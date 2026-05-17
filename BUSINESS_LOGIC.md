# Business Logic & Domain Rules

System purpose — intelligent document Q&A using **Retrieval-Augmented Generation (RAG)**. Users upload documents, ask natural-language questions, and receive sourced answers grounded in the document corpus.

> Rules in this document are derived from the current source code in `modules/`. When code and this document disagree, the code wins — open a PR to update this file.

---

## Document Processing Domain

### Upload validation rules

Enforced in [UploadDocumentRequest.php](modules/DocumentModule/Requests/UploadDocumentRequest.php).

- **Allowed formats**: PDF, DOCX, TXT, CSV, Markdown (extension-based check).
- **Maximum file size**: **50 MB** (51 200 KB — `max:51200` in the FormRequest).
- **Required field**: `file`.
- **Optional fields**:
  - `title` — overrides the filename-derived title (max 255 chars).
  - `embedding_model` — free-form embedding model name override.
  - `embedding_model_id` — ULID of an `ai_models` row of `type = embedding`. Validated with `exists:ai_models,id`.
- **Duplicate detection**: SHA-256 file hash matched against `documents.file_hash` (unique index). On match → HTTP 409 with the existing document body.
- **File integrity**: file must be readable; non-extractable PDFs (image-only) flagged as `failed` with `error_message`.

### Text extraction

| Format | Extractor | Notes |
|--------|-----------|-------|
| PDF | `smalot/pdfparser` | Preserves page boundaries; sets `page_number` on each chunk. |
| DOCX | `phpoffice/phpword` | Linearizes structure; heading hierarchy not currently preserved. |
| TXT, CSV, Markdown | direct file read | UTF-8 with auto-detect fallback. |

Empty extraction result → document marked `failed`, `error_message` populated.

### Chunking strategy

Implemented in [TextChunkingService.php](modules/DocumentModule/Services/TextChunkingService.php).

- **Algorithm**: Recursive Character Text Splitter.
- **Chunk size**: 1 000 characters (`config('rag.chunking.chunk_size')`).
- **Overlap**: 200 characters (`config('rag.chunking.overlap')`).
- **Separator priority**: paragraph break (`\n\n`) → line break (`\n`) → sentence end (`.`) → comma → space → character.
- **Per-chunk metadata stored**: `chunk_index`, `page_number` (PDF only), `char_start`, `char_end`. Unique index on `(document_id, chunk_index)`.

### Embedding generation

Implemented in [EmbeddingService.php](modules/EmbeddingModule/Services/EmbeddingService.php).

- **Default model**: Ollama `nomic-embed-text:latest` (768 dims) or the AiModel registry's active embedding model. Override per-upload via `embedding_model_id`.
- **Provider**: Ollama (default seeder) or OpenAI. Both implemented behind `EmbeddingProviderInterface`.
- **Batch size**: 100 texts per provider call (`config('rag.embedding.batch_size')`).
- **Cache**: MD5 hash of `(model + text)` → vector, TTL 24 h (`config('rag.embedding.cache_ttl')`).
- **Job retries**: `ProcessDocumentJob` has `tries = 3` with exponential backoff (Laravel default).
- **Failure handling**: on terminal failure, document status → `failed`, `error_message` populated, vectors are not partially persisted (job is transactional per chunk batch).
- **Manual retry**: `POST /api/documents/{id}/retry` re-dispatches `ProcessDocumentJob`. Permitted only for `status = failed` documents.

### Document status lifecycle

```
pending  ──(job picked up)──→ processing ──(success)──→ completed
                                          ──(failure ×3)─→ failed ──(retry)──→ pending
```

`status` column on `documents`. The frontend [DocumentList.vue](resources/js/components/DocumentList.vue) renders status as a colored badge (`warning` / `brand` / `success` / `danger`) and shows a Retry icon-button only on `failed` rows.

### Document update + delete

- **Update** (`PUT /api/documents/{id}`): mutable fields include `title`, `description` (Trix HTML), `report_date` (date), and `project` (string). `report_date` and `project` are sortable, filterable metadata. Rich-text description is rendered with `v-html` in the list view — server-side sanitization is the responsibility of `DocumentResource` (track if not yet implemented).
- **Delete** (`DELETE /api/documents/{id}`): soft-delete on `documents`, cascading delete on `document_chunks`, `vector_embeddings` (metadata), and the row in the matching `ve_{dim}` shard table — via service code (no DB-level FK cascade).
- **Bulk delete**: no dedicated endpoint — frontend issues parallel `DELETE` calls via `Promise.allSettled` and reports per-item success/failure via toast.

---

## Question-Answer Domain

### RAG pipeline

Orchestrated in [RAGPipelineService.php](modules/ChatModule/Services/RAGPipelineService.php).

1. **Validation**:
   - Empty question → `InvalidArgumentException`.
   - Question > `config('rag.chat.max_question_length')` (1 000 chars) → truncated.
