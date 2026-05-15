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

    private string $dimTable;

    private const DIM_TABLES = [384, 768, 1024, 1536, 3072];

    public function __construct()
    {
        $this->dimensions = (int) config('rag.embedding.dimensions', 1536);
        $this->dimTable = $this->resolveDimTable($this->dimensions);
    }

    public function run(): void
    {
        if (VectorEmbedding::count() > 0) {
            return;
        }

        if (! Schema::hasTable($this->dimTable)) {
            if (Schema::hasColumn('vector_embeddings', 'embedding')) {
                // Fallback: old single-table structure (SQLite / no pgvector)
                $this->seedLegacy();
            }

            return;
        }

        if (! Schema::hasColumn($this->dimTable, 'embedding')) {
            return;
        }

        $chunks = DocumentChunk::all();

        if ($chunks->isEmpty()) {
            return;
        }

        $now = now();
        $modelName = (string) config('rag.embedding.model', 'text-embedding-3-small');

        foreach ($chunks as $chunk) {
            $id = (string) Str::ulid();
            $vectorLiteral = $this->generateEmbedding();

            DB::statement(
                'INSERT INTO vector_embeddings (id, chunk_id, dimensions, model_name, content_hash, created_at) VALUES (?, ?, ?, ?, ?, ?::timestamptz)',
                [$id, $chunk->id, $this->dimensions, $modelName, md5($chunk->content), $now]
            );

            DB::statement(
                "INSERT INTO {$this->dimTable} (id, chunk_id, embedding, model_name, content_hash, created_at) VALUES (?, ?, ?::vector, ?, ?, ?::timestamptz)",
                [$id, $chunk->id, $vectorLiteral, $modelName, md5($chunk->content), $now]
            );
        }
    }

    private function seedLegacy(): void
    {
        $chunks = DocumentChunk::all();
        if ($chunks->isEmpty()) {
            return;
        }

        $now = now();
        $records = [];

        foreach ($chunks as $chunk) {
            $embedding = $this->generateEmbedding();
            $records[] = [
                'id' => (string) Str::ulid(),
                'chunk_id' => $chunk->id,
                'embedding' => $embedding,
                'dimensions' => $this->dimensions,
                'model_name' => config('rag.embedding.model'),
                'content_hash' => md5($chunk->content),
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($records, 50) as $batch) {
            DB::table('vector_embeddings')->insert($batch);
        }
    }

    private function generateEmbedding(): string
    {
        mt_srand(crc32(microtime()));

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

    private function resolveDimTable(int $dimensions): string
    {
        $nearest = self::DIM_TABLES[0];
        foreach (self::DIM_TABLES as $dim) {
            if (abs($dim - $dimensions) < abs($nearest - $dimensions)) {
                $nearest = $dim;
            }
        }

        return "ve_{$nearest}";
    }
}
