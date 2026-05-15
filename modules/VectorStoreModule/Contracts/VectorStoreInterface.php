<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Contracts;

interface VectorStoreInterface
{
    public function upsert(array $vectors, array $metadata, string|array $chunkId, string $namespace): array;

    public function search(array $queryVector, int $topK = 5, array $filters = []): array;

    public function searchHybrid(string $queryText, array $queryVector, int $topK = 5, array $filters = []): array;

    public function delete(array $ids): void;

    public function deleteByDocumentId(string $documentId): void;

    public function getStats(): array;
}
