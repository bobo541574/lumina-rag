# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

> See [AGENTS.md](AGENTS.md) for the full project reference. Treat code as the source of truth when docs and code disagree — several legacy docs (`PROJECT_ARCHITECTURE.md`, `BUSINESS_LOGIC.md`, `SETUP_GUIDE.md`) describe aspirational behavior that the code does not implement.

---

## Commands

| Action | Command |
|--------|---------|
| Dev (server + queue + logs + Vite, concurrent) | `composer run dev` |
| Run all tests | `composer run test` (clears config, then `artisan test` via Pest) |
| Run a single test | `php artisan test --filter=TestName` |
| Run one suite | `php artisan test --testsuite=Unit` or `--testsuite=Feature` |
| Format PHP | `./vendor/bin/pint` (or `./vendor/bin/pint --dirty` for changed files) |
| Frontend build | `npm run build` |
| Frontend dev | `npm run dev` |
| Initial setup | `composer run setup` (install + key + migrate + npm install + build) |
| Install npm | `npm install --ignore-scripts` (`.npmrc` sets `ignore-scripts=true`) |
| Queue worker | `php artisan queue:work` (no Horizon UI is wired even though the package is installed) |
| Seed data | `php artisan db:seed` |

Tests use SQLite `:memory:` and `QUEUE_CONNECTION=sync` — see `phpunit.xml`. `tests/Pest.php` applies `RefreshDatabase` to the `Feature` suite only.

---

## Architecture

### Stack
- **Backend**: Laravel 13 monolith, PHP 8.3+
- **Database**: PostgreSQL 16 + pgvector 0.6+ (vectors live in the same DB as relational data)
- **Cache/Session/Queue**: Redis recommended; queue defaults to `database`
- **AI**: Ollama (`nomic-embed-text:latest`, `all-MiniLM-L6-v2`, `mxbai-embed-large` for embedding; `qwen3.5:9b`, `gemma4:e4b`, `qwen2.5-coder` for LLM) via raw curl — no official SDK. OpenAI is supported but no longer the default; the AiModel registry in the DB is the source of truth.
- **Frontend**: Vue 3 + Pinia + vue-router + Tailwind v4 + TypeScript, built with Vite. Single SPA mounted from `resources/js/app.js`.

### Module layout (PSR-4 → `Modules\{Name}Module\` → `modules/{Name}Module/`)

```
ChatModule       → EmbeddingModule + VectorStoreModule + LLMModule
DocumentModule   → EmbeddingModule + VectorStoreModule
SettingsModule   → standalone (AI model registry + term alias registry)
UserModule       → standalone (token auth)
EmbeddingModule  → leaf
VectorStoreModule → leaf
LLMModule        → leaf
```

All seven module service providers are registered manually in [config/app.php](config/app.php) via `ServiceProvider::defaultProviders()->merge([...])`. There is no auto-discovery. `config/modules.php` lists enabled flags for documentation/future-toggle purposes; nothing currently reads it to disable modules.

### Standard module structure
```
modules/{Name}Module/
├── AGENTS.md          (per-module reference — may be stale)
├── Controllers/
├── Services/          (business logic + the only layer that touches Models)
├── Contracts/         (service interfaces, bound in Providers/)
├── Models/
├── Requests/          (FormRequest validation)
├── Routes/            (loaded by the module's ServiceProvider)
├── Providers/
├── Jobs/              (DocumentModule only)
├── Commands/          (ChatModule, DocumentModule)
└── database/migrations + database/Seeders
```

### Service contract bindings
| Module | Contract → Implementation |
|--------|--------------------------|
| ChatModule | `RAGPipelineServiceInterface` → `RAGPipelineService` |
| DocumentModule | `TextExtractionServiceInterface` → `TextExtractionService`, `TextChunkingServiceInterface` → `TextChunkingService` |
| EmbeddingModule | `EmbeddingProviderInterface` → `OpenAIEmbeddingProvider`, `EmbeddingServiceInterface` → `EmbeddingService` |
| LLMModule | `LLMProviderInterface` → `OpenAILLMProvider`, `LLMServiceInterface` → `LLMService` |
| UserModule | `AuthServiceInterface` → `AuthService` |
| VectorStoreModule | `VectorStoreInterface` → `VectorStoreService` (wraps `PgvectorDriver` — only driver implemented) |
| SettingsModule | `SettingsService`, `AiModelService`, `TermAliasServiceInterface` → `TermAliasService` |

