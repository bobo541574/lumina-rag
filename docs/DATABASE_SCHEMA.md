# Database Schema (PostgreSQL + pgvector)

## Entity Relationship Overview
```
users ──< chat_sessions
users ──< documents
chat_sessions ──< chat_messages
documents ──< document_chunks ──< vector_embeddings
```

## Tables

### chat_sessions
Stores chat conversation sessions.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | ULID | PK | Primary key |
| user_id | ULID | NULLABLE | Optional user ownership (no FK constraint, handled in code) |
| title | VARCHAR(255) | NULLABLE | Auto-generated from first question |
| status | VARCHAR(20) | DEFAULT 'active' | active, archived, deleted |
| message_count | INTEGER | DEFAULT 0 | Cached count for performance |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |
| updated_at | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |
| deleted_at | TIMESTAMPTZ | NULLABLE | Soft delete |

**Indexes**:
- `idx_chat_sessions_user_id` on user_id
- `idx_chat_sessions_status` on status  
- `idx_chat_sessions_created_at` on created_at (for archival queries)

### chat_messages
Individual messages within a chat session.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | ULID | PK | Primary key |
| session_id | ULID | NOT NULL | Parent session (no FK constraint, handled in code) |
| role | VARCHAR(20) | NOT NULL, CHECK (role IN ('user', 'assistant')) | Message role |
| content | TEXT | NOT NULL | Message text content |
| sources | JSONB | NULLABLE | Array of source citations |
| embedding_id | ULID | NULLABLE | Reference to question embedding (no FK constraint, handled in code) |
| token_count | INTEGER | NULLABLE | Token usage (for analytics) |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |

**Indexes**:
- `idx_chat_messages_session_id` on chat_session_id
- `idx_chat_messages_created_at` on created_at
- `idx_chat_messages_sources` GIN index on sources JSONB column

### documents
Uploaded document metadata.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | ULID | PK | Primary key |
| user_id | ULID | NULLABLE | Optional user ownership (no FK constraint, handled in code) |
| title | VARCHAR(255) | NOT NULL | Document title/filename |
| file_path | VARCHAR(1000) | NOT NULL | Storage path |
| mime_type | VARCHAR(100) | NOT NULL | e.g., application/pdf |
| file_size | BIGINT | NOT NULL | Size in bytes |
| file_hash | VARCHAR(64) | NOT NULL | SHA-256 hash for dedup |
| page_count | INTEGER | NULLABLE | Pages (for PDFs) |
| status | VARCHAR(20) | NOT NULL, DEFAULT 'pending' | pending, processing, completed, failed |
| error_message | TEXT | NULLABLE | Error details if failed |
| chunks_count | INTEGER | DEFAULT 0 | Total chunks created |
| processed_at | TIMESTAMPTZ | NULLABLE | When processing completed |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |
| updated_at | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |
| deleted_at | TIMESTAMPTZ | NULLABLE | Soft delete |

**Indexes**:
- `uq_documents_file_hash` UNIQUE on file_hash (deduplication)
- `idx_documents_user_id` on user_id
- `idx_documents_status` on status
- `idx_documents_created_at` on created_at

### document_chunks
Text chunks extracted from documents.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | ULID | PK | Primary key |
| document_id | ULID | NOT NULL | Parent document (no FK constraint, handled in code) |
| content | TEXT | NOT NULL | Chunk text content |
| chunk_index | INTEGER | NOT NULL | Order within document |
| char_start | INTEGER | NOT NULL | Start position in source |
| char_end | INTEGER | NOT NULL | End position in source |
| page_number | INTEGER | NULLABLE | Page number (PDF) |
| token_count | INTEGER | NULLABLE | Estimated token count |
| vector_id | ULID | NULLABLE | Reference to vector_embeddings (no FK constraint, handled in code) |
| metadata | JSONB | NULLABLE | Additional metadata |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |

**Indexes**:
- `idx_document_chunks_document_id` on document_id
- `uq_document_chunk_order` UNIQUE on (document_id, chunk_index)
- `idx_document_chunks_vector_id` on vector_id

### vector_embeddings
Vector representations stored via pgvector.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | ULID | PK | Primary key |
| chunk_id | ULID | NOT NULL | Related chunk (no FK constraint, handled in code) |
| embedding | vector(1536) | NOT NULL | OpenAI embedding vector |
| model_name | VARCHAR(100) | NOT NULL | Embedding model identifier |
| content_hash | VARCHAR(32) | NOT NULL | MD5 of chunk text (cache key) |
| created_at | TIMESTAMPTZ | NOT NULL, DEFAULT NOW() | |

**Indexes**:
- `idx_vector_embeddings_chunk_id` on chunk_id
- `idx_vector_embeddings_content_hash` on content_hash
- `idx_vector_embeddings_ivfflat` IVFFlat index on embedding vector_l2_ops

**IVFFlat Index Configuration**:
```sql
-- Create after significant data insertion (> 1000 rows)
CREATE INDEX idx_vector_embeddings_ivfflat 
ON vector_embeddings 
USING ivfflat (embedding vector_cosine_ops) 
WITH (lists = 100);
```

### jobs (Laravel Queue)
Standard Laravel queue jobs table for document processing. Jobs are processed by `php artisan queue:work`.

### failed_jobs
Standard Laravel failed jobs table for debugging.

## Migration Order
1. `users` (Laravel default — includes `api_token` column)
2. `personal_access_tokens` (Laravel default — not used, exists by default)
3. `chat_sessions`
4. `chat_messages` (code-level relationship to chat_sessions)
5. `documents`
6. `document_chunks` (code-level relationship to documents)
7. `vector_embeddings` (code-level relationship to document_chunks — includes `embedding` + IVFFlat index if pgvector available)
8. `jobs` (Laravel default)
9. `failed_jobs` (Laravel default)

## Seeders

| Seeder | Data |
|--------|------|
| `UserModuleSeeder` | 2 users (admin + test) with API tokens |
| `ChatModuleSeeder` | 2 sessions with 2 messages each |
| `DocumentModuleSeeder` | 1 document with 3 chunks |
| `VectorStoreModuleSeeder` | Embeddings for chunks (skips if pgvector unavailable) |

Run via: `php artisan db:seed`

## pgvector Configuration Notes

### Vector Dimension
1536 dimensions matching OpenAI text-embedding-ada-002.

### Similarity Operators
- Cosine distance: `<=>` (used for similarity search)
- L2 distance: `<->` (alternative)
- Inner product: `<#>` (alternative)

### Recommended Query Pattern
```sql
SELECT 
    dc.id as chunk_id,
    dc.content,
    d.title as document_title,
    1 - (ve.embedding <=> query_vector) as similarity
FROM vector_embeddings ve
JOIN document_chunks dc ON dc.id = ve.chunk_id
JOIN documents d ON d.id = dc.document_id
WHERE d.deleted_at IS NULL
ORDER BY ve.embedding <=> query_vector
LIMIT 5;
```

### Performance Considerations
- IVFFlat index requires ~1000+ vectors before creating
- REINDEX after major data changes
- ANALYZE after bulk inserts
- Consider partitioning by document_id for large-scale deployments

## Foreign Key Constraints

**Note:** Foreign key constraints are not enforced at the database level. All relationships (e.g., between chat_sessions, chat_messages, documents, document_chunks, vector_embeddings) are managed at the application code level for flexibility and scalability.
