# Project Structure

> Source-of-truth reference for how the codebase is organized — backend modules, frontend layout, and the contracts between them. Cross-check against the code; this document is updated as part of feature work, but the code wins on disagreement.

---

## High-level shape

```
laravel-monolith
├── modules/             ← 7 feature modules (PSR-4: Modules\{Name}Module\)
├── app/                 ← shared framework code (User model, middleware, etc.)
├── config/              ← Laravel + per-feature config
├── database/            ← shared migrations + seeders entry point
├── resources/
│   ├── css/             ← Tailwind v4 entry + design tokens
│   └── js/              ← Vue 3 SPA
├── routes/              ← framework default route files (modules register their own)
├── tests/
└── docs/                ← supplementary reference (deployment, schema)
```

The Laravel app is monolithic; "modules" are an internal organization, not separate deployable units. Each module is mounted at boot via its `ServiceProvider`, registered manually in [config/app.php](config/app.php).

---

## Backend modules

| Module | Purpose | Key contracts (interface → implementation) |
|--------|---------|--------------------------------------------|
| **UserModule** | Registration, login, API token auth | `AuthServiceInterface` → `AuthService` |
| **EmbeddingModule** | Text → vector, batched, cached | `EmbeddingProviderInterface` → `OpenAIEmbeddingProvider` / `OllamaEmbeddingProvider` / `GeminiEmbeddingProvider`<br>`EmbeddingServiceInterface` → `EmbeddingService` |
| **VectorStoreModule** | Vector storage + similarity search | `VectorStoreInterface` → `VectorStoreService` (wraps `PgvectorDriver`) |
| **LLMModule** | LLM completions with streaming | `LLMProviderInterface` → `OpenAILLMProvider` / `OllamaLLMProvider` / `GeminiLLMProvider` / `ClaudeLLMProvider` / `DeepSeekLLMProvider`<br>`LLMServiceInterface` → `LLMService` |
| **DocumentModule** | Upload → extract → chunk → embed pipeline | `TextExtractionServiceInterface` → `TextExtractionService`<br>`TextChunkingServiceInterface` → `TextChunkingService` |
| **ChatModule** | Session management + RAG orchestration | `RAGPipelineServiceInterface` → `RAGPipelineService` |
| **SettingsModule** | Runtime settings + AI model registry + term aliases | `AiModelServiceInterface` → `AiModelService`<br>`TermAliasServiceInterface` → `TermAliasService` |

### Module dependency graph

```
                   UserModule (standalone — token auth)
                   SettingsModule (standalone — key/value + AI model registry)

                   EmbeddingModule ──┐
                   VectorStoreModule ┤
                   LLMModule ────────┤
                                     ↓
                   DocumentModule (Embedding + VectorStore)
                   ChatModule (Embedding + VectorStore + LLM)
```

Modules communicate **only through the service contracts** in their `Contracts/` directory, resolved through Laravel's container. A module never reaches into another module's models or tables directly.

### Standard module layout

```
modules/{Name}Module/
├── Controllers/      ← thin HTTP layer; validation + dispatch only
├── Services/         ← business logic; the only layer that touches Models
├── Contracts/        ← interfaces, bound in Providers/
├── Models/           ← Eloquent models (attributes + relations only)
├── Requests/         ← FormRequest validation
├── Routes/           ← API route file, loaded by ServiceProvider
├── Providers/        ← {Name}ModuleServiceProvider — registers bindings + routes
├── Jobs/             ← queue jobs (DocumentModule only — ProcessDocumentJob)
├── Commands/         ← artisan commands (ChatModule, DocumentModule)
└── database/
    ├── migrations/
    └── Seeders/
```

---

## Request flow (must follow this direction)

```
HTTP Request → Controller (FormRequest validates)
                  ↓
              Service (via Interface — business logic + DB)
                  ↓
              Model
                  ↓
              Resource (returns Frontend-shaped JSON)
```

Rules from [PROJECT_RULES.md](PROJECT_RULES.md):
- Controllers do validation + method dispatch only — no business logic, no DB calls.
- Services own all DB operations (CRUD). Models are passive (attributes + relations).
- One Resource per route. No sharing across routes.
- ULIDs everywhere — `HasUlids` trait on models, `->whereUlid('id')` in routes.
- No DB-level FK constraints — relationships are enforced at the application layer.

---

## Database

PostgreSQL 16 + pgvector extension. Vectors live in the same database as relational data (no separate vector store).