### Request flow (must follow this direction)
```
Request → Controller → Service (Interface) → Model
```
Only Services touch Models. Controllers do validation + dispatch only. See `~/.claude/CLAUDE.md` for the full coding standard the user enforces globally.

---

## RAG pipeline (the core flow)

[modules/ChatModule/Services/RAGPipelineService.php](modules/ChatModule/Services/RAGPipelineService.php) orchestrates:

1. Embed the question via `EmbeddingService` (cached by MD5(text), 24h TTL).
2. `VectorStoreService::search(vector, topK=5)` against pgvector using cosine distance + IVFFlat index (`lists=100`).
3. `applyDynamicThreshold()` — elbow method starting from `0.20`, capped at `search.similarity_threshold` (default `0.65`). If all chunks below threshold → return `"I cannot answer this question based on the available documents."`
4. Concatenate top chunks (truncated to `llm.max_context_tokens`, default `32768`) into the LLM prompt. Output capped via `llm.max_tokens` (default `4096`).
5. `LLMService::complete(...)` → answer, returned with `sources[]` (document_id, title, chunk_index, page_number, similarity_score, excerpt).
6. Persist user + assistant `chat_messages`, attach to a `chat_session` (auto-created if no `session_id`).

Document upload flow ([modules/DocumentModule/Services/DocumentService.php](modules/DocumentModule/Services/DocumentService.php) → [modules/DocumentModule/Jobs/ProcessDocumentJob.php](modules/DocumentModule/Jobs/ProcessDocumentJob.php)): validate → SHA-256 dedupe → store file → create `Document` (status `pending`) → dispatch job → extract text (PDF via `smalot/pdfparser`, DOCX via `phpoffice/phpword`) → chunk (recursive char splitter, 1000/200) → batch-embed (100/call) → upsert vectors → mark `completed`.

---

## Database

All PKs are **ULIDs** (`HasUlids` trait, route binding via `->whereUlid()`). **No DB-level FK constraints** — relationships are enforced in app code only.

| Table | Notable columns |
|-------|-----------------|
| `chat_sessions` | `user_id`, `title`, `is_archived`, `last_activity_at`, soft deletes |
| `chat_messages` | `session_id`, `role`, `content` (longText), `sources` (jsonb), soft deletes |
| `documents` | `user_id`, `title`, `original_filename`, `file_path`, `file_size`, `mime_type`, `file_hash` (unique — dedupe), `status`, `chunks_count`, `token_count`, `embedding_model`, `embedding_model_id`, `report_date` (date), `project`, `is_public`, soft deletes |
| `document_chunks` | `document_id`, `content`, `chunk_index`, `page_number`, `token_count`, `char_start`, `char_end`, unique on `(document_id, chunk_index)` |
| `vector_embeddings` | **Metadata only**: `chunk_id`, `dimensions`, `model_name`, `content_hash` (+ `embedding` JSON on SQLite). |
| `ve_384` / `ve_768` / `ve_1024` / `ve_1536` / `ve_3072` | **Postgres+pgvector only** — actual vectors. `chunk_id`, `embedding` (`vector(N)`), `model_name`, `content_hash`. IVFFlat `vector_cosine_ops` index on `embedding` (skipped for `ve_3072` — IVFFlat caps at 2000 dims). |
| `ai_models` (SettingsModule) | configurable embedding/LLM model registry. `settings` JSONB holds per-model overrides (e.g. `{"max_tokens":4096}`). |
| `term_aliases` (SettingsModule) | `id` (ULID), `type`, `alias`, `canonical`, `description`, `is_active`; unique on `(alias, canonical)`. |

Vector-related migration steps (creating the `ve_{dim}` shard tables and their IVFFlat indexes) are conditional — they're skipped on connections without the pgvector extension. Under SQLite (test runs), vectors fall back to a JSON `embedding` column on `vector_embeddings`.

---

## API

All routes are prefixed `api/` and registered by each module's `ServiceProvider` from `modules/{Name}Module/Routes/`. Auth-protected routes use the `auth.token` middleware (custom — no Sanctum).

