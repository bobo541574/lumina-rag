# AGENTS.md

> Compact reference for AI agents working in this repo. **Trust code over config, config over docs** when they disagree.

---

## Commands

| Action | Command |
|--------|---------|
| Dev (server + queue + logs + Vite) | `composer run dev` |
| All tests | `composer run test` (clears config, then `artisan test` via Pest) |
| Single test | `php artisan test --filter=TestName` |
| Test suite | `php artisan test --testsuite=Unit` or `--testsuite=Feature` |
| Formatter | `./vendor/bin/pint` (or `--dirty` for changed files) |
| Frontend build | `npm run build` |
| Frontend dev | `npm run dev` |
| Setup from scratch | `composer run setup` |
| Install npm | `npm install --ignore-scripts` (`.npmrc` sets `ignore-scripts=true`) |
| Queue worker | `php artisan queue:work` |
| Seed data | `php artisan db:seed` |

---

## Stack

| Layer | Detail |
|-------|--------|
| Backend | Laravel 13 monolith, PHP 8.3+, strict types on every PHP file |
| Database | PostgreSQL 16 + pgvector 0.6+ (vectors colocated) |
| Cache/Session/Queue | Redis recommended; queue defaults to `database` in `.env` |
| AI providers | ollama + openai — **raw curl**, no SDK. Implemented in `EmbeddingModule` / `LLMModule` |
| Frontend | Vue 3 + Pinia + vue-router + Tailwind v4 + TypeScript, Vite 8 SPA |
| Testing | Pest 4, SQLite `:memory:` test DB, `QUEUE_CONNECTION=sync` in tests |

Config defaults are ollama-local (`nomic-embed-text:latest`, `qwen3.5:9b`). Override via `.env` — see `config/rag.php`.

---

## Modules

