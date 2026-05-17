<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Services;

use Modules\VectorStoreModule\Contracts\VectorStoreInterface;

/**
 * Vector Store Service
 *
 * Decorator/facade around a concrete VectorStoreInterface driver. All calls
 * are delegated to the injected driver, allowing different backends
 * (pgvector, Pinecone, etc.) to be swapped transparently.
 *
 * This service is the single entry point used by the ChatModule's RAG
 * pipeline and the DocumentModule for all vector storage operations.
 *
 * @param  VectorStoreInterface  $driver  The underlying vector store driver. Example: app(PgvectorDriver::class)
 *
 * @throws \RuntimeException Propagated from the driver on failures
 */
class VectorStoreService implements VectorStoreInterface
{
    /** @var VectorStoreInterface The underlying vector store driver implementation */
    private VectorStoreInterface $driver;

    /**
     * @param  VectorStoreInterface  $driver  Concrete vector store driver. Example: new PgvectorDriver($db)
     */
    public function __construct(VectorStoreInterface $driver)
    {
        $this->driver = $driver;
    }

    /**
     * Delegate upsert to the underlying driver.
     *
     * @param  array  $vectors  Array of float vectors. Example: [[0.012, -0.034], [0.023, 0.045]]
     * @param  array  $metadata  Array or single metadata map. Example: [["document_id" => "01J..."]]
     * @param  string|array  $chunkId  Single chunk ID or array matching vectors. Example: "01J..."
     * @param  string  $namespace  Namespace for the vectors. Example: "default"
     * @return array Array of created vector ULIDs. Example: ["01JAR...", "01JAS..."]
     *
     * @throws \RuntimeException When the driver upsert fails
     */
    public function upsert(array $vectors, array $metadata, string|array $chunkId, string $namespace): array
    {
        return $this->driver->upsert($vectors, $metadata, $chunkId, $namespace);
    }

    /**
     * Delegate pure vector search to the underlying driver.
     *
     * @param  array  $queryVector  Query vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  int  $topK  Number of results. Example: 5
     * @param  array  $filters  Metadata filters. Example: ["project" => "Orion"]
     * @return array Result objects with similarity_score. Example: [["chunk_id" => "01J...", "similarity_score" => 0.92]]
     *
     * @throws \RuntimeException When the driver search fails
     */
    public function search(array $queryVector, int $topK = 5, array $filters = []): array
    {
        return $this->driver->search($queryVector, $topK, $filters);
    }

    /**
     * Delegate hybrid search to the underlying driver.
     *
     * @param  string  $queryText  Raw query text for FTS. Example: "Project Orion"
     * @param  array  $queryVector  Query vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  int  $topK  Number of results. Example: 5
     * @param  array  $filters  Metadata filters. Example: ["project" => "Orion"]
     * @return array Fused result objects with similarity_score. Example: [["chunk_id" => "01J...", "similarity_score" => 0.87]]
     *
     * @throws \RuntimeException When the driver hybrid search fails
     */
    public function searchHybrid(string $queryText, array $queryVector, int $topK = 5, array $filters = []): array
    {
        return $this->driver->searchHybrid($queryText, $queryVector, $topK, $filters);
    }

    /**
     * Delegate vector deletion by IDs to the underlying driver.
     *
     * @param  array  $ids  Vector ULIDs to delete. Example: ["01JAR...", "01JAS..."]
     *
     * @throws \RuntimeException When the driver delete fails
     */
    public function delete(array $ids): void
    {
        $this->driver->delete($ids);
    }

    /**
     * Delegate document-scoped deletion to the underlying driver.
     *
     * @param  string  $documentId  Document ULID. Example: "01JAR..."
     *
     * @throws \RuntimeException When the driver delete fails
     */
    public function deleteByDocumentId(string $documentId): void
    {
        $this->driver->deleteByDocumentId($documentId);
    }

    /**
     * Delegate stats retrieval to the underlying driver.
     *
     * @return array Stats including total_vectors, by_dimensions, by_model. Example: ["total_vectors" => 1500, "by_dimensions" => [768 => 500]]
     */
    public function getStats(): array
    {
        return $this->driver->getStats();
    }
}