| Group | Endpoints |
|-------|-----------|
| Auth (`auth.php`) | `POST /api/auth/register \| /login \| /logout`, `GET /api/auth/me` |
| Chat (`chat.php`) | `POST /api/chat`, `GET/DELETE /api/chat/sessions[/{ulid}]` |
| Documents (`document.php`) | `GET /api/documents` (server-side pagination: `?page=&per_page=&search=&sort_key=&sort_dir=`), `POST /api/documents`, `GET /api/documents/{ulid}`, `GET /api/documents/{ulid}/status`, `POST /api/documents/{ulid}/retry`, `PUT /api/documents/{ulid}`, `DELETE /api/documents/{ulid}` |
| Settings (`settings.php`) | `GET /api/settings`, `PUT /api/settings/bulk`, `PUT/DELETE /api/settings/{key}`, `GET/POST/PUT/DELETE /api/settings/ai-models[/{ulid}]`, `GET/POST /api/settings/term-aliases`, `GET/PUT/DELETE /api/settings/term-aliases/{id}` |

Standard response envelope: `{ success, message, data, errors }`.

All list endpoints support `?page=` and `?per_page=` query params and return `{ success, data, meta }` where `meta` contains `current_page`, `last_page`, `per_page`, `total`, `from`, `to`. Server caps `per_page` at `config('rag.pagination.max_per_page')` (default 100).

Tokens: 80-char hex (`bin2hex(random_bytes(40))`), stored on `users.api_token`. Login rotates the token; logout nulls it.

---

## Configuration

[config/rag.php](config/rag.php) is the central knob panel — embedding/LLM provider, model, dimensions, batch size, cache TTL, timeouts, search top-K, similarity threshold, chunk size/overlap, max question length, max messages per session. All read via `env()` with sensible defaults; the user's standard prefers constructor-injected config over runtime `config()` calls inside services.

`RAG_VECTOR_DRIVER` defaults to `pgsql` (only driver implemented).

---

## Frontend

Vue 3 SPA in `resources/js/`:
```
App.vue, app.js, router.ts
components/   (ChatInterface, DocumentUpload/List/Detail, SessionList, AiModelsManager, ui/)
pages/        (ChatPage, DocumentsPage, LoginPage, RegisterPage, SettingsPage, AiModelsPage, TermAliasesPage)
stores/       (authStore, chatStore, documentStore, settingsStore — Pinia)
services/     (per-module API client wrappers around axios, e.g. termAliasService.ts)
composables/  types/
```
Vite builds via `vite.config.js` with `@vitejs/plugin-vue`, `@tailwindcss/vite`, `laravel-vite-plugin`, and bunny-fonts (Instrument Sans). `trix-editor` is registered as a custom element.

---

## Conventions specific to this repo

- `declare(strict_types=1);` at the top of every PHP file.
- ULID PKs everywhere — use `HasUlids` on new models and `->whereUlid('id')` on routes.
- No DB-level FKs; declare relationships in models, enforce existence in services.
- Both facades (`Storage`, `Log`, `Response`) and constructor-injected config are used in practice — don't refactor one style to the other without a reason.
- OpenAI calls go through raw curl in `OpenAIEmbeddingProvider` / `OpenAILLMProvider` — keep new providers behind the same interface (`EmbeddingProviderInterface`, `LLMProviderInterface`) rather than calling APIs from services directly.
- Embeddings are MD5-cached (24h). Don't bypass the cache layer when adding new embedding call sites — go through `EmbeddingService`.
- New modules: create the directory under `modules/`, add the PSR-4 entry in [composer.json](composer.json), register the `ServiceProvider` in [config/app.php](config/app.php), and run `composer dump-autoload`.

---

## Known doc/code drift

The legacy docs in the repo root predate several refactors. When in doubt:
- `composer.json` is the source of truth for dependencies (Vue/Pinia/smalot/pdfparser/phpoffice-phpword/laravel-horizon are all installed despite older notes saying otherwise).
- `config/modules.php` exists with all 7 modules enabled — but no code currently honors the `enabled` flag.
- Document processing **is** asynchronous via `ProcessDocumentJob` (older `BUSINESS_LOGIC.md` text describing synchronous processing is stale).
- Always re-read the relevant `Routes/*.php`, `Services/*.php`, and migration files before stating an API or schema fact.
