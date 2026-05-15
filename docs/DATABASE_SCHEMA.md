# Database Schema

PostgreSQL 16 + pgvector. All primary keys are **ULIDs** (26-char Crockford base32). **No DB-level foreign-key constraints** — referential integrity is enforced in service code (intentional design choice; see [PROJECT_RULES.md](../PROJECT_RULES.md)). All timestamp columns use `TIMESTAMPTZ`.

> **Source of truth**: the migrations under `modules/{Module}/database/migrations/` and `database/migrations/`. This document is regenerated when those change.

---

## Entity overview

```
users ──< chat_sessions ──< chat_messages
users ──< documents     ──< document_chunks ──< vector_embeddings (metadata)
                                              ╲
                                               ╲── ve_384 / ve_768 / ve_1024 / ve_1536 / ve_3072
                                                   (one row per chunk, in the matching-dimension table)

settings   (standalone — runtime overrides for config/rag.*)
ai_models  (standalone — registry of embedding + LLM endpoints)
```

Auxiliary Laravel tables: `users`, `password_reset_tokens`, `sessions` (HTTP session storage), `cache`, `cache_locks`, `jobs`, `failed_jobs`.

---

## Tables

### `users`

`database/migrations/0001_01_01_000000_create_users_table.php`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `name` | string | not null | Display name |
| `email` | string | unique, not null | Login identifier |
| `email_verified_at` | timestamp | nullable | Laravel default; not currently exercised |
| `password` | string | not null | bcrypt hash |
| `remember_token` | string | nullable | Laravel default |
| `api_token` | string(80) | unique, nullable | 80-char hex token for API auth (`bin2hex(random_bytes(40))`) |
| `created_at`, `updated_at` | timestamp | not null | |

---

### `chat_sessions`

`modules/ChatModule/database/migrations/2026_01_01_000001_create_chat_sessions_table.php`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `title` | varchar(255) | default `'New Chat'` | Auto-set from assistant's first reply (first 50 chars) |
| `user_id` | ULID | nullable | Owning user (no FK; enforced in service) |
| `is_archived` | boolean | default `false` | Soft archive flag — not the same as soft delete |
| `message_count` | integer | default `0` | Cached count for performance |
| `last_activity_at` | timestamptz | not null | Updated on every new message |
| `created_at`, `updated_at` | timestamptz | default `NOW()` | |
| `deleted_at` | timestamptz | nullable | Soft delete |

**Indexes**:
- `idx_chat_sessions_activity` on `last_activity_at`
- `idx_chat_sessions_archived` on `is_archived`

---

### `chat_messages`

`modules/ChatModule/database/migrations/2026_01_01_000002_create_chat_messages_table.php`

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `session_id` | ULID | not null | → `chat_sessions.id` (no FK) |
| `role` | varchar(20) | not null | `user` or `assistant` |
| `content` | longtext | not null | Message body (assistant body may be partial if SSE dropped) |
| `token_count` | integer | nullable | Tokens in the response (assistant only) |
| `sources` | jsonb | nullable | Array of `{document_id, document_title, chunk_index, page_number, similarity_score, excerpt}` (assistant only) |
| `created_at`, `updated_at` | timestamptz | default `NOW()` | |
| `deleted_at` | timestamptz | nullable | Soft delete |

**Indexes**:
- `idx_chat_messages_session_id` on `session_id`
- `idx_chat_messages_session_created` on `(session_id, created_at)` — for thread retrieval

---

### `documents`

`modules/DocumentModule/database/migrations/2026_01_01_000001_create_documents_table.php` + later add-column migrations.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `user_id` | ULID | nullable | Owning user (no FK; enforced in service) |
| `title` | varchar(255) | not null | Editable; defaults to filename |
| `original_filename` | varchar(255) | not null | As uploaded |
| `file_path` | varchar(500) | not null | Storage path under `storage/app/` |
| `file_size` | integer | not null | Bytes |
| `page_count` | integer | nullable | PDF only |
| `mime_type` | varchar(100) | not null | |
| `file_hash` | varchar(64) | unique, not null | SHA-256 of file contents — duplicate detection |
| `embedding_model` | varchar(100) | nullable | Free-form override (e.g. `text-embedding-3-large`) |
| `embedding_model_id` | ULID | nullable | → `ai_models.id` of `type=embedding` (no FK) |
| `description` | text | nullable | Trix-edited HTML — **sanitize server-side** (rendered with `v-html`) |
| `status` | varchar(20) | default `'pending'` | `pending` / `processing` / `completed` / `failed` |
| `chunks_count` | integer | default `0` | Cached chunk count |
| `error_message` | text | nullable | Populated on `failed` |
| `processed_at` | timestamptz | nullable | When status flipped to `completed` |
| `created_at`, `updated_at` | timestamptz | default `NOW()` | |
| `deleted_at` | timestamptz | nullable | Soft delete (cascades to chunks/embeddings via service) |

