<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Services;

use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

class VectorStoreService implements VectorStoreInterface
{
    private VectorStoreInterface $driver;

    public function __construct(VectorStoreInterface $driver)
    {
        $this->driver = $driver;
    }

    public function upsert(array $vectors, array $metadata, string|array $chunkId, string $namespace): array
    {
        return $this->driver->upsert($vectors, $metadata, $chunkId, $namespace);
    }

    public function search(array $queryVector, int $topK = 5, array $filters = []): array
    {
        return $this->driver->search($queryVector, $topK, $filters);
    }

    public function delete(array $ids): void
    {
        $this->driver->delete($ids);
    }

    public function deleteByDocumentId(string $documentId): void
    {
        $this->driver->deleteByDocumentId($documentId);
    }

    public function getStats(): array
    {
        return $this->driver->getStats();
    }
}
