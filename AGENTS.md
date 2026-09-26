# AGENTS.md

Compact reference for AI agents. **Trust code over config, config over docs** when they disagree.

---

## Commands

| Action | Command |
|--------|---------|
| Dev (4 concurrent: serve + queue + logs + Vite) | `composer run dev` |
| Run all PHP tests | `composer run test` (clears config, then `artisan test` via Pest) |
| Single test | `php artisan test --filter=TestName` |
| Single suite | `php artisan test --testsuite=Unit` or `--testsuite=Feature` |
| Frontend tests | `npm run test` (Vitest; `--watch` for watch mode) |
| Format PHP | `./vendor/bin/pint` (add `--dirty` for changed-only) |
| Seed models | `php artisan db:seed` |

Tests use SQLite `:memory:`, `QUEUE_CONNECTION=sync`. `tests/Pest.php` applies `RefreshDatabase` to Feature suite only. **No ESLint/typecheck gating exists** — PHPDoc/JSDoc is convention-only, not tool-enforced.

`rag:reembed` batch selectors (all dispatch `ReEmbedDocumentJob`): `--document={ulid}`, `--missing` (chunks lacking vectors), `--only-old-model`, `--status=`, `--project=`, `--report-date=` (exact/`YYYY-MM`/`from:to`), `--dry-run`, `--force`. Purge stale sessions: `php artisan chat:cleanup [--dry-run]` (scheduled daily).

---

## Architecture — key facts that matter

**7 modules**, all in `modules/{Name}Module/`, PSR-4 in `composer.json`. Service providers are registered manually in `config/app.php` via `ServiceProvider::defaultProviders()->merge([...])` — **no auto-discovery**.

`config/modules.php` **IS** read at runtime — all 7 SPs check `config('modules.modules.{key}.enabled', true)` in their `boot()` method. (Contradicts older docs in the repo.)

### Module directory reality (not all have the full structure)

| Module | Has Controllers? | Has Models? | Has Routes? |
|--------|-----------------|-------------|-------------|
| ChatModule, DocumentModule, SettingsModule, UserModule | ✓ | ✓ | ✓ |
| VectorStoreModule | ✗ | ✓ | ✗ |
| EmbeddingModule, LLMModule | ✗ | ✗ | ✗ |

### Request flow
```
Request → Controller (validates) → Service (business logic, touches Models) → Response
```
No Repository layer. Services call Eloquent directly. Models are in module `Models/` dirs except `User` (`app/Models/User.php`).

**Migrations live in `modules/{Module}/database/migrations/`** (each SP calls `loadMigrationsFrom(...)` in `boot()`). The root `database/migrations/` only holds the 3 stock Laravel tables (users, cache, jobs). Don't look for schema changes there.

### Module dependencies
```
ChatModule       → EmbeddingModule + VectorStoreModule + LLMModule
DocumentModule   → EmbeddingModule + VectorStoreModule
SettingsModule   → standalone (AiModel + TermAlias registry)
UserModule       → standalone (token auth)
EmbeddingModule, VectorStoreModule, LLMModule → leaf modules
```

---

## Config priority — a common trap

**API request params > AiModel DB columns > `config/rag.php` > env defaults**

`RAGPipelineService` constructor overrides both `$this->llm` and `$this->embedder` with providers built from the active AiModel's DB record via `ProviderFactory`. Service provider bindings are only fallbacks — the active model's columns (`timeout`, `model`, `base_url`, `api_key`, `temperature`, `dimensions`, `batch_size`, etc.) always win.

Service provider fallbacks are built the same way: `ProviderFactory` + `AiModel::fromConfig('llm'|'embedding')` (an unsaved model whose null columns fall back to `config/rag.php`). `RAG_*_BASE_URL` is the Ollama server URL only; `openai`-provider rows default to `https://api.openai.com/v1` and honour their own `base_url` (any OpenAI-compatible host). Provider HTTP: non-streaming calls go through `App\Support\HttpJsonClient::post()` (retries 5xx/transport errors per `rag.http.retry_backoff_ms`, fails fast on 4xx); streaming goes through the `StreamsHttpLines` trait (throws on non-200). Embedding providers extend `AbstractEmbeddingProvider` and only implement `requestBatch()`; `DeepSeekLLMProvider` is `OpenAILLMProvider` with DeepSeek defaults.

