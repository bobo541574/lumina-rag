# Project Routes

Complete reference for **API routes** (backend) and **SPA routes** (Vue frontend). Authoritative sources:

- API: `modules/{Module}/Routes/*.php`
- SPA: [resources/js/router.ts](resources/js/router.ts)

---

## Conventions

### API
- Base path: `/api`
- All authenticated endpoints use the `auth.token` middleware (custom token auth — **not** Sanctum).
- Token is sent as `Authorization: Bearer <80-char hex>` header.
- All ULID route params use `->whereUlid('id')`.
- Standard response envelope:

```json
{
  "success": true,
  "message": "Human-readable message (optional)",
  "data": { ... },
  "errors": { "field": ["message"] }
}
```

- Validation failures return HTTP 422 with `errors` populated per field.
- Authorization failures return HTTP 401 / 403.

### SPA
- History mode (`createWebHistory`).
- Two route guards:
  - `meta: { auth: true }` — redirects to `/login` when not authenticated.
  - `meta: { guest: true }` — redirects to `/` (chat) when already authenticated.
- Authentication state lives in `useAuthStore()`; the guard waits for `auth.isInitialized` before deciding.

---

## API Routes

### Health

| Method | Path | Notes |
|--------|------|-------|
| GET | `/api/health` | Public — uptime check, returns `{ status: "ok" }` |

### Auth (`UserModule/Routes/auth.php`)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/api/auth/register` | Public | Creates user, returns `{ user, token }` |
| POST | `/api/auth/login` | Public, throttled `5/60` | Returns `{ user, token }`. Login rotates the existing token. |
| POST | `/api/auth/logout` | Token | Nulls the user's token |
| GET | `/api/auth/me` | Token | Returns the current user |

### Chat (`ChatModule/Routes/chat.php`)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| POST | `/api/chat` | Token | Body: `{ question, session_id?, document_ids?, date_from?, date_to?, llm_model_id?, stream? }`. Returns `{ session, message, sources }`. With `stream: true`, responds with `text/event-stream`. |
| GET | `/api/chat/sessions` | Token | List the current user's sessions |
| GET | `/api/chat/sessions/{ulid}` | Token | Session detail with messages |
| DELETE | `/api/chat/sessions/{ulid}` | Token | Soft-delete a session |

