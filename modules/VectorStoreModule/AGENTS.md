# Vector Store Module

## Overview
Manages storage and similarity search of vector embeddings. Provides a driver-based abstraction supporting PostgreSQL pgvector (primary) and Pinecone (cloud alternative). Handles metadata filtering and batch operations.

## Responsibility Boundaries

### This Module OWNS:
- Vector storage and persistence
- Similarity search operations
- Metadata filtering during search
- Batch upsert and delete operations
- Vector index management

### This Module DOES NOT OWN:
- Embedding generation (→ EmbeddingModule)
- Document or chunk management (→ DocumentModule)
- Search result interpretation (→ ChatModule)

## Service Contract

### VectorStoreInterface
```php
interface VectorStoreInterface
{
    public function upsert(array $vectors, array $metadata, string $namespace): array;
    public function search(array $queryVector, int $topK, array $filters): array;
    public function delete(array $ids): void;
    public function deleteByFilter(array $filters): void;
    public function getStats(): array;
}
```

### VectorStoreService
Orchestrates driver selection and provides unified API.

**Method: search(array $queryVector, int $topK = 5, array $filters = []): array**

**Return Format**:
```php
[
    [
        'id' => 'chunk_123',
        'score' => 0.92,
        'metadata' => [
            'document_id' => 1,
            'document_title' => 'Q3 Report.pdf',
            'chunk_index' => 12,
            'page_number' => 5,
        ],
        'content' => 'Q3 revenue reached $45.2 million...'
    ],
    // ... more results sorted by score desc
]
```

## PostgreSQL pgvector Driver

### Schema
```sql
CREATE TABLE vector_embeddings (
    id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    chunk_id BIGINT NOT NULL REFERENCES document_chunks(id) ON DELETE CASCADE,
    embedding vector(1536) NOT NULL,
    model_name VARCHAR(100) NOT NULL,
    content_hash VARCHAR(32) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- IVFFlat index for similarity search
CREATE INDEX idx_vector_embeddings_ivfflat 
ON vector_embeddings 
USING ivfflat (embedding vector_cosine_ops) 
WITH (lists = 100);
```

### Similarity Search Query
```sql
SELECT
    dc.id,
    dc.content,
    d.title as document_title,
    1 - (ve.embedding <=> $1) as similarity_score
FROM vector_embeddings ve
JOIN document_chunks dc ON dc.id = ve.chunk_id
JOIN documents d ON d.id = dc.document_id
WHERE d.deleted_at IS NULL
  AND 1 - (ve.embedding <=> $1) > 0.65  -- Threshold
ORDER BY ve.embedding <=> $1
LIMIT 5;
```

### Performance Characteristics
- 100K vectors: Sub-second search
- 1M vectors: < 2 seconds with IVFFlat
- 10M+ vectors: Consider Pinecone

## Pinecone Driver (Alternative)

### Configuration
```php
'pinecone' => [
    'api_key' => env('PINECONE_API_KEY'),
    'environment' => env('PINECONE_ENVIRONMENT'),
    'index' => env('PINECONE_INDEX', 'rag-documents'),
]
```

### When to Use Pinecone
- Vector count exceeds 10M
- Need sub-100ms search at any scale
- Managed service preferred over PostgreSQL tuning
- Hybrid search (dense + sparse vectors) needed

## Search Filters

### Supported Metadata Filters
```php
// Filter by document IDs
['document_ids' => [1, 2, 3]]

// Filter by date range
['date_from' => '2024-01-01', 'date_to' => '2024-12-31']

// Filter by minimum chunk index
['chunk_index_min' => 10]

// Filter by JSONB metadata fields (document_chunks.metadata)
['meta' => ['project' => 'Orion', 'user_name' => 'John']]

// Combined filters
[
    'document_ids' => [1, 2],
    'date_from' => '2024-06-01',
    'meta' => ['project' => 'Orion'],
]
```

### Filter Implementation
- pgvector: WHERE clauses on JOINed metadata + `jsonb @>` operator for `meta` filter
- SQLite: `json_extract()` for `meta` filter
- Pinecone: Not implemented

## Batch Operations

### Upsert
- Accept: Array of vectors + metadata
- Batch size: 100 per transaction (pgvector), 100 per request (Pinecone)
- ID generation: `chunk_{document_id}_{chunk_index}`
- Metadata serialization: JSON

### Delete
- By vector IDs: Individual or batch removal
- By document ID: Delete all chunks belonging to a document
- Cascade: Deleting document → deleting chunks → deleting vectors

## Index Management (pgvector)

### IVFFlat Index
- **When to create**: After inserting > 1000 vectors
- **lists parameter**: sqrt(row_count) for optimal performance
- **Reindexing**: After major data changes (> 20% new/updated)

### Maintenance Queries
```sql
-- Check index usage
EXPLAIN ANALYZE SELECT ... (search query);

-- Reindex after bulk insert
REINDEX INDEX idx_vector_embeddings_ivfflat;

-- Update statistics
ANALYZE vector_embeddings;
```

## Data Integrity

### Orphaned Vectors
- Vector without corresponding chunk → Delete cascade handles this
- Chunk without vector → Allowed (embedding failed), marked for retry

### Consistency Checks
- Document deletion → All vectors removed
- Document retry → Old vectors deleted, new ones inserted
- Transaction wrapping for batch operations

## Seeder
`VectorStoreModuleSeeder` — creates vector embeddings for existing document chunks. Skips if pgvector extension is unavailable or if embeddings already exist. Idempotent. Called automatically by `DatabaseSeeder`.

### Retrieval Performance

---

## Code Documentation Standards

All classes and methods must include comprehensive PHPDoc blocks.

### Requirements:
1.  **Title & Detailed Description**: Clear explanation of purpose.
2.  **Parameters**: `@param {type} $name Description. Example: {example}`.
3.  **Return Type**: `@return {type} Description. Example: {example}`.
4.  **Exceptions**: `@throws {ExceptionClass} Description of when it's thrown. Example: {example}`.

---

## Testing Strategy

### Unit Tests
- search() with mock vector → Returns results sorted by score
- upsert() with vector array → Stores successfully
- delete() by document ID → All related vectors removed
- filter application → Only matching metadata returned

### Integration Tests
- Real pgvector search → Results returned in < 500ms
- Batch upsert of 100 vectors → All retrievable
- Delete cascade → Document deletion removes all vectors