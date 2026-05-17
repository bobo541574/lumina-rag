<?php

declare(strict_types=1);

namespace Modules\VectorStoreModule\Contracts;

/**
 * Vector Store Interface
 *
 * Defines the contract for vector storage and similarity search. Implementations
 * wrap either pgvector (PostgreSQL) or other vector database drivers.
 *
 * Supports vector-only search and hybrid search (vector + full-text) with
 * metadata filtering. All implementations must handle batch upsert, bulk
 * delete (by ID or by document), and return statistics.
 *
 * @method array upsert(array $vectors, array $metadata, string|array $chunkId, string $namespace) Store vectors. Example: $store->upsert([[0.1, ...]], [["doc_id" => "01J..."]], "chunk_01J...", "default")
 * @method array search(array $queryVector, int $topK, array $filters) Vector similarity search. Example: $store->search([0.1, ...], 5, ["project" => "Orion"])
 * @method array searchHybrid(string $queryText, array $queryVector, int $topK, array $filters) Hybrid vector+FTS search. Example: $store->searchHybrid("project", [0.1, ...], 5)
 * @method void delete(array $ids) Delete by vector IDs. Example: $store->delete(["id1", "id2"])
 * @method void deleteByDocumentId(string $documentId) Delete all vectors for a document. Example: $store->deleteByDocumentId("01J...")
 *
 * @throws \RuntimeException On database errors or unsupported operations
 */
interface VectorStoreInterface
{
    /**
     * Upsert vectors with associated metadata.
     *
     * @param  array  $vectors  Array of float vectors. Example: [[0.012, -0.034], [0.023, 0.045]]
     * @param  array  $metadata  Array or single metadata map. Example: [["document_id" => "01J...", "model_name" => "text-embedding-ada-002"]]
     * @param  string|array  $chunkId  Single chunk ID or array matching vectors. Example: "01J..." or ["01J...", "01J..."]
     * @param  string  $namespace  Namespace/scope for the vectors. Example: "default"
     * @return array Array of newly created vector ULIDs. Example: ["01JAR...", "01JAS..."]
     *
     * @throws \RuntimeException When the database write fails
     * @throws \InvalidArgumentException When vector dimensions don't match
     */
    public function upsert(array $vectors, array $metadata, string|array $chunkId, string $namespace): array;

    /**
     * Perform a pure vector similarity search.
     *
     * @param  array  $queryVector  Query vector. Example: [0.012, -0.034, ..., 0.098]
     * @param  int  $topK  Number of results to return. Example: 5
     * @param  array  $filters  Metadata filters. Example: ["project" => "Orion", "user_ids" => ["01J..."]]
     * @return array Array of result objects with similarity_score, content, metadata. Example: [["chunk_id" => "01J...", "similarity_score" => 0.92, ...]]
     *
     * @throws \RuntimeException When the database query fails
     */
    public function search(array $queryVector, int $topK = 5, array $filters = []): array;

    /**
     * Perform a hybrid search combining vector similarity and full-text search.
     *
     * @param  string  $queryText  Raw query text for FTS. Example: "What is Project Orion?"
     * @param  array  $queryVector  Query vector for similarity. Example: [0.012, -0.034, ..., 0.098]
     * @param  int  $topK  Number of results to return. Example: 5
     * @param  array  $filters  Metadata filters. Example: ["project" => "Orion"]
     * @return array Array of fused result objects with similarity_score. Example: [["chunk_id" => "01J...", "similarity_score" => 0.87, ...]]
     *
     * @throws \RuntimeException When the database query fails
     */
    public function searchHybrid(string $queryText, array $queryVector, int $topK = 5, array $filters = []): array;

    /**
     * Delete vectors by their IDs.
     *
     * @param  array  $ids  Array of vector ULIDs to delete. Example: ["01JAR...", "01JAS..."]
     *
     * @throws \RuntimeException When the database delete fails
     */
    public function delete(array $ids): void;

    /**
     * Delete all vectors belonging to a document.
     *
     * @param  string  $documentId  Document ULID. Example: "01JAR..."
     *
     * @throws \RuntimeException When the database delete fails
     */
    public function deleteByDocumentId(string $documentId): void;

    /**
     * Get storage statistics.
     *
     * @return array Stats including total_vectors, by_dimensions, by_model. Example: ["total_vectors" => 1500, "by_dimensions" => [768 => 500, 1536 => 1000]]
     */
    public function getStats(): array;
}