| Table | PK | Notable columns |
|-------|----|-----------------|
| `users` | ULID | `name`, `email`, `password`, `api_token` (80-char hex, unique) |
| `chat_sessions` | ULID | `user_id`, `title`, `is_archived`, `last_activity_at`, soft deletes |
| `chat_messages` | ULID | `session_id`, `role`, `content`, `sources` (jsonb), soft deletes |
| `documents` | ULID | `user_id`, `title`, `description`, `original_filename`, `file_path`, `file_size`, `mime_type`, `file_hash` (unique), `status`, `chunks_count`, `token_count`, `embedding_model`, `embedding_model_id`, `report_date` (date), `project` (varchar), `is_public` (boolean), `error_message`, soft deletes |
| `document_chunks` | ULID | `document_id`, `content`, `chunk_index`, `page_number`, `token_count`, `char_start`, `char_end`, `metadata` (jsonb), `tsv_content` (tsvector); unique on `(document_id, chunk_index)` |
| `vector_embeddings` | ULID | **Metadata only**: `chunk_id`, `dimensions`, `model_name`, `content_hash` (+ `embedding` JSON column on SQLite). Actual vectors live in per-dimension shard tables. |
| `ve_384` / `ve_768` / `ve_1024` / `ve_1536` / `ve_3072` | ULID | **Postgres + pgvector only.** `chunk_id`, `embedding` (`vector(N)`), `model_name`, `content_hash`. IVFFlat index with `vector_cosine_ops` (skipped for `ve_3072` — pgvector IVFFlat caps at 2000 dims). |
| `ai_models` | ULID | `name`, `type` (embedding/llm), `provider`, `model`, `api_key`, `base_url`, `collection`, `dimensions`, `batch_size`, `cache_ttl`, `temperature`, `max_context_tokens`, `timeout`, `description`, `settings` (jsonb, e.g. `{"max_tokens":4096}`), `is_active`, `sort_order` |
| `term_aliases` | ULID | `type`, `alias`, `canonical`, `description`, `is_active`; unique on `(alias, canonical)` |

