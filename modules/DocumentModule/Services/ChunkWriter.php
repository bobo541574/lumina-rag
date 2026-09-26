<?php

declare(strict_types=1);

namespace Modules\DocumentModule\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds and bulk-inserts document_chunks rows.
 *
 * Every chunk gets the same metadata header prepended to its content (so the
 * embedding and FTS see who/what/when) and the same JSON metadata keys, which
 * PgvectorDriver's meta filters rely on. Used by document processing and by
 * the demo seeder so both produce identical rows.
 */
class ChunkWriter
{
    /**
     * Build insertable rows for one document's chunks.
     *
     * @param  string  $documentId  Owning document ULID. Example: "01J..."
     * @param  array<array{content: string, char_start: int, char_end: int, page_number?: int|null, section?: string|null}>  $rawChunks  Chunker output
     * @param  array{user_id: string|null, user_name: string, project: string, report_date: string, document_title: string}  $docMeta  Document-level metadata
     * @param  \DateTimeInterface|string  $createdAt  Row timestamp. Example: now()
     * @return array<array<string, mixed>> Rows ready for insert()
     */
    public function buildRecords(string $documentId, array $rawChunks, array $docMeta, \DateTimeInterface|string $createdAt): array
    {
        $header = "Report by: {$docMeta['user_name']}\nProject: {$docMeta['project']}\nDate: {$docMeta['report_date']}\n\n";
        $records = [];

        foreach (array_values($rawChunks) as $i => $chunk) {
            $content = $header.$chunk['content'];
            $metadata = array_filter([
                ...$docMeta,
                'chunk_index' => $i,
                'section' => $chunk['section'] ?? null,
                'page_number' => $chunk['page_number'] ?? null,
            ], fn ($v): bool => $v !== null);

            $records[] = [
                'id' => (string) Str::ulid(),
                'document_id' => $documentId,
                'content' => $content,
                'chunk_index' => $i,
                'char_start' => $chunk['char_start'],
                'char_end' => $chunk['char_end'],
                'token_count' => (int) ceil(mb_strlen($content) / 4),
                'page_number' => $chunk['page_number'] ?? null,
                'metadata' => json_encode($metadata),
                'created_at' => $createdAt,
            ];
        }

        return $records;
    }

    /**
     * Insert chunk rows in batches and fill their tsvector (PostgreSQL).
     *
     * Callers wrap this in a transaction when it must be atomic with other writes.
     *
     * @param  array<array<string, mixed>>  $records  Rows from buildRecords(). Example: $writer->buildRecords(...)
     */
    public function insert(array $records): void
    {
        foreach (array_chunk($records, 500) as $batch) {
            DB::table('document_chunks')->insert($batch);

            if (DB::getDriverName() === 'pgsql') {
                $ids = array_column($batch, 'id');
                DB::statement(
                    "UPDATE document_chunks SET tsv_content = to_tsvector('english', content) WHERE id IN (".implode(', ', array_fill(0, count($ids), '?')).')',
                    $ids,
                );
            }
        }
    }
}