**Indexes**:
- `idx_documents_status` on `status`
- `idx_documents_file_hash` on `file_hash` (also unique)

---

### `document_chunks`

`modules/DocumentModule/database/migrations/2026_01_01_000002_create_document_chunks_table.php`
+ `2026_01_01_000003_add_fts_index_to_document_chunks.php` (Postgres-only)

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `document_id` | ULID | not null | → `documents.id` (no FK) |
| `content` | longtext | not null | Chunk text |
| `chunk_index` | integer | not null | 0-based position within document |
| `page_number` | integer | nullable | PDF only |
| `char_start` | integer | not null | Offset within original text |
| `char_end` | integer | not null | Offset within original text |
| `token_count` | integer | nullable | Best-effort token count |
| `metadata` | json | nullable | Free-form per-chunk metadata |
| `tsv_content` | tsvector | nullable | **Postgres only** — generated from `content` for FTS |
| `created_at` | timestamptz | default `NOW()` | (no `updated_at` — chunks are immutable) |

**Indexes**:
- `idx_document_chunks_document_id` on `document_id`
- `idx_document_chunks_doc_chunk` on `(document_id, chunk_index)` — uniqueness enforced at app layer
- `idx_chunks_tsv` GIN index on `tsv_content` — **Postgres only**, used by hybrid search

The `tsv_content` column and its GIN index are skipped on SQLite (test environment).

---

### `vector_embeddings` (metadata) + `ve_{dim}` (per-dimension vectors)

`modules/VectorStoreModule/database/migrations/2026_01_01_000001_create_vector_embeddings_table.php`

This is the most architecturally distinct table set in the system. Vectors live in **per-dimension shards**, not a single table.

#### `vector_embeddings` — metadata only

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `chunk_id` | ULID | not null | → `document_chunks.id` (no FK) |
| `embedding` | json | nullable | **SQLite only** — vectors stored here as JSON arrays |
| `dimensions` | integer | not null | Selects which `ve_{dim}` table holds the actual vector |
| `model_name` | varchar(100) | not null | Provider model that produced the embedding |
| `content_hash` | varchar(32) | not null | MD5 of source text — for invalidation |
| `created_at` | timestamptz | default `NOW()` | |

**Indexes**:
- `idx_vector_embeddings_chunk_id` on `chunk_id`
- `idx_vector_embeddings_dims` on `dimensions`

#### `ve_384`, `ve_768`, `ve_1024`, `ve_1536`, `ve_3072` — actual vectors (Postgres only)

Created at migration time **only when the `vector` extension is installed** (skipped silently otherwise). Each shares the same shape:

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `chunk_id` | ULID | not null | → `document_chunks.id` (no FK) |
| `embedding` | `vector({dim})` | not null | The actual vector |
| `model_name` | varchar(100) | not null | |
| `content_hash` | varchar(32) | not null | |
| `created_at` | timestamptz | default `NOW()` | |

**Indexes per `ve_{dim}` table**:
- `idx_ve_{dim}_chunk_id` on `chunk_id`
- `idx_ve_{dim}_ivfflat` IVFFlat index on `embedding` using `vector_cosine_ops` with `lists = config('rag.vector_store.index_lists')` (default 100)
- IVFFlat index is **skipped for `ve_3072`** because IVFFlat in pgvector currently supports up to 2 000 dims — those vectors are searched with brute-force cosine.

#### Why per-dimension tables?

Different embedding models produce different vector dimensions: OpenAI `text-embedding-3-small` is 1 536, `text-embedding-3-large` is 3 072, Ollama `nomic-embed-text` is 768, etc. pgvector's `vector(N)` type fixes the dimension at column creation time, so a single mixed-dimension table is impossible.

Routing: when storing or searching, the service consults `ai_models.collection` (or `ai_models.dimensions`) to determine which `ve_{dim}` table to hit. The metadata `vector_embeddings` row stays in sync.