**`max_tokens` is NOT a column on `ai_models`** — it lives inside the `settings` JSONB column (read via `$model->settings['max_tokens']`).

---

## RAG pipeline

`modules/ChatModule/Services/RAGPipelineService.php` orchestrates the flow (simplified to core steps):

1. **Pre-process** — `normalizeQuestion()` → `QuestionClassifier` (factual/analytical/comparative/generic) → `SessionManager::resolveSession()` → `FilterExtractor::extract()` (user/project/date/Burmese month) + follow-up inheritance → `applyTenantIsolation()` (RAG_TENANT_ISOLATION) → model auto-select.
2. **Rewrite** — `QueryRewriterService::rewrite()` scores complexity (word count, negation, logical ops, ambiguous terms). SIMPLE (score < 5): rule-based rewrite with boolean FTS. COMPLEX (≥ 5): same + LLM `expandQuery()`. Emits `rewrite_info` SSE event.
3. **Semantic cache** — `SemanticCacheService` (cosine ≥ 0.95 hit, scoped by `knowledge version + user_id + model_hash`) returns a cached answer and skips retrieval + LLM. Skipped when the session has history.
4. **Embed** — `EmbeddingService::embed()` (MD5-cached 24h). Uses active embedding AiModel's provider columns.
5. **Search** — `VectorStoreService::searchHybrid()` — vector cosine + FTS fused by reciprocal rank fusion. FTS uses `to_tsquery('english', ...)` with `& | !` operators when boolean query available; falls back to `plainto_tsquery`. Non-ASCII/date/numeric tokens stripped from boolean FTS.
6. **Filter** — `ChunkProcessor::applyDynamicThreshold()` (elbow-method gap detection, `filtered_base` when filters active, fallback safety valve), then optional MMR diversity (`applyMMR`, λ=0.7) and optional cross-encoder `RerankerService` (bge-reranker, default off). None survive → refusal, no LLM call.
7. **Context** — top chunks truncated to active LLM model's `max_context_tokens`. Ollama receives `num_ctx` from this value. `reorderForLostInTheMiddle()`, confidence/old-doc notes, history window (4), "Search Scope" note.
8. **Answer** — `LLMService::complete()` with `temperature` from AiModel column. `think` param auto-disabled for Qwen reasoning models (can override via request or AiModel settings).
9. **Persist** — user + assistant `chat_messages` saved to `chat_session`, then `writeSemanticCache()`.

Pipeline helpers in `modules/ChatModule/Services/Pipeline/`: `FilterExtractor`, `FtsQueryBuilder`, `ChunkProcessor`, `ResponseBuilder`, `SessionManager`, `QueryRewriterService`, `RewrittenQuery`.

Document upload: `DocumentService::upload()` → dispatch `ProcessDocumentJob` (queue `document-processing`, 3 tries w/ backoff) → `ProcessDocumentService::process()` extracts text (`smalot/pdfparser`, `phpoffice/phpword`) → chunk (recursive char splitter, 1000/200) → batch-embed via document's `embedding_model_id` AiModel → upsert vectors → mark `completed`. Jobs are thin queue wrappers — business logic lives in services (`ProcessDocumentService`, `ReEmbedDocumentService`). Chunk rows are built/inserted only via `ChunkWriter` (also used by `ReportDemoSeeder`), and vectors only via `ChunkEmbedder` (`embed()` then `store()`; re-embed swaps inside a transaction so a provider failure keeps the old vectors). `ProcessDocumentJob` marks a document `failed` only on its last attempt. Vector-store code must not query missing `ve_*` shard tables (use `PgvectorDriver::shardTables()`): on Postgres a failed statement aborts the surrounding transaction.

---

## API

