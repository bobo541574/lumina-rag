# AGENTS.md

> `/init` — comprehensive project reference. **Trust code over docs** when they disagree.

---

## Commands

| Action | Command |
|--------|---------|
| Dev server | `composer run dev` — serves + queue + logs + Vite concurrently |
| Tests | `composer run test` — runs `config:clear` then `artisan test` (Pest) |
| Single test | `php artisan test --filter=TestName` |
| Formatter | `./vendor/bin/pint` |
| Frontend build | `npm run build` |
| Setup from scratch | `composer run setup` |
| Install npm | `npm install --ignore-scripts` (`.npmrc` has `ignore-scripts=true`) |

---

## Architecture

### Stack
| Layer | Technology |
|-------|-----------|
| Backend | Laravel 13 monolith, PHP 8.3+ |
| Database | PostgreSQL 16 + pgvector 0.6+ |
| Cache/Session | Redis |
| LLM | OpenAI API (`gpt-4o`, `text-embedding-ada-002`) via raw curl |
| Frontend | **Aspirational only** — `resources/js/app.js` is `//`. No Vue/Pinia/Inertia deps exist |
| Queue | `QUEUE_CONNECTION=sync` in test, `database` default, `redis` in .env.example. **No Horizon package or config installed** |

### Module layout
5 PSR-4 modules mapped under `Modules\{Name}Module\` → `modules/{Name}Module/`.

```
ChatModule → EmbeddingModule + VectorStoreModule + LLMModule
DocumentModule → EmbeddingModule + VectorStoreModule
```

All registered manually in `config/app.php` providers array:
```php
'providers' => ServiceProvider::defaultProviders()->merge([
    EmbeddingModuleServiceProvider::class,
    VectorStoreModuleServiceProvider::class,
    LLMModuleServiceProvider::class,
    DocumentModuleServiceProvider::class,
    ChatModuleServiceProvider::class,
])->toArray(),
```
No `config/modules.php` exists (despite docs referencing one).

### User / Auth
`UserModule` manages user registration and API token authentication. `App\Models\User` uses ULID `id`. Auth is token-based (80-char random tokens, no Sanctum). Endpoints: `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`.

### Module service bindings
| Module | Contract → Implementation |
|--------|--------------------------|
| ChatModule | `RAGPipelineServiceInterface` → `RAGPipelineService` |
| DocumentModule | `TextExtractionServiceInterface` → `TextExtractionService`, `TextChunkingServiceInterface` → `TextChunkingService` |
| EmbeddingModule | `EmbeddingProviderInterface` → `OpenAIEmbeddingProvider`, `EmbeddingServiceInterface` → `EmbeddingService` |
| LLMModule | `LLMProviderInterface` → `OpenAILLMProvider`, `LLMServiceInterface` → `LLMService` |
| UserModule | `AuthServiceInterface` → `AuthService` |
| VectorStoreModule | `VectorStoreInterface` → `VectorStoreService` (wraps `PgvectorDriver`) |

### Module structure (standard)
```
modules/{Name}Module/
├── AGENTS.md
├── Controllers/
├── Services/
├── Models/
├── Requests/
├── Routes/
├── Contracts/
└── Tests/
```
No `Vue/` directories exist in any module (aspirational).

---

## Docs vs Code — Resolved Discrepancies

These items were previously mismatched but have been aligned:

| Docs say | Code now does | 
|----------|---------------|
| `config/modules.php` for module toggles | File exists with all 5 modules enabled by default |
| No facades | `DocumentService` uses `Storage::` facade; `ChatController` uses `ResponseFacade` | 
| Config injection via constructor | All services accept config via constructor injection |
| `ProcessDocumentJob` via queue | `ProcessDocumentJob` exists in `modules/DocumentModule/Jobs/` |
| `user_id` on sessions & documents | Added in the relevant create-table migrations |
| `page_count` on documents | Added in `documents` create-table migration |
| `token_count`, `metadata` on chunks | Added in `document_chunks` create-table migration |
| `embedding` column + IVFFlat index on `vector_embeddings` | Added in vector_embeddings create-table migration (conditional on pgvector) |
| `smalot/pdfparser`, `phpoffice/phpword` for text extraction | Both installed in `composer.json` |
| Async document processing with batches | `ProcessDocumentJob` dispatched by `DocumentService::upload()` |
| Session title from first question | Set from user's first question at `RAGPipelineService` |

### Still Aspirational (not implemented)

| Docs say | Actual code says |
|----------|-----------------|
| Vue frontend (Vue 3, Pinia, Inertia, TypeScript) | `resources/js/` files exist but frontend requires `npm install --ignore-scripts && npm run build` to function |
| Horizon for queue monitoring | No Horizon package — use `php artisan queue:work` |
| DB-level FK constraints | No FK constraints in migrations (app-level only) |
| `POST /api/documents/{id}/retry` | Route does not exist |
| `status` enum on sessions (`active`/`archived`/`deleted`) | Uses `is_archived` boolean + soft deletes |
| Pinecone driver exists | Only `PgvectorDriver` implemented |

---

## Database

### Migration schema (actual — not docs)
| Table | PK | Key columns |
|-------|----|-------------|
| `chat_sessions` | ULID | `title`, `is_archived` (bool), `last_activity_at`, soft deletes |
| `chat_messages` | ULID | `session_id` (ULID), `role`, `content` (longText), `sources` (jsonb, nullable), soft deletes |
| `documents` | ULID | `title`, `original_filename`, `file_path`, `file_size`, `mime_type`, `file_hash` (unique), `status`, `chunks_count`, soft deletes |
| `document_chunks` | ULID | `document_id` (ULID), `content` (longText), `chunk_index`, `page_number` (nullable), `char_start`, `char_end` |
| `vector_embeddings` | ULID | `chunk_id` (ULID), `embedding` (vector, dims from config), `model_name`, `content_hash` |

### Relationships (app-level, no DB FKs)
```
chat_sessions ──< chat_messages
documents ──< document_chunks ──< vector_embeddings
```

### Indexes
- IVFFlat on `vector_embeddings.embedding` using `vector_cosine_ops` with `lists = 100` (once `embedding` column exists)
- Unique on `documents.file_hash` (deduplication)
- Unique on `document_chunks (document_id, chunk_index)`

### Test config (`phpunit.xml`)
- `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`
- `RefreshDatabase` trait commented out in `tests/Pest.php`

### Seeders
`database/seeders/DatabaseSeeder.php` calls all module-level seeders:

| Module | Seeder | Creates |
|--------|--------|---------|
| UserModule | `UserModuleSeeder` | 2 users with API tokens |
| ChatModule | `ChatModuleSeeder` | 2 sessions with messages |
| DocumentModule | `DocumentModuleSeeder` | 1 document with 3 chunks |
| VectorStoreModule | `VectorStoreModuleSeeder` | Embeddings for existing chunks (skips if pgvector unavailable) |

Run via: `php artisan db:seed`

### Docs vs actual schema differences (columns missing from migrations)
- `chat_sessions`: missing `user_id`, `status` enum (replaced by `is_archived`), `message_count`, `last_activity_at` (exists)
- `chat_messages`: missing `embedding_id`, `token_count`
- `documents`: missing `user_id`, `page_count`, `error_message` (exists? need to check)
- `document_chunks`: missing `token_count`, `vector_id`, `metadata`
- `vector_embeddings`: `embedding` column dimension reads from `config('rag.embedding.dimensions')` at migration time (default 1536)

---

## API

All routes under `api/` prefix, defined in `modules/{Module}/Routes/`.

### Health
```
GET /api/health
```

### Auth (auth.php)
```
POST  api/auth/register → AuthController@register
POST  api/auth/login    → AuthController@login
POST  api/auth/logout   → AuthController@logout
GET   api/auth/me       → AuthController@me
```

### Chat (chat.php)
```
POST   api/chat/              → ChatController@ask
GET    api/chat/sessions      → ChatController@sessions
GET    api/chat/sessions/{id} → ChatController@showSession   (whereUlid)
DELETE api/chat/sessions/{id} → ChatController@destroySession (whereUlid)
```

### Documents (document.php)
```
GET    api/documents          → DocumentController@index
POST   api/documents          → DocumentController@upload
GET    api/documents/{id}     → DocumentController@show       (whereUlid)
GET    api/documents/{id}/status → DocumentController@status  (whereUlid)
DELETE api/documents/{id}     → DocumentController@destroy    (whereUlid)
```
No `POST .../retry` route exists.

### Consistent response format
```json
{
  "success": true|false,
  "message": "...",
  "data": { ... },
  "errors": { ... }
}
```

---

## Config (`config/rag.php`)

| Key | Env | Default | Description |
|-----|-----|---------|-------------|
| `embedding.provider` | `RAG_EMBEDDING_PROVIDER` | `openai` | |
| `embedding.api_key` | `OPENAI_API_KEY` | — | |
| `embedding.model` | `RAG_EMBEDDING_MODEL` | `text-embedding-ada-002` | |
| `embedding.dimensions` | `RAG_EMBEDDING_DIMENSIONS` | `1536` | |
| `embedding.batch_size` | `RAG_EMBEDDING_BATCH_SIZE` | `100` | |
| `embedding.cache_ttl` | `RAG_EMBEDDING_CACHE_TTL` | `86400` | 24h |
| `embedding.timeout` | `RAG_EMBEDDING_TIMEOUT` | `30` | seconds |
| `llm.provider` | `RAG_LLM_PROVIDER` | `openai` | |
| `llm.api_key` | `OPENAI_API_KEY` | — | |
| `llm.model` | `RAG_LLM_MODEL` | `gpt-4o` | |
| `llm.temperature` | `RAG_LLM_TEMPERATURE` | `0.3` | |
| `llm.max_context_tokens` | `RAG_LLM_MAX_CONTEXT_TOKENS` | `4000` | |
| `llm.timeout` | `RAG_LLM_TIMEOUT` | `60` | seconds |
| `vector_store.driver` | `RAG_VECTOR_DRIVER` | `pgsql` | |
| `vector_store.index_lists` | `RAG_VECTOR_INDEX_LISTS` | `100` | IVFFlat lists |
| `search.top_k` | `RAG_SEARCH_TOP_K` | `5` | |
| `search.similarity_threshold` | `RAG_SEARCH_SIMILARITY_THRESHOLD` | `0.65` | |
| `chunking.chunk_size` | `RAG_CHUNK_SIZE` | `1000` | chars |
| `chunking.overlap` | `RAG_CHUNK_OVERLAP` | `200` | chars |
| `chat.max_question_length` | `RAG_MAX_QUESTION_LENGTH` | `1000` | chars |
| `chat.max_messages_per_session` | `RAG_MAX_MESSAGES_PER_SESSION` | `100` | |

---

## OpenAI API

Both embedding and LLM use **raw curl** (no SDK).

| Module | Endpoint | Model default |
|--------|----------|---------------|
| Embedding | `POST https://api.openai.com/v1/embeddings` | `text-embedding-ada-002` |
| LLM | `POST https://api.openai.com/v1/chat/completions` | `gpt-4o` |

