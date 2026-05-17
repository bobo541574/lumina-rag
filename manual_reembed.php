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

$documentId = '01KRRJA4VD73S5M2S92J54NNC8';
$document = Document::findOrFail($documentId);

echo 'Re-embedding document: '.$document->title.' (Model: '.$document->embedding_model.")\n";

$vectorStore = app(VectorStoreInterface::class);
$defaultEmbedder = app(EmbeddingServiceInterface::class);
$providerFactory = app(ProviderFactory::class);
$cache = app(CacheRepository::class);

// Manual job logic
$vectorStore->deleteByDocumentId($documentId);

$chunks = DocumentChunk::where('document_id', $documentId)
    ->orderBy('chunk_index')
    ->get();

echo 'Chunks to process: '.$chunks->count()."\n";

// Use ResolvesEmbedder trait logic (simulated)
$model = $document->embeddingModel; // BelongsTo AiModel
if (! $model) {
    echo "No AiModel found for this document, searching by model name...\n";
    $model = AiModel::where('model', $document->embedding_model)->first();
}

if ($model) {
    echo 'Found AiModel: '.$model->id.' | '.$model->model.' | Provider: '.$model->provider."\n";
    $provider = $providerFactory->createEmbeddingProvider($model);
    $embedder = new EmbeddingService(
        $provider,
        $cache,
        (int) ($model->settings['cache_ttl'] ?? 86400)
    );
} else {
    echo "Falling back to default embedder.\n";
    $embedder = $defaultEmbedder;
}

$modelName = $document->embedding_model ?? config('rag.embedding.model');
$texts = $chunks->pluck('content')->toArray();
$batchSize = 100;
$batches = array_chunk($texts, $batchSize);

foreach ($batches as $batchIndex => $batch) {
    echo 'Processing batch '.($batchIndex + 1).' ('.count($batch)." texts)...\n";
    try {
        $vectors = $embedder->embedBatch($batch, $modelName);
        echo 'Received '.count($vectors)." vectors.\n";

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

        echo "Upserting to vector store...\n";
        $vectorStore->upsert(
            vectors: $batchVectors,
            metadata: $batchMetadata,
            chunkId: $batchChunkIds,
            namespace: "document_{$document->id}",
        );
        echo "Upsert done.\n";
    } catch (Throwable $e) {
        echo 'ERROR during batch '.($batchIndex + 1).': '.$e->getMessage()."\n";
        echo $e->getTraceAsString()."\n";
    }
}

echo "Verification:\n";
$veCount = DB::table('vector_embeddings')->whereIn('chunk_id', $chunks->pluck('id'))->count();
echo "Vector count in DB now: $veCount\n";
