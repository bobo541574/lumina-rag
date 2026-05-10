<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\DocumentModule\Models\DocumentChunk;
use Modules\VectorStoreModule\Models\VectorEmbedding;

class VectorStoreModuleSeeder extends Seeder
{
    private int $dimensions;

    public function __construct()
    {
        $this->dimensions = (int) config('rag.embedding.dimensions', 1536);
    }

    public function run(): void
    {
        if (VectorEmbedding::count() > 0) {
            return;
        }

        if (! Schema::hasColumn('vector_embeddings', 'embedding')) {
            return;
        }

        $chunks = DocumentChunk::all();

        if ($chunks->isEmpty()) {
            return;
        }

        $now = now();
        $records = [];
        $batchSize = 50;

        foreach ($chunks as $chunk) {
            $embedding = $this->generateEmbedding($chunk->content);

            $records[] = [
                'id' => (string) Str::ulid(),
                'chunk_id' => $chunk->id,
                'embedding' => $embedding,
                'model_name' => config('rag.embedding.model'),
                'content_hash' => md5($chunk->content),
                'created_at' => $now,
            ];

            if (count($records) >= $batchSize) {
                DB::table('vector_embeddings')->insert($records);
                $records = [];
            }
        }

        if ($records !== []) {
            DB::table('vector_embeddings')->insert($records);
        }
    }

    private function generateEmbedding(string $text): string
    {
        mt_srand(crc32($text));

        $components = [];
        for ($i = 0; $i < $this->dimensions; $i++) {
            $components[] = mt_rand(-10000, 10000) / 10000;
        }

        $magnitude = sqrt(array_sum(array_map(fn ($v) => $v * $v, $components)));

        if ($magnitude > 0) {
            $components = array_map(fn ($v) => $v / $magnitude, $components);
        }

        mt_srand();

        return '['.implode(',', $components).']';
    }
}
