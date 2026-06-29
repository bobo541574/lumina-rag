# AGENTS.md

Compact reference for AI agents. **Trust code over config, config over docs** when they disagree.

---

## Commands

| Action | Command |
|--------|---------|
| Dev (4 concurrent: serve + queue + logs + Vite) | `composer run dev` |
| Run all tests | `composer run test` (clears config, then `artisan test` via Pest) |
| Single test | `php artisan test --filter=TestName` |
| Single suite | `php artisan test --testsuite=Unit` or `--testsuite=Feature` |
| Format PHP | `./vendor/bin/pint` (add `--dirty` for changed-only) |
| Seed models | `php artisan db:seed` |
| Re‑embed all chunks (regenerates vectors) | `php artisan rag:reembed` |
| Re‑embed one document | `php artisan rag:reembed --document={ulid}` |
| Frontend build | `npm run build` |
| Frontend dev | `npm run dev` |
| Install npm (skip postinstall scripts) | `npm install --ignore-scripts` |

Tests use SQLite `:memory:`, `QUEUE_CONNECTION=sync`. `tests/Pest.php` applies `RefreshDatabase` to Feature suite only. **No ESLint or typecheck scripts exist** — PHPDoc/JSDoc is convention-only, not tool-enforced.

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

**`max_tokens` is NOT a column on `ai_models`** — it lives inside the `settings` JSONB column (read via `$model->settings['max_tokens']`).

---

## RAG pipeline

`modules/ChatModule/Services/RAGPipelineService.php` orchestrates 7 steps:

1. **Rewrite** — `QueryRewriterService::rewrite()` scores complexity (word count, negation, logical ops, ambiguous terms). SIMPLE (score < 5): rule-based rewrite with boolean FTS. COMPLEX (≥ 5): same + LLM `expandQuery()`. Emits `rewrite_info` SSE event.
2. **Embed** — `EmbeddingService::embed()` (MD5-cached 24h). Uses active embedding AiModel's provider columns.
3. **Search** — `VectorStoreService::searchHybrid()` — vector cosine + FTS fused by reciprocal rank fusion. FTS uses `to_tsquery('english', ...)` with `& | !` operators when boolean query available; falls back to `plainto_tsquery`. Non-ASCII/date/numeric tokens stripped from boolean FTS.
4. **Filter** — drops chunks below `similarity_threshold` (default 0.65). None survive → refusal, no LLM call.
5. **Context** — top chunks truncated to active LLM model's `max_context_tokens`. Ollama receives `num_ctx` from this value.
6. **Answer** — `LLMService::complete()` with `temperature` from AiModel column. `think` param auto-disabled for Qwen reasoning models (can override via request or AiModel settings).
7. **Persist** — user + assistant `chat_messages` saved to `chat_session`.

Pipeline helpers in `modules/ChatModule/Services/Pipeline/`: `FilterExtractor`, `FtsQueryBuilder`, `ChunkProcessor`, `ResponseBuilder`, `SessionManager`, `QueryRewriterService`, `RewrittenQuery`.

Document upload: `DocumentService::upload()` → dispatch `ProcessDocumentJob` (queue) → extract text (`smalot/pdfparser`, `phpoffice/phpword`) → chunk (recursive char splitter, 1000/200) → batch-embed via document's `embedding_model_id` AiModel → upsert vectors → mark `completed`.

---

## API

All routes under `api/`. Auth: custom `auth.token` middleware, 80-char hex token, `Authorization: Bearer` header. Response envelope: `{ success, message, data, errors }`.

| Group | Endpoints |
|-------|-----------|
| Auth | `POST /api/auth/{register\|login\|logout}`, `GET /api/auth/me` |
| Chat | `POST /api/chat`, `GET/DELETE /api/chat/sessions[/{ulid}]` |
| Documents | `GET /api/documents`, `POST /api/documents`, `GET /api/documents/{ulid}[/status]`, `POST /api/documents/{ulid}/retry`, `PUT /api/documents/{ulid}`, `DELETE /api/documents/{ulid}` |
| AiModels | `CRUD /api/settings/ai-models[/{id}]` |
| TermAliases | `CRUD /api/settings/term-aliases[/{id}]` |

---

## Database quirks

- All PKs are **ULIDs** (`HasUlids` trait, `->whereUlid()` route binding).
- **No DB-level FK constraints** — enforced in application code.
- pgvector shard tables (`ve_{384,768,1024,1536,3072}`) created conditionally; skipped on SQLite.
- `ai_models` columns: `id, name, type, provider, model, api_key, base_url, collection, dimensions, batch_size, cache_ttl, temperature, max_context_tokens, timeout, description, settings` (JSONB), `is_active, sort_order`.
- `vector_embeddings` table is metadata-only (no embedding column) on pgvector; the `embedding` column exists only on SQLite fallback.

---

## Known doc/code drift

- `.env.example` defaults to OpenAI; `config/rag.php` defaults to Ollama. The AiModel registry in `ai_models` table is the source of truth.
- `config/modules.php` has all 7 modules enabled — **contradicts older docs** claiming it's unused (all 7 SPs read it).
- `BUSINESS_LOGIC.md`, `PROJECT_STRUCTURE.md`, `PROJECT_ROUTES.md` predate several refactors — routes, services, and migrations are the executable truth.