2. **Auto-filters**: `extractFiltersFromQuestion()` detects user names, project names, date ranges (including `YYYY-MonthName` and `MonthName DD` patterns) from the question text and applies them as SQL filters (user_id, project, report_date range) to both vector and FTS queries — not as FTS search terms.
3. **Question embedding**: same model the matching documents were embedded with (per-document override falls back to AiModel registry or config default).
4. **FTS query refinement**: `refineFtsQuery()` strips detected filter terms (user, project, date) and stopwords from the FTS query so they don't compete with content terms. Date patterns (`YYYY-MM-DD`, `YYYY-MM`, `YYYY-MonthName`) are stripped before individual year removal to avoid bare `-MM` fragments being interpreted as PostgreSQL FTS negation operators.
5. **Vector search**: top-K (default 5) chunks via cosine distance. Initial threshold lowered to `min(0.65, 0.20)` to cast a wider net; final threshold applied post-fusion.
6. **Search modes** (`config('rag.search.mode')`):
   - `vector` — pure cosine similarity.
   - `fts` — full-text search (PostgreSQL `tsvector` with `english` config).
   - `hybrid` (default) — vector + FTS in parallel, fused via Reciprocal Rank Fusion (RRF) with scores normalised to 0–1.
7. **MMR re-ranking** (`config('rag.search.mmr.enabled')`, default `true`): Maximal Marginal Relevance to reduce redundancy among top-K results. Tuned by `lambda` (default 0.7).
8. **Query expansion** (`config('rag.search.query_expansion.enabled')`, default `false`): generates additional query variants via the LLM, runs each, merges results.
9. **Threshold handling** (`applyDynamicThreshold()`): starts from a low floor (`0.20`) and applies an elbow-detection method to find the natural drop-off point in similarity scores, capped at the configured `similarity_threshold` (default 0.65). If all chunks score below the floor, a single best chunk survives as a safety valve.
10. **Context assembly**: chunks sorted by score desc, concatenated with source labels, truncated to `config('rag.llm.max_context_tokens')` (4 000 tokens default).
11. **LLM completion**: provider per AiModel registry (Ollama default). Temperature 0.3 default.
12. **Streaming**: SSE with `chunk`, `sources`, `status` (embedding/searching/generating), and `done` (with tokens_used/search_time_ms/llm_time_ms/total_time_ms metadata) events. Frontend uses `fetch` with `ReadableStream` reader and automatic retry (up to 3 attempts with exponential backoff). The **Stop** button calls `store.abortStream()`.
13. **Persistence**: user message + assistant message saved to `chat_messages`; `sources[]` stored as jsonb on the assistant message.

### Source citation format

Returned on every answer (and on the final SSE event in streaming mode):

```json
{
  "document_id": "01HX...",
  "document_title": "Q3 Financial Report",
  "chunk_index": 12,
  "page_number": 7,
  "similarity_score": 0.89,
  "excerpt": "…relevant snippet…"
}
```

### Filters (chat request)

Optional fields on `POST /api/chat`:

| Field | Effect |
|-------|--------|
| `document_ids[]` | Restrict search to specific documents |
| `date_from`, `date_to` | Restrict to documents uploaded in date range |
| `llm_model_id` | ULID of an `ai_models` row of `type = llm` to use for this query |

The frontend ChatInterface filter chips bind these. Active filter count appears next to the "Search Filters" toggle button.

---

## Chat Session Domain

### Session lifecycle

- **Auto-created** on first message if no `session_id` provided.
- **Title** — set from the **assistant's first response** (first 50 chars). Not from the user's question.
- **Message limit**: `config('rag.chat.max_messages_per_session')` (100). 101st message → `RuntimeException("Session full")`.
- **Archival**: `is_archived` boolean on `chat_sessions`. (No automatic archival job currently — flag is set manually.)
- **Soft delete**: deleted sessions are inaccessible (404 on `GET /api/chat/sessions/{id}`); messages cascade-soft-delete via service code.

### Message ownership

- **User scoping**: `chat_sessions.user_id` is set from the authenticated user. Cross-user access returns 404 (not 403, to avoid existence enumeration).
- **Immutability**: messages are not editable — only soft-deletable (handled at the session level only).

### Streaming caveats

- Default behavior is non-streaming. Streaming is opt-in via `stream: true` in the request body.
- If the SSE connection drops mid-stream, the partial assistant message is **persisted as-is**.
- The `Stop` button (visible only while streaming) calls `AbortController.abort()` on the EventSource — backend sees a closed connection and stops generating.

---

## Authentication Domain

Implemented in [AuthService.php](modules/UserModule/Services/AuthService.php).

### Registration

- Required: `name`, `email`, `password` (min 8 chars).
- `email` is unique across `users`.
- `password` is bcrypt-hashed before storage.
- Returns `{ user, token }` — token is an 80-char hex string (`bin2hex(random_bytes(40))`).

### Login

- Required: `email`, `password`.
- **Throttle**: 5 attempts per 60 seconds (`throttle:5,60` middleware).
- Invalid credentials → generic `"Invalid email or password"` (no enumeration).
- **Each successful login generates a new token**, invalidating the previous one.

### Token semantics

