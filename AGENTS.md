# AGENTS.md

> Compact reference for AI agents working in this repo. **Trust code over config, config over docs** when they disagree.

---

## Commands

| Action | Command |
|--------|---------|
| Dev (concurrent: serve + queue + logs + Vite) | `composer run dev` |
| Run all tests | `composer run test` (clears config, then `artisan test` via Pest) |
| Run one test | `php artisan test --filter=TestName` |
| Run one suite | `php artisan test --testsuite=Unit` or `--testsuite=Feature` |
| Format PHP | `./vendor/bin/pint` (or `--dirty` for changed files) |
| Frontend build | `npm run build` |
| Frontend dev | `npm run dev` |
| Install npm | `npm install --ignore-scripts` |
| Queue worker | `php artisan queue:work` |
| Seed data | `php artisan db:seed` |
| Re‑embed all chunks (regenerates vectors) | `php artisan rag:reembed` |
| Re‑embed one document | `php artisan rag:reembed --document={ulid}` |

Tests: SQLite `:memory:`, `QUEUE_CONNECTION=sync`. `tests/Pest.php` applies `RefreshDatabase` to Feature suite only.

---

## Architecture

### Stack
- **Backend**: Laravel 13 monolith, PHP 8.3+
- **Database**: PostgreSQL 16 + pgvector 0.6+ (vectors in same DB)
- **Cache/Session/Queue**: Redis recommended; queue defaults to `database`
- **AI**: OpenAI (`gpt-4o`, `text-embedding-3-small`), Ollama (`nomic-embed-text:latest`), **Gemini** (`text-embedding-004`, `gemini-2.5-flash`), **Claude** (`claude-sonnet-4-5-20250929`), **DeepSeek** (`deepseek-chat`) via raw curl — no official SDK. Interchangeable per-document via AiModel registry.
- **Frontend**: Vue 3 + Pinia + vue-router + Tailwind v4 + TypeScript, built with Vite. Single SPA from `resources/js/app.js`.

### Module layout (PSR-4 → `Modules\{Name}Module\` → `modules/{Name}Module/`)

```
ChatModule       → EmbeddingModule + VectorStoreModule + LLMModule
DocumentModule   → EmbeddingModule + VectorStoreModule
SettingsModule   → standalone (AiModel registry)
UserModule       → standalone (token auth)
EmbeddingModule  → leaf
VectorStoreModule → leaf
LLMModule        → leaf
```

All 7 service providers registered manually in `config/app.php` via `ServiceProvider::defaultProviders()->merge([...])`. No auto-discovery. `config/modules.php` exists but is **not** read at runtime.

### Standard module structure
```
modules/{Name}Module/
├── Controllers/     (validation + dispatch only)
├── Services/        (business logic — the only layer touching Models)
├── Contracts/       (interfaces, bound in ServiceProvider)
├── Models/          (Eloquent, HasUlids, soft-deletes)
├── Requests/        (FormRequest validation)
├── Routes/          (loaded by ServiceProvider)
├── Providers/
├── Jobs/            (DocumentModule only)
├── Commands/        (ChatModule → CleanupExpiredSessions, DocumentModule → ReEmbed)
└── database/migrations + database/Seeders
```

### Request flow
```
Request → Controller → Service (Interface) → Model
```
No Repository layer. Services interact with Models directly. Controllers only validate and dispatch.

---

## RAG pipeline

`modules/ChatModule/Services/RAGPipelineService.php` orchestrates:

1. **Embed** the question via `EmbeddingService` (MD5-cached, 24h TTL).
2. **Search** — `VectorStoreService::searchHybrid()` runs vector cosine + FTS (`plainto_tsquery('english', ...)`) in parallel, fused by reciprocal rank fusion.
3. **Filter** — chunks below `similarity_threshold` (default `0.65`) are dropped. If none survive → refusal, no LLM call.
4. **Context** — top chunks (truncated to `llm.max_context_tokens`) concatenated into the LLM prompt.
5. **Answer** — `LLMService::complete(...)` with `temperature=0.3`. Sources attached (document_id, title, chunk_index, page_number, score, excerpt).
6. **Persist** — user + assistant `chat_messages` saved to `chat_session`.

Document upload: `DocumentService::upload()` → dispatch `ProcessDocumentJob` → extract text (smalot/pdfparser, phpoffice/phpword) → chunk (recursive char splitter, 1000/200) → batch-embed via document's `embedding_model_id` AiModel → upsert vectors → mark `completed`.

---

## Database

All PKs are **ULIDs** (`HasUlids` trait, `->whereUlid()` route binding). **No DB-level FK constraints** — enforced in app code only.