---

### `settings`

`modules/SettingsModule/database/migrations/2026_01_01_000001_create_settings_table.php`

Runtime overrides for `config('rag.*')`. Edited via the Settings UI; consulted by the modules at runtime.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `key` | varchar(100) | unique, not null | e.g. `embedding.model`, `search.top_k` |
| `value` | text | nullable | Stored as text; coerced via `type` |
| `type` | varchar(20) | default `'string'` | `string` / `int` / `float` / `bool` / `json` |
| `label` | varchar(255) | nullable | Human-readable label for the UI |
| `group` | varchar(50) | nullable | UI grouping (`embedding`, `llm`, `search`, …) |
| `created_at`, `updated_at` | timestamptz | default `NOW()` | |

---

### `ai_models`

`modules/SettingsModule/database/migrations/2026_01_01_000002_create_ai_models_table.php`

Registry of available embedding + LLM endpoints. Each row is a complete provider configuration.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| `id` | ULID | PK | |
| `name` | varchar(255) | not null | Friendly name shown in UI |
| `type` | varchar(20) | not null | `embedding` or `llm` |
| `provider` | varchar(50) | not null | `openai` or `ollama` |
| `model` | varchar(255) | not null | Provider's model identifier (e.g. `gpt-4o`) |
| `api_key` | text | nullable | Required for OpenAI; optional for Ollama |
| `base_url` | varchar(500) | nullable | Required for Ollama; optional for OpenAI proxy |
| `collection` | varchar(100) | nullable | Maps to a specific `ve_{dim}` table; `null` → derived from `dimensions` |
| `dimensions` | integer | nullable | Embedding only |
| `batch_size` | integer | nullable | Embedding only |
| `cache_ttl` | integer | nullable | Embedding only — cache TTL in seconds |
| `temperature` | decimal(4,2) | nullable | LLM only |
| `max_context_tokens` | integer | nullable | LLM only |
| `timeout` | integer | default `30` | Request timeout in seconds |
| `description` | text | nullable | Trix-edited HTML — **sanitize server-side** (rendered with `v-html`) |
| `settings` | jsonb | nullable | Per-model overrides for search / chunking / chat config |
| `is_active` | boolean | default `true` | When `false`, hidden from selection dropdowns |
| `sort_order` | integer | default `0` | UI ordering |
| `created_at`, `updated_at` | timestamptz | default `NOW()` | |

**Indexes**:
- `idx_ai_models_type` on `type`
- `idx_ai_models_active` on `is_active`

---

## Auxiliary tables

### `password_reset_tokens`
Laravel default. Not exercised by the current auth flow but the migration is in place.

### `sessions`
Laravel HTTP session storage. Used when `SESSION_DRIVER=database`.

### `cache`, `cache_locks`
Laravel cache + atomic locks. Used when `CACHE_STORE=database`.

### `jobs`, `failed_jobs`
Laravel queue + failure log. Used when `QUEUE_CONNECTION=database`. `ProcessDocumentJob` lands here.

---

## Conventions

- **Primary keys**: ULIDs always. Use `HasUlids` trait on Eloquent models, `->whereUlid('id')` in routes.
- **Timestamps**: TIMESTAMPTZ, default `NOW()`. Avoid the timezone-naive `TIMESTAMP` type.
- **Soft deletes**: TIMESTAMPTZ `deleted_at`. Cascade is application-level (services walk relationships and soft-delete children).
- **Relationships**: Defined on Eloquent models (`hasMany`, `belongsTo`, …) but **never as DB-level foreign keys**. This permits per-module migration ordering and easier multi-tenant / shard splits later.
- **JSON columns**: `jsonb` on Postgres for indexable / queryable JSON; plain `json` (text) for opaque blobs.
- **Vector columns**: only on Postgres with the `vector` extension. Migrations are written to be idempotent and skip silently on SQLite (test) and on Postgres without the extension.

---

## Test environment caveats

The Pest test suite uses SQLite `:memory:` (`phpunit.xml`). This means:

- The `tsv_content` column and its GIN index don't exist in tests.
- The `ve_{dim}` tables don't exist; vectors live in `vector_embeddings.embedding` as JSON.
- IVFFlat indexes don't exist; similarity search falls back to whatever the SQLite-mode driver does.
- Hybrid search results in tests will differ from production — keep this in mind when writing relevance-sensitive feature tests.