---

## Business Rules (verified from code)

### Question handling
- Empty question → `InvalidArgumentException`
- Question > 1000 chars → truncated to `max_question_length`
- No chunks above threshold (0.65) → `"I cannot answer this question based on the available documents."`
- No documents exist → returns answer from LLM with no context (verify exact behavior)

### Session rules
- 100-msg limit → `RuntimeException` with "Session full"
- Deleted session → `RuntimeException` on access
- Session title from **assistant response** first 50 chars, not the question
- New session auto-created if no `session_id` provided

### Upload rules
- Max file size: 50MB
- Formats: PDF, DOCX, TXT, CSV, Markdown
- Duplicate (SHA-256 hash match) → 409 with existing document
- **Processing runs synchronously** (no queue job — despite docs describing `ProcessDocumentJob`)

### Sources format
```json
{
  "document_id": "...",
  "document_title": "...",
  "chunk_index": 12,
  "page_number": null,
  "similarity_score": 0.89,
  "excerpt": "..."
}
```

### Chunking
- Recursive Character Text Splitter
- Separator priority: `\n\n` → `\n` → `.` → `,` → space → char
- 1000 chars per chunk, 200 overlap
- MD5 hash caching for embeddings (24h TTL)

---

## Coding Standards

All PHP files must have `declare(strict_types=1)`. All PKs are ULIDs (`HasUlids` trait on models, `->whereUlid()` route binding). No DB-level FK constraints. Both facades and direct `config()` calls are used in practice.