| Table | Key notes |
|-------|-----------|
| `chat_sessions` | `user_id`, soft deletes |
| `chat_messages` | `session_id`, `role`, `content`, `sources` (jsonb), soft deletes |
| `documents` | `user_id`, `file_hash` (unique), `status`, `chunks_count`, `report_date` (date), `project` (varchar), `embedding_model_id` (ULID → ai_models), `embedding_model` (string), soft deletes |
| `document_chunks` | `document_id`, `content`, `chunk_index`, `metadata` (jsonb, with `@>` GIN index), unique `(document_id, chunk_index)`, `tsv_content` (tsvector, `english` config, GIN index) |
| `vector_embeddings` | Metadata only on pgvector (no embedding column); `embedding` column exists only on SQLite fallback |
| `ve_{dims}` (shard tables) | `chunk_id`, `embedding` `vector(N)`, `model_name`, `content_hash`. IVFFlat `vector_cosine_ops` index. Shards: `ve_{384,768,1024,1536,3072}` |
| `ai_models` | Embedding/LLM model registry with per-model config (provider, credentials, dims, temperature, timeout, settings JSONB for pipeline overrides). Active model determined by `is_active` + `sort_order`. |

Shard tables are created conditionally (pgvector required, skipped on SQLite).

---

## API

All routes under `api/`. Auth via custom `auth.token` middleware (80-char hex token, `Authorization: Bearer`).

| Group | Endpoints |
|-------|-----------|
| Auth | `POST /api/auth/{register\|login\|logout}`, `GET /api/auth/me` |
| Chat | `POST /api/chat`, `GET/DELETE /api/chat/sessions[/{ulid}]` |
| Documents | `GET /api/documents`, `POST /api/documents`, `GET /api/documents/{ulid}[/status]`, `DELETE /api/documents/{ulid}` |
| AiModels | `CRUD /api/settings/ai-models[/{id}]` |

Response envelope: `{ success, message, data, errors }`.

---

## Configuration

`config/rag.php` is the central knob — all RAG params read via `env()` with sensible defaults:
- `RAG_EMBEDDING_PROVIDER` / `RAG_LLM_PROVIDER` — `openai`, `ollama`, `gemini`, `claude`, or `deepseek`
- `RAG_VECTOR_DRIVER` — only `pgsql` is implemented
- Provider-specific model, dimensions, batch size, cache TTL, timeouts, search mode, chunk params

**Either** `OPENAI_API_KEY` (OpenAI), `GEMINI_API_KEY` (Gemini), `CLAUDE_API_KEY` (Claude), `DEEPSEEK_API_KEY` (DeepSeek), **or** a local Ollama instance is required, depending on the provider setting. The AiModel registry (`ai_models` table) can override the global provider per-document.

---

## Conventions

- `declare(strict_types=1);` at top of every PHP file.
- ULID PKs everywhere — use `HasUlids` on new models.
- No DB-level FK constraints; enforce in service code.
- All provider calls (OpenAI, Ollama, Gemini, Claude, DeepSeek) go through raw curl in provider classes — keep new providers behind `EmbeddingProviderInterface` / `LLMProviderInterface`.
- Embeddings are MD5-cached (24h). Always go through `EmbeddingService`.
- Documents have `embedding_model_id` (FK to `ai_models`) and `embedding_model` (model name string). `ReEmbedDocumentJob` respects these per-document settings.
- FTS uses `plainto_tsquery('english', ...)` and `to_tsvector('english', ...)`. The `simple` config was replaced because it lacks stopword removal and stemming.
- New module: create directory under `modules/`, add PSR-4 entry in `composer.json`, register `ServiceProvider` in `config/app.php`, run `composer dump-autoload`.

---

## Code Documentation Standards

Every class, method, function, interface, and trait must have a PHPDoc (PHP) or JSDoc (TS/JS) block. **No exceptions.** Use `./vendor/bin/pint` (PHP) or `eslint` (JS/TS) to verify formatting.

### Requirements:
1.  **Title** (single line): Short name of what it is.
2.  **Detailed Description** (1-3 paragraphs): What it does, when to use it, side effects.
3.  **Parameters**: `@param {type} $name Description. Example: {example}`.
4.  **Return Type**: `@return {type} Description. Example: {example}`.
5.  **Exceptions**: `@throws {ExceptionClass} Description of when it's thrown. Example: {example}`.

### Examples:

#### PHP — Class
```php
/**
 * RAG Pipeline Service
 * 
 * Orchestrates the full RAG flow: embedding → vector search → filtering → LLM completion.
 * Entry point for both synchronous (ask) and streaming (askStream) question answering.
 * All external dependencies (embedder, vector store, LLM) are injected via constructor.
 *
 * @param EmbeddingServiceInterface $embedder Converts question text to vector. Example: mock(EmbeddingServiceInterface::class)
 * @param VectorStoreInterface $vectorStore Performs hybrid or pure vector search. Example: mock(VectorStoreInterface::class)
 * @param LLMServiceInterface $llm Generates natural-language answers from context. Example: mock(LLMServiceInterface::class)
 * @param ProviderFactory $providerFactory Creates provider instances per model config. Example: mock(ProviderFactory::class)
 * @param CacheRepository $cache Redis/Memcached for embedding cache. Example: $app->make(CacheRepository::class)
 * @param TermAliasServiceInterface $termAliasService Expands aliases in search queries. Example: mock(TermAliasServiceInterface::class)
 * @param int $topK Number of chunks to retrieve. Example: 5
 * @param float $similarityThreshold Minimum similarity for chunk inclusion. Example: 0.65
 * @param int $maxQuestionLength Truncation limit for long questions. Example: 1000
 * @param int $maxMessagesPerSession Hard limit per session. Example: 100
 * @param string $searchMode "hybrid" or "vector". Example: "hybrid"
 * @param bool $queryExpansionEnabled Enable LLM-based query reformulation. Example: false
 * @param int $numExpansionQueries Number of reformulated queries. Example: 3
 * @param bool $mmrEnabled Enable MMR diversity reranking. Example: true
 * @param float $mmrLambda MMR diversity/lambda trade-off. Example: 0.7
 * @param int $maxTokens Max generation tokens. Example: 4096
 * @param string|null $userId Authenticated user ULID. Example: "01J..."
 * @param string|null $activeEmbeddingModelId Override embedding model ULID. Example: "01J..."
 * @param string|null $activeLlmModelId Override LLM model ULID. Example: "01J..."
 * 
 * @throws InvalidArgumentException If question is empty or exceeds maxQuestionLength
 * @throws RuntimeException If no documents are found matching the query
 */
class RAGPipelineService implements RAGPipelineServiceInterface
```

#### PHP — Method
```php
/**
 * Answer a question synchronously
 * 
 * Embeds the question, searches for relevant chunks, filters by threshold,
 * applies dynamic threshold (elbow method) when possible, then calls the LLM.
 * Persists both user and assistant messages to the session.
 *
 * @param string $question The user's natural-language question. Example: "What is Project Orion?"
 * @param array $options Optional overrides. Example: ["session_id" => "01J...", "document_filter" => ["project" => "Orion"]]
 * 
 * @return array{message: array, session_id: string, sources: array} The assistant response with sources
 *   Example: ["message" => ["role" => "assistant", "content" => "Project Orion is...", "sources" => [...]], "session_id" => "01J..."]
 * 
 * @throws InvalidArgumentException When $question is empty or exceeds maxQuestionLength
 *   Example: $service->ask('') → InvalidArgumentException("Question cannot be empty")
 * @throws RuntimeException When filtered search returns no chunks and no fallback is possible
 *   Example: $service->ask('xyznonexistent') → RuntimeException("No documents found")
 */
public function ask(string $question, array $options = []): array
```

#### JS/TS — Service method
```typescript
/**
 * List term aliases with optional pagination and type filter
 *
 * Fetches aliases from the backend. When page/perPage are provided, returns
 * paginated results with meta (current_page, last_page, total, etc.).
 *
 * @param {string} [type] - Filter by alias type: "project" | "technical" | "general". Example: "project"
 * @param {number} [page] - Page number (1-based). Example: 1
 * @param {number} [perPage] - Items per page (server caps at 100). Example: 20
 * @returns {Promise<ApiResponse<TermAlias[]>>} Response with data array and optional meta
 *   Example: { success: true, data: [{ id: "01J...", alias: "အိုရီယွန်", ... }], meta: { current_page: 1, last_page: 3, total: 50 } }
 */
async getAll(type?: string, page?: number, perPage?: number): Promise<ApiResponse<TermAlias[]>>
```

#### Vue — Component
```typescript
/**
 * Term Aliases Page
 * 
 * Full CRUD management for term alias mappings. Displays aliases in a paginated
 * table with inline edit row and a top-of-page create form. Supports create,
 * update, and delete with confirmation dialog. Resets to page 1 after mutations.
 *
 * @prop {void} - This component is route-driven, no props
 * @emits {void} - All side-effects are store/service calls
 */
defineProps<{ /* route-driven, no props */ }>()
```

---

## Known doc/code drift

- `config/modules.php` has all 7 modules `enabled` — but no code reads this flag at runtime.
- Per-module `AGENTS.md` files exist but may be stale — verify against code.
- `.env.example` defaults to OpenAI models; `config/rag.php` defaults to Ollama. The AiModel registry in the DB is the source of truth for which provider/model is actually active.
- Older docs (`BUSINESS_LOGIC.md`, `PROJECT_STRUCTURE.md`, `PROJECT_ROUTES.md`) predate several refactors. Routes, services, and migrations are the executable truth.