7 PSR-4 modules under `Modules\{Name}Module\` → `modules/{Name}Module/`.

```
ChatModule       → EmbeddingModule + VectorStoreModule + LLMModule
DocumentModule   → EmbeddingModule + VectorStoreModule
SettingsModule   → standalone (AiModel registry only — no more `settings` table)
UserModule       → standalone (token auth)
EmbeddingModule, VectorStoreModule, LLMModule → leaf modules
```

All registered manually in `config/app.php` via `ServiceProvider::defaultProviders()->merge([...])`. No auto-discovery. `config/modules.php` has enabled flags but nothing reads them to disable modules.

### Module structure
```
modules/{Name}Module/
├── Controllers/     (validation + dispatch only)
├── Services/        (business logic — only layer that touches Models)
├── Contracts/       (interfaces bound in Providers/)
├── Models/
├── Requests/        (FormRequest validation)
├── Routes/          (loaded by ServiceProvider)
├── Providers/
├── Jobs/            (DocumentModule only)
├── Commands/        (ChatModule, DocumentModule only)
└── Database/migrations + Seeders
```

Every module has its own `AGENTS.md` — may be stale, cross-reference with source.

### Service bindings

| Module | Contract → Implementation |
|--------|--------------------------|
| ChatModule | `RAGPipelineServiceInterface` → `RAGPipelineService` |
| DocumentModule | `TextExtractionServiceInterface` → `TextExtractionService`, `TextChunkingServiceInterface` → `TextChunkingService` |
| EmbeddingModule | `EmbeddingProviderInterface` → `OpenAIEmbeddingProvider`, `EmbeddingServiceInterface` → `EmbeddingService` |
| LLMModule | `LLMProviderInterface` → `OpenAILLMProvider`, `LLMServiceInterface` → `LLMService` |
| UserModule | `AuthServiceInterface` → `AuthService` |
| VectorStoreModule | `VectorStoreInterface` → `VectorStoreService` (wraps `PgvectorDriver` — only driver) |
| SettingsModule | `AiModelService` (no Contract/) |

---

## Database

All PKs are **ULIDs** (`HasUlids` trait, `->whereUlid()` route binding). **No DB-level FK constraints** — enforced in app code only.

| Table | PK | Key columns |
|-------|----|-------------|
| `chat_sessions` | ULID | `user_id`, `title`, `is_archived` (bool), `last_activity_at`, soft deletes |
| `chat_messages` | ULID | `session_id`, `role`, `content` (longText), `sources` (jsonb), soft deletes |
| `documents` | ULID | `user_id`, `title`, `file_hash` (unique), `status`, `chunks_count`, soft deletes |
| `document_chunks` | ULID | `document_id`, `content`, `chunk_index`, `page_number`, `char_start`, `char_end`, unique `(document_id, chunk_index)` |
| `vector_embeddings` | ULID | Metadata only: `chunk_id`, `dimensions`, `model_name`, `content_hash`. JSON `embedding` on SQLite fallback. |
| `ve_{dims}` (shard tables) | — | `chunk_id`, `embedding` `vector(N)`, `model_name`, `content_hash`. IVFFlat `vector_cosine_ops` index on `embedding`. Created conditionally (pgvector required, skipped on SQLite). |
| `ai_models` | ULID | Embedding/LLM model registry with per-model config (provider, credentials, dimensions, temperature, timeout, settings JSONB for pipeline overrides) |

The old `settings` key/value table was removed — `config/rag.php` is the single source of truth for global config. Per-model pipeline overrides live in `ai_models.settings` JSONB.

Shard tables: `ve_384`, `ve_768`, `ve_1024`, `ve_1536`, `ve_3072`.

---

## API

All routes under `api/`, auth via custom `auth.token` middleware (no Sanctum). Standard envelope: `{ success, message, data, errors }`. 80-char hex tokens (`bin2hex(random_bytes(40))`), stored on `users.api_token`.

| Group | Endpoints |
|-------|-----------|
| Auth | `POST /api/auth/register \| /login \| /logout`, `GET /api/auth/me` |
| Chat | `POST /api/chat`, `GET/DELETE /api/chat/sessions[/{id}]` |
| Documents | `GET/POST /api/documents`, `GET /api/documents/{id} \| /status \| /retry`, `PUT/DELETE /api/documents/{id}` |
| Settings (AiModels) | `CRUD /api/settings/ai-models[/{id}]` |

---

## Configuration

`config/rag.php` is the single source of truth — embedding/LLM provider, model, dimensions, batch size, cache TTL, timeouts, search top-K, similarity threshold, chunk size/overlap, chat limits, logging. All values read via `env()` with sensible defaults for local Ollama.

Per-model overrides (search mode, top_K, MMR, query expansion, max_question_length) stored in `ai_models.settings` JSONB, consumed by `RAGPipelineService`.

---

## Business Rules (verified from code)

- **Question**: empty → `InvalidArgumentException`. >1000 chars → truncated. No chunks ≥0.65 threshold → *"I cannot answer..."* (no LLM call).
- **Sessions**: auto-created if no `session_id`. Title from **first user question** (50 chars). Max 100 msgs → `RuntimeException`.
- **Uploads**: max 50MB. Formats: PDF, DOCX, TXT, CSV, MD. SHA-256 dedup → 409. Processing is **async** via `ProcessDocumentJob` (dispatched by `DocumentService::upload()`).
- **Chunking**: recursive char splitter, priority `\n\n` → `\n` → `.` → `,` → space → char, 1000/200 chars. MD5-hash cached embeddings (24h TTL).
- **Auth**: tokens rotate on login, nulled on logout. Login throttled 5/60s.
- **AiModel selection**: `RAGPipelineService` picks first active model by `sort_order` unless a specific model ID is provided in the request. `DocumentUpload` allows per-document embedding model override.

---

## Testing

- `tests/Pest.php` applies `RefreshDatabase` to **Feature** suite only.
- `phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`.
- External API calls must be mocked (no network in tests).
- Naming convention: `test_{what}_{expectedOutcome}`.

---

## Conventions & Gotchas

- `declare(strict_types=1)` on every PHP file.
- ULID PKs everywhere. Route binding: `->whereUlid('id')`.
- Only Services touch Models. Controllers validate + dispatch only.
- Providers (EmbeddingModule/LLMModule) call OpenAI/Ollama via raw curl — keep behind `EmbeddingProviderInterface` / `LLMProviderInterface`.
- Both facades (`Storage`, `Log`, `Response`) and constructor-injected config are used — don't refactor one to the other without reason.
- New modules: create dir under `modules/`, add PSR-4 entry in `composer.json`, register `ServiceProvider` in `config/app.php`, run `composer dump-autoload`.
- Horizon is installed (`laravel/horizon`) but the UI isn't wired — use `php artisan queue:work`.
- Module-level `AGENTS.md` files may be stale — cross-reference with source code.
- `PROJECT_RULES.md` describes aspirational coding standards (e.g. "no facades", "90% coverage") that the codebase does not fully enforce — treat as guidelines, not hard rules.