### Documents (`DocumentModule/Routes/document.php`)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/documents` | Token | List documents the user owns |
| POST | `/api/documents` | Token | Multipart upload — see [BUSINESS_LOGIC.md](BUSINESS_LOGIC.md#document-upload-rules) |
| GET | `/api/documents/{ulid}` | Token | Document detail |
| GET | `/api/documents/{ulid}/status` | Token | Lightweight status poll (`pending` / `processing` / `completed` / `failed`) |
| POST | `/api/documents/{ulid}/retry` | Token | Re-dispatch `ProcessDocumentJob` for a failed document |
| PUT | `/api/documents/{ulid}` | Token | Update title + description (Trix HTML) |
| DELETE | `/api/documents/{ulid}` | Token | Soft-delete document and its chunks/embeddings |

#### Upload payload

```
POST /api/documents
Content-Type: multipart/form-data

file:                  <PDF / DOCX / TXT / CSV / Markdown — max 50MB>
title:                 (optional) override the filename-derived title
embedding_model:       (optional) free-form model name override
embedding_model_id:    (optional) ULID of an `ai_models` row of type=embedding
```

Duplicate detection: SHA-256 file hash matched against `documents.file_hash`. Returns HTTP 409 with the existing document if matched.

### Settings (`SettingsModule/Routes/settings.php`)

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| GET | `/api/settings` | Token | All settings + their definitions (label, group, type, options) |
| PUT | `/api/settings/bulk` | Token | Body: `{ settings: { key: { value, type? } } }` — atomic update |
| PUT | `/api/settings/{key}` | Token | Single-key update |
| DELETE | `/api/settings/{key}` | Token | Reset a setting to its default |
| GET | `/api/settings/ai-models` | Token | Optional `?type=embedding` or `?type=llm` filter |
| POST | `/api/settings/ai-models` | Token | Create an AI model registry entry |
| GET | `/api/settings/ai-models/{ulid}` | Token | One model |
| PUT | `/api/settings/ai-models/{ulid}` | Token | Update — omit `api_key` to keep existing |
| DELETE | `/api/settings/ai-models/{ulid}` | Token | Remove a model from the registry |

#### AI model payload

```json
{
  "name": "OpenAI text-embedding-3-small",
  "type": "embedding",                 // "embedding" | "llm"
  "provider": "openai",                // "openai" | "ollama"
  "model": "text-embedding-3-small",
  "api_key": "sk-…",                   // required for openai; optional for ollama
  "base_url": null,                    // required for ollama; optional for openai (proxy)
  "collection": "ve_1536",             // pgvector table suffix; "" = auto-detect from dimensions
  "dimensions": 1536,                  // embedding only
  "batch_size": 100,                   // embedding only
  "cache_ttl": 86400,                  // embedding only
  "temperature": null,                 // llm only
  "max_context_tokens": null,          // llm only
  "timeout": 30,
  "description": "<p>HTML from Trix</p>",
  "settings": {                        // optional per-model overrides
    "top_k": 5,
    "search_mode": "hybrid"
  },
  "is_active": true,
  "sort_order": 0
}
```

When updating, **omit `api_key`** to keep the existing value — sending an empty string is treated as "no change". Frontend [AiModelForm.vue](resources/js/components/AiModelForm.vue) enforces this.

---

## SPA Routes (Frontend)

Defined in [resources/js/router.ts](resources/js/router.ts).

| Path | Name | Page component | Guard | Purpose |
|------|------|----------------|-------|---------|
| `/login` | `login` | `LoginPage.vue` | guest | Sign-in form |
| `/register` | `register` | `RegisterPage.vue` | guest | Sign-up form (per-field server errors) |
| `/` | `chat` | `ChatPage.vue` | auth | Chat UI: session sidebar + message thread + question input |
| `/documents` | `documents` | `DocumentsPage.vue` | auth | Document list with search, status tabs, sort, pagination, bulk select |
| `/settings` | `settings` | `SettingsPage.vue` | auth | RAG configuration (key/value editor, grouped) |
| `/settings/ai-models` | `ai-models` | `AiModelsPage.vue` | auth | List of registered embedding + LLM models |
| `/settings/ai-models/new` | `ai-model-create` | `AiModelManager.vue` | auth | Create-model form |
| `/settings/ai-models/:id/edit` | `ai-model-edit` | `AiModelManager.vue` | auth | Edit-model form (`props: true`, fetches model on mount) |

### Header navigation

The authenticated header in [App.vue](resources/js/App.vue) exposes four destinations: Chat, Documents, AI Models, Settings. The active route gets a brand-tinted background. On mobile (< 768px), the desktop nav collapses behind a hamburger; the mobile dropdown auto-closes on route change.

### Cross-page navigation patterns

- **AI Models list → form**: `router.push({ name: 'ai-model-create' })` (Add button) or `router.push({ name: 'ai-model-edit', params: { id } })` (row Edit).
- **Form → list**: `router.push({ name: 'ai-models' })` on save success or Cancel; "← AI Models" breadcrumb in form header does the same.
- **Document delete / bulk-delete**: never navigates — uses `AppConfirm` modal in-place; success toast via `useToast()`.

### Route transitions

`<router-view>` is wrapped in a `<Transition name="fade-route" mode="out-in">` (150ms opacity fade). The transition is suppressed under `prefers-reduced-motion: reduce`.

---

## Common error responses

| HTTP | Meaning | When |
|------|---------|------|
| 400 | Bad Request | Malformed body or query param |
| 401 | Unauthenticated | Missing or invalid `Authorization` header on a token-protected route |
| 403 | Forbidden | Authenticated but not allowed (e.g. accessing another user's document) |
| 404 | Not Found | ULID doesn't match any row, or row is soft-deleted |
| 409 | Conflict | Duplicate file upload (returns the existing document) |
| 422 | Unprocessable Entity | Validation failure — `errors` populated per field |
| 429 | Too Many Requests | Login throttle hit (5 attempts per 60 seconds) |
| 500 | Server Error | Unhandled — check `storage/logs/` |