All routes under `api/`. Auth: custom `auth.token` middleware (alias in `bootstrap/app.php`) — **tokens are stored as SHA-256 digests** (`App\Support\ApiToken::hash()`), raw 80-char hex token is generated fresh on login and never persisted (`ApiToken::make()`). Expiry + sliding renewal via `api_token_expires_at` (`RAG_API_TOKEN_TTL_DAYS`, default 30). Response envelope: `{ success, message, data, meta, errors }` — build it with `App\Http\Responses\ApiResponse` (never hand-roll `response()->json`). `ApiExceptionRenderer` (registered in `bootstrap/app.php`) renders `ValidationException` → 422 with `errors` and `ModelNotFoundException` → 404 `"<Model> not found."` on `api/*`, so controllers don't catch those. Ownership: `Document::ownedBy()` / `ChatSession::ownedBy()`; service methods take a non-null `$userId`. Document status is the `DocumentStatus` enum (cast on `Document`), with transitions via `markProcessing/markCompleted/markFailed/markPendingRetry`.

| Group | Endpoints |
|-------|-----------|
| Auth | `POST /api/auth/{register\|login\|logout}`, `GET /api/auth/me`, `DELETE /api/auth/me` (account deletion) |
| Chat | `POST /api/chat`, `GET/DELETE /api/chat/sessions[/{ulid}]` |
| Documents | `GET /api/documents`, `POST /api/documents`, `GET /api/documents/{ulid}[/status]`, `POST /api/documents/{ulid}/retry`, `PUT /api/documents/{ulid}`, `DELETE /api/documents/{ulid}` |
| Settings | `CRUD /api/settings/ai-models[/{id}]`, `CRUD /api/settings/term-aliases[/{id}]` — both gated by `auth.token` **+** `auth.admin` (`EnsureAdmin`) |

Settings routes are admin-only; the seeded `admin@lumina.test` user carries `is_admin=true`. Security-relevant events (login/logout/failed auth/account deletion) go to the dedicated `security` log channel (`storage/logs/security.log`).

---

## Database quirks

- All PKs are **ULIDs** (`HasUlids` trait, `->whereUlid()` route binding).
- **No DB-level FK constraints** — enforced in application code.
- **Migrations live in `modules/{Module}/database/migrations/`**, loaded via `loadMigrationsFrom()` in each module's SP `boot()`. Root `database/migrations/` only holds the 3 stock Laravel tables (users, cache, jobs).
- pgvector shard tables (`ve_{384,768,1024,1536,3072}`) created conditionally; skipped on SQLite. On SQLite, `vector_embeddings` carries a real `embedding` column as fallback.
- `ai_models.api_key` is **encrypted at rest** via `App\Casts\EncryptedString` (ISO 27002:8.24) — `null`/`''` values passed through untouched; undecryptable legacy values returned verbatim. Settings responses expose only a `has_api_key` boolean (model's `api_key` is `$hidden`).
- `rag:reembed` only re-embeds completed documents by default; use `--missing`/`--status`/`--force` to widen. `--document={ulid}` targets one, `--dry-run` is the safe preview.
- `ai_models` columns: `id, name, type, provider, model, api_key, base_url, collection, dimensions, batch_size, cache_ttl, temperature, max_context_tokens, timeout, description, settings` (JSONB), `is_active, sort_order`.
- `vector_embeddings` table is metadata-only (no embedding column) on pgvector; the `embedding` column exists only on SQLite fallback.

---

## Known doc/code drift

- `.env.example` defaults to OpenAI; `config/rag.php` defaults to Ollama. The AiModel registry in `ai_models` table is the source of truth.
- `config/modules.php` has all 7 modules enabled — **contradicts older docs** claiming it's unused (all 7 SPs read it).
- `BUSINESS_LOGIC.md`, `PROJECT_STRUCTURE.md`, `PROJECT_ROUTES.md` predate several refactors — routes, services, and migrations are the executable truth.
- **Per-module `modules/*/AGENTS.md` files exist but are stale** — they don't reflect digest-only token storage, the Job→Service refactor, or the semantic-cache/reranker/tenant-isolation layers. Use them for API shape only; trust this file + code for behavior.