### Module isolation rules (from PROJECT_RULES.md — aspirational)
- Modules communicate only through defined service contracts
- No direct DB table access across modules
- Each module can be enabled/disabled (but `config/modules.php` doesn't exist)

### Testing standards (aims)
- Services: 90%+ coverage, Controllers: 80%+
- Mock external services
- `test_{what}_{expectedOutcome}` naming

---

## Environment

`.env.example` requires `OPENAI_API_KEY`. PostgreSQL 16 + pgvector + Redis required. Setup: `composer run setup`.

### Queue
- Default driver: `database` (PostgreSQL jobs table)
- `.env.example` sets `QUEUE_CONNECTION=redis`
- **No Horizon package** — use `php artisan queue:listen` or `php artisan queue:work`
- Document processing is synchronous (`QUEUE_CONNECTION=sync` in test)

---

## UserModule
`UserModule` manages user registration and API token authentication. `App\Models\User` uses ULID `id`. Auth is token-based (80-char random tokens, no Sanctum). Endpoints: `POST /api/auth/register`, `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me`.

---

## Module-level docs (may be stale)

Each module has `AGENTS.md` at `modules/{Name}Module/AGENTS.md`. They reference ULIDs, queue jobs, Pinecone drivers, and Vue components that may not match actual code. Always cross-reference with actual source.
