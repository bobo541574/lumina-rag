<?php

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Modules\DocumentModule\Models\Document;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\EmbeddingModule\Contracts\EmbeddingServiceInterface;
use Modules\EmbeddingModule\Services\EmbeddingService;
use Modules\EmbeddingModule\Services\ProviderFactory;
use Modules\SettingsModule\Models\AiModel;
use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

echo "Starting bulk re-embedding for documents with missing vectors...\n";

// Prioritize April 2026 documents for Project Orion and Aung Zeya
$priorityDocs = Document::where('status', 'completed')
    ->where('user_id', '01krrcwn9hvp4j6m4k84r236hh')
    ->where('project', 'Project Orion')
    ->whereBetween('report_date', ['2026-04-01', '2026-04-30'])
    ->get();

$otherDocs = Document::where('status', 'completed')->limit(1000)->get();

$docs = $priorityDocs->merge($otherDocs);
$count = 0;
$total = $docs->count();

$vectorStore = app(VectorStoreInterface::class);
$defaultEmbedder = app(EmbeddingServiceInterface::class);
$providerFactory = app(ProviderFactory::class);
$cache = app(CacheRepository::class);

foreach ($docs as $index => $document) {
    $chunks = DocumentChunk::where('document_id', $document->id)->get();
    if ($chunks->isEmpty()) {
        continue;
    }

    $veCount = DB::table('vector_embeddings')->whereIn('chunk_id', $chunks->pluck('id'))->count();

    if ($veCount === 0) {
        echo ' ['.($index + 1)."/$total] Re-embedding: ".$document->title.' ('.$document->id.")\n";

        try {
            // Manual job logic (optimized)
            $vectorStore->deleteByDocumentId($document->id);

            // Resolve embedder
            $model = $document->embeddingModel;
            if (! $model) {
                $model = AiModel::where('model', $document->embedding_model)->first();
            }

            if ($model) {
                $provider = $providerFactory->createEmbeddingProvider($model);
                $embedder = new EmbeddingService(
                    $provider,
                    $cache,
                    (int) ($model->settings['cache_ttl'] ?? 86400)
                );
            } else {
                $embedder = $defaultEmbedder;
            }

            $modelName = $document->embedding_model ?? config('rag.embedding.model');
            $texts = $chunks->pluck('content')->toArray();
            $batchSize = 100;
            $batches = array_chunk($texts, $batchSize);

            foreach ($batches as $batchIndex => $batch) {
                $vectors = $embedder->embedBatch($batch, $modelName);

                $batchVectors = [];
                $batchChunkIds = [];
                $batchMetadata = [];

                foreach ($vectors as $j => $vector) {
                    $chunk = $chunks[$batchIndex * $batchSize + $j];
                    $batchVectors[] = $vector;
                    $batchChunkIds[] = $chunk->id;
                    $batchMetadata[] = [
                        'model_name' => $modelName,
                        'content_hash' => md5($chunk->content),
                    ];
                }

                $vectorStore->upsert(
                    vectors: $batchVectors,
                    metadata: $batchMetadata,
                    chunkId: $batchChunkIds,
                    namespace: "document_{$document->id}",
                );
            }
            $count++;
            echo '   Success. Chunks: '.$chunks->count()."\n";
        } catch (Throwable $e) {
            echo '   FAILED: '.$e->getMessage()."\n";
        }

        // Stop if we've done a lot in one go to avoid timeouts in this environment,
        // but since I'm running in terminal it should be fine.
        // Let's limit to 200 documents per run for safety if there are many.
        if ($count >= 200) {
            echo "Reached 200 documents limit for this run. Please run again to continue.\n";
            break;
        }
    }
}

echo "Finished. Re-embedded $count documents.\n";