- Stored on `users.api_token` (unique, nullable).
- Sent as `Authorization: Bearer <token>` header.
- No expiry — the token is valid until logout (which sets it to `null`) or replaced by a fresh login.

### Per-field server validation errors

Both `/register` and `/login` return Laravel's standard `errors: { field: [messages] }` shape on 422. The frontend ([RegisterPage.vue](resources/js/pages/RegisterPage.vue)) renders **the first message per field beneath the relevant input** (with `aria-invalid` + `aria-describedby`), not as a single banner.

---

## AI Model Registry

The `ai_models` table is a registry of available embedding + LLM endpoints. Each row encapsulates a complete provider configuration: provider type, model name, API key, base URL, dimensions/timeout/etc.

- Multiple models can be registered. `is_active` marks the default for that type.
- **Per-document embedding selection**: on upload, user picks an `embedding_model_id` (or accepts the default).
- **Per-query LLM selection**: on chat, user picks an `llm_model_id` (or accepts the default).
- **Documents stay linked to the model that embedded them** (`embedding_model_id` foreign key). Deleting a model from the registry **does not** remove the embeddings — existing documents continue to work.

### Frontend split

The AI model UI is now split across two routes (changed in recent refactor):

- `/settings/ai-models` — list view ([AiModelsPage.vue](resources/js/pages/AiModelsPage.vue))
- `/settings/ai-models/new` and `/settings/ai-models/:id/edit` — form ([AiModelManager.vue](resources/js/pages/AiModelManager.vue))

The form pre-fills all fields from the existing model on edit **except the API key**, which is left blank with a placeholder ("leave blank to keep existing"). Empty submissions during edit omit `api_key` from the payload.

---

## Term Alias Registry

The `term_aliases` table stores `alias → canonical` mappings with types: `project`, `technical`, `general`.

- **`TermAliasService`** caches the alias map in Redis (24h TTL).
- **`expandText()`** appends canonical terms to the question text before embedding (vector search).
- **`expandFtsQuery()`** adds `OR canonical` to each matching term in the FTS query.
- Both are called automatically in `RAGPipelineService::ask()` and `askStream()` — no user action needed.
- Example: searching "အိုရီယွန်" automatically also searches for "Orion".
- CRUD available at `/api/settings/term-aliases` and via UI at `/settings/term-aliases`.
- Seeded with 19 default aliases (Burmese → English project names, technical terms, abbreviations).

### Pagination

All list endpoints return paginated responses with `{ data, meta }`. Client sends `?page=` and `?per_page=`, server caps `per_page` at `config('rag.pagination.max_per_page')` (default 100) to prevent abuse.

### Template files

Downloadable report templates are available at `public/templates/` (5 report types × 4 formats each). The `php artisan rag:generate-templates` command regenerates `.docx` files from `.md` sources.

---

## Edge Cases & Error Handling

### Document processing

| Case | Behavior |
|------|----------|
| File > 50 MB | Rejected at upload (422) — no DB row created |
| Image-only PDF | Extraction returns empty → status `failed`, error_message set |
| Single document, 1 000 chunks | Processed in batches of 100 via the queue |
| Embedding API down | Job retries 3× with backoff, then marks document `failed` |
| Duplicate upload | 409 with the existing document body |

### Query

| Case | Behavior |
|------|----------|
| Empty question | 422 validation error |
| Question > 1 000 chars | Truncated server-side (frontend also enforces `maxlength=1000` + char counter) |
| No documents in system | LLM still called with empty context — answer typically says it lacks information |
| All chunks below threshold | `"I cannot answer this question based on the available documents."` (no LLM call) |
| Filter excludes all docs | Same as above |

### Sessions

| Case | Behavior |
|------|----------|
| Missing `session_id` on chat | New session auto-created |
| Soft-deleted session referenced | 404 on subsequent reads |
| 101st message in a session | 422 with "Session full, start a new chat" |
| Session belongs to another user | 404 (not 403) |

---

## Observability

### Logging

- Channel: configurable via `config('rag.logging.channel')` (default `rag`).
- Level: `config('rag.logging.level')` (default `info`).
- Structured JSON-friendly fields on every CRUD operation: actor (user_id), entity, entity_id, action, result.

### Key events logged

| Event | Level |
|-------|-------|
| Document uploaded / processed / failed | INFO / WARNING / ERROR |
| Chat session created / message saved | INFO |
| Embedding cache hit / miss | DEBUG (when `level=debug`) |
| OpenAI / Ollama API call latency | INFO |
| Vector search query duration | INFO |

### Metrics worth watching

- Document processing time (upload → completed).
- Embedding API error rate.
- pgvector search query time (p50/p95).
- LLM latency.
- Chat session activity per user.

---

## What's intentionally not in this document

- Coding standards → see [PROJECT_RULES.md](PROJECT_RULES.md).
- Module structure / file layout → see [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md).
- Routes → see [PROJECT_ROUTES.md](PROJECT_ROUTES.md).
- Setup → see [SETUP_GUIDE.md](SETUP_GUIDE.md).
- Database schema → see [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md).
- Deployment → see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).