See [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) for full column-by-column reference (note: some entries there are stale — the migrations under each module's `database/migrations/` are authoritative).

---

## Frontend (resources/js/)

Vue 3 SPA, mounted from a single entry point. Tailwind v4 for styling with a centralized design-token system. Pinia for state, vue-router for routing, axios for HTTP.

```
resources/js/
├── App.vue                          ← root layout (auth-aware header + main + AppToast)
├── app.js                           ← Vue app + Pinia + router setup
├── router.ts                        ← route definitions + auth guards
├── pages/                           ← page-level components (one per route)
│   ├── LoginPage.vue
│   ├── RegisterPage.vue
│   ├── ChatPage.vue
│   ├── DocumentsPage.vue
│   ├── SettingsPage.vue
│   ├── AiModelsPage.vue             ← list page
│   ├── AiModelManager.vue           ← form page (create + edit)
│   └── TermAliasesPage.vue          ← list + manage term aliases
├── components/
│   ├── ChatInterface.vue
│   ├── SessionList.vue
│   ├── DocumentUpload.vue
│   ├── DocumentList.vue
│   ├── DocumentDetail.vue           ← modal, opens via AppModal
│   ├── AiModelList.vue              ← presentational list (extracted)
│   ├── AiModelForm.vue              ← presentational form (extracted)
│   └── ui/                          ← design-system primitives (see below)
├── stores/                          ← Pinia stores
│   ├── authStore.ts
│   ├── chatStore.ts
│   ├── documentStore.ts
│   └── settingsStore.ts
├── services/                        ← axios wrappers per module
│   ├── api.ts                       ← shared axios instance + auth header
│   ├── chatService.ts
│   ├── documentService.ts
│   ├── settingsService.ts
│   └── termAliasService.ts
├── composables/
│   └── useToast.ts                  ← global toast singleton
├── utils/
│   └── dates.ts                     ← formatRelativeTime + formatAbsoluteTime
├── types/
│   └── index.ts                     ← TypeScript interfaces (Document, AiModel, TermAlias, …)
└── ...
```

### Design system (resources/js/components/ui/)

| Primitive | Purpose |
|-----------|---------|
| `AppButton.vue` | All buttons. Variants: `primary` / `secondary` / `danger` / `ghost` / `danger-ghost`. Sizes: `sm` / `md` / `lg`. Props: `loading`, `loadingLabel`, `block`, `align`. Built-in spinner via AppSpinner. |
| `AppInput.vue` | Text input with focus ring matching design tokens. |
| `AppSelect.vue` | Native select styled to match AppInput, with chevron icon. |
| `AppTextarea.vue` | Multiline text input. |
| `AppCheckbox.vue` | Custom-styled checkbox. Supports `indeterminate` for tri-state "select-all" UX. |
| `AppSpinner.vue` | Loading spinner. Sizes: `xs` / `sm` / `md` / `lg`. `role="status"` + `aria-label`. |
| `AppBadge.vue` | Status pill. Variants: `neutral` / `brand` / `success` / `warning` / `danger` / `info`. Sizes: `xs` / `sm` / `md`. Shapes: `pill` / `square`. |
| `AppEmptyState.vue` | Empty-state block with built-in icons (`inbox` / `chat` / `document` / `info`). |
| `AppModal.vue` | Base modal. Teleport, ESC-to-close, focus trap, body-scroll lock, transition. `dismissable` toggle. |
| `AppToast.vue` + `useToast` composable | Toast notifications. Variants: `success` / `error` / `info` / `warning`. Auto-dismiss with per-variant defaults. |
| `AppConfirm.vue` | Confirmation dialog (wraps AppModal). Used for destructive actions (delete document, delete AI model). |
| `SortHeader.vue` | Sortable `<th>` button. Renders sort-direction arrow, sets `aria-sort`. |

### Design tokens (resources/css/app.css)

Defined in Tailwind v4's `@theme` block:

| Token family | Shades | Used for |
|--------------|--------|----------|
| `brand-{50,100,200,300,500,600,700,800}` | Primary action color (mapped to Tailwind blue) |
| `surface-{50–900}` | Neutral backgrounds, borders, body text (mapped to Tailwind gray) |
| `success-{50,100,600,700,800}` | Positive feedback |
| `warning-{50,100,600,700,800}` | Caution feedback |
| `danger-{50,100,200,600,700,800}` | Destructive / error feedback |
| `info-{50,100,600,700,800}` | Informational feedback |
| `--radius-card`, `--radius-pill` | Rounding scale |

The codebase consistently uses these semantic tokens — raw Tailwind palette names (`blue-`, `gray-`, etc.) should not appear in `resources/`.

### Frontend conventions

- TypeScript on all `.vue` files (`<script setup lang="ts">`) and on services/stores/composables.
- Pages are thin: own page-level state + API calls + navigation. Components are presentational (props in / events out) where possible.
- Loading states use skeletons that mimic the eventual layout (not generic spinners) for cold loads. Quick refreshes don't re-skeleton.
- Server validation errors render **inline at the field**, not as a global banner (auth pages, AI model form).
- Side effects on save/delete surface via toast notifications, not redirects + flash messages.
- Confirmations for destructive actions go through `AppConfirm`, never the native `confirm()` dialog.

---

## Tech stack reference

| Layer | Tech |
|-------|------|
| Backend framework | Laravel 13 (PHP 8.3+) |
| Database | PostgreSQL 16 + pgvector 0.6+ |
| Cache / sessions | Redis (recommended); database fallback |
| Queue | Database (default), Redis (production); `sync` in test |
| AI providers | OpenAI, Ollama, Gemini, Claude, DeepSeek — all implemented for embedding (OpenAI/Ollama/Gemini) and LLM (all five) via raw curl |
| PDF / DOCX extraction | `smalot/pdfparser` + `phpoffice/phpword` (composer deps) |
| Frontend framework | Vue 3 (Composition API + `<script setup lang="ts">`) |
| State | Pinia 3 |
| Routing | vue-router 4 |
| Styling | Tailwind v4 + `@theme` design tokens |
| Build | Vite 8 + `@vitejs/plugin-vue` + `laravel-vite-plugin` |
| Rich text | Trix (description fields on documents + models) |
| Testing | Pest 4 (Feature + Unit suites) + `phpoffice/phpword` mocks |

---

## Coding standards

See [PROJECT_RULES.md](PROJECT_RULES.md) for the full list. Most-load-bearing rules:

- `declare(strict_types=1);` on every PHP file.
- All method parameters and returns are typed.
- DTOs replace raw `$request` for service inputs.
- No hardcoded strings, IDs, URLs, or magic numbers — extract to config or constants.
- Constructor DI everywhere; no facades inside services (some legacy `Storage::` / `Log::` facade usage remains and is being migrated).
- One Resource per route; never share resources between endpoints.
- Tests: 90%+ coverage target on services, 80%+ on controllers.

---

## Templates

`public/templates/` holds downloadable report templates (5 types × 4 formats each). Regenerate with `php artisan rag:generate-templates`.

---

## What lives where — quick lookup

| If you want to… | Look in |
|-----------------|---------|
| Add a new API endpoint | `modules/{Module}/Routes/*.php` + `Controllers/` + `Services/` |
| Change a business rule | `modules/{Module}/Services/` (e.g. RAG threshold lives in `RAGPipelineService`) |
| Add / change a column | `modules/{Module}/database/migrations/` (write up + down) |
| Tune a runtime setting | [config/rag.php](config/rag.php) (or override via `settings` table at runtime) |
| Add a new AI provider | Implement `EmbeddingProviderInterface` or `LLMProviderInterface`; bind in module's ServiceProvider |
| Add a UI primitive | `resources/js/components/ui/` — must be used 2+ places before extracting |
| Change colors | `resources/css/app.css` `@theme` block — never hard-code Tailwind palette names |
| Add a frontend page | `resources/js/pages/` + register in [resources/js/router.ts](resources/js/router.ts) |
