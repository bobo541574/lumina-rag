# Lumina RAG — Learning Guide

> **Self-hosted Document Q&A System**
> Upload PDF/DOCX/TXT/CSV/MD files — ask questions in natural language — get answers with source citations.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Tech Stack](#2-tech-stack)
3. [Project Structure](#3-project-structure)
4. [Database Schema](#4-database-schema)
5. [Module System](#5-module-system)
6. [RAG Pipeline (Core Logic)](#6-rag-pipeline-core-logic)
7. [Document Upload & Processing](#7-document-upload--processing)
8. [API Routes](#8-api-routes)
9. [Authentication](#9-authentication)
10. [Frontend (Vue 3 SPA)](#10-frontend-vue-3-spa)
11. [Configuration](#11-configuration)
12. [Commands & Tools](#12-commands--tools)
13. [Testing](#13-testing)
14. [Key Conventions](#14-key-conventions)

---

## 1. Project Overview

Lumina RAG is a **Retrieval-Augmented Generation** system. The idea is simple:

1. **Upload** documents (PDF, DOCX, TXT, CSV, Markdown)
2. **Ask** questions in natural language (English or Myanmar/Burmese)
3. **Get** answers with citations showing exactly which source passages were used

### How RAG works (simplified)

```
User Question
      ↓
[1] Embed question → vector ( numbers that represent meaning )
      ↓
[2] Search documents for similar chunks (vector search + text search)
      ↓
[3] Filter best matching chunks (above similarity threshold)
      ↓
[4] Send chunks + question to an AI (LLM) → generate answer
      ↓
[5] Return answer with source citations
```

The key insight: instead of asking the AI to guess from its training data, we **find the relevant passages first** and feed them to the AI so it answers based on your actual documents.

---

## 2. Tech Stack

| Layer | Technology | Purpose |
|-------|------------|---------|
| **Backend** | Laravel 13 (PHP 8.3+) | Web framework, REST API |
| **Database** | PostgreSQL 16 + pgvector 0.6+ | Store data + vector embeddings (same DB) |
| **Cache/Queue** | Redis (recommended) / database fallback | Cache embeddings, queue processing jobs |
| **AI Embedding** | Ollama (`nomic-embed-text:latest`, etc.) or OpenAI | Convert text → vectors |
| **AI LLM** | Ollama (`qwen3.5:9b`, `gemma4:e4b`, etc.) or OpenAI | Generate answers from context |
| **Frontend** | Vue 3 + TypeScript + Pinia + vue-router | Single-page application |
| **CSS** | Tailwind v4 | Styling |
| **Build** | Vite 8 | Frontend build tool |
| **Testing** | Pest PHP (backend) + Vitest (frontend) | Automated tests |

### Key PHP libraries

- `smalot/pdfparser` — extract text from PDFs
- `phpoffice/phpword` — extract text from DOCX files

### AI Note

All AI calls go through **raw PHP curl** — no official OpenAI SDK. This keeps dependencies minimal and works equally well with OpenAI or Ollama (just change the URL).

---

## 3. Project Structure

```
lumina_rag/
├── AGENTS.md                 # Quick reference for AI coding assistants
├── LEARNING.md               # THIS FILE — learning guide
├── composer.json             # PHP dependencies
├── package.json              # Frontend dependencies
├── config/
│   ├── app.php               # Service providers registered here
│   ├── rag.php               # ALL RAG settings (central config file)
│   └── modules.php           # Module enabled flags (NOT read at runtime!)
├── app/
│   ├── Models/User.php       # User model (ULID, api_token auth)
│   └── Http/Middleware/
│       └── AuthenticateWithToken.php  # Custom token auth middleware
├── modules/                  # 7 feature modules
│   ├── ChatModule/           # RAG orchestration, chat sessions
│   ├── DocumentModule/       # Upload, extract, chunk documents
│   ├── EmbeddingModule/      # Text → vector conversion
│   ├── LLMModule/            # LLM completions (streaming + non-streaming)
│   ├── VectorStoreModule/    # Vector storage + similarity search
│   ├── UserModule/           # Registration, login, token auth
│   └── SettingsModule/       # AI model registry, settings
├── resources/js/             # Vue 3 frontend
│   ├── app.js                # Entry point — boots Vue + Pinia + Router
│   ├── App.vue               # Root component (nav, auth loading)
│   ├── router.ts              # SPA routes with auth guards
│   ├── types/index.ts         # TypeScript interfaces
│   ├── services/              # API calls (axios)
│   ├── stores/                # Pinia stores (auth, chat, document)
│   ├── components/            # Reusable Vue components
│   └── pages/                 # Page-level components
├── docs/                      # Documentation
├── tests/                     # PHP tests (Pest)
└── database/                  # Shared migrations, seeders
```

### Module Dependency Graph

```
                   UserModule (standalone — login/register/token auth)
                   SettingsModule (standalone — AI model registry)

                   EmbeddingModule ──┐
                   VectorStoreModule ┤
                   LLMModule ────────┤
                                      ↓
                   DocumentModule (uses Embedding + VectorStore)
                   ChatModule (uses Embedding + VectorStore + LLM)
```

---

## 4. Database Schema

### Key Design Decisions

1. **All primary keys are ULIDs** — not auto-increment integers. ULIDs are 26-character strings (Crockford base32) that are sortable by time, URL-safe, and collision-resistant.
2. **No database-level foreign key constraints** — relationships are enforced in PHP service code. This makes migrations easier and supports future sharding.
3. **Vectors live in the same database** — pgvector extension adds a `vector` column type.

### Tables

#### `users`
| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `name` | string | Display name |
| `email` | string (unique) | Login identifier |
| `password` | string | bcrypt hash |
| `api_token` | string(80) (unique) | 80-char hex token for API auth |
| `created_at` | timestamptz | |
| `updated_at` | timestamptz | |
| `deleted_at` | timestamptz | Soft delete |

#### `documents`
| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `user_id` | ULID | Owner (no FK) |
| `title` | varchar(255) | Editable title |
| `original_filename` | varchar(255) | Original upload name |
| `file_path` | varchar(500) | Storage path |
| `file_size` | integer | Bytes |
| `mime_type` | varchar(100) | PDF/DOCX/TXT/CSV/MD |
| `file_hash` | varchar(64) (unique) | SHA-256 — duplicate detection |
| `status` | varchar(20) | `pending` → `processing` → `completed` / `failed` |
| `chunks_count` | integer | Number of text chunks |
| `report_date` | date | Optional date field for reports |
| `project` | varchar | Optional project name for grouping |
| `embedding_model` | varchar(100) | Model used for embeddings |
| `embedding_model_id` | ULID | → `ai_models.id` |
| `error_message` | text | Populated on failure |
| `processed_at` | timestamptz | When processing completed |
| `created_at` | timestamptz | |
| `deleted_at` | timestamptz | Soft delete |

#### `document_chunks`
| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `document_id` | ULID | → documents.id |
| `content` | longtext | The chunk text |
| `chunk_index` | integer | 0-based position |
| `page_number` | integer | PDF only |
| `char_start` / `char_end` | integer | Character offsets in original text |
| `token_count` | integer | Estimated token count |
| `tsv_content` | tsvector | PostgreSQL FTS vector (for text search) |
| `created_at` | timestamptz | |

Unique: `(document_id, chunk_index)`

#### `chat_sessions`
| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `user_id` | ULID | Owner |
| `title` | varchar(255) | Auto-set from first question |
| `is_archived` | boolean | |
| `message_count` | integer | Cached count |
| `last_activity_at` | timestamptz | Updated on every message |
| `created_at` / `updated_at` | timestamptz | |
| `deleted_at` | timestamptz | Soft delete |

#### `chat_messages`
| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `session_id` | ULID | → chat_sessions.id |
| `role` | varchar(20) | `user` or `assistant` |
| `content` | longtext | Message body |
| `sources` | jsonb | Array of `{document_id, title, chunk_index, score, excerpt}` |
| `token_count` | integer | |
| `created_at` | timestamptz | |
| `deleted_at` | timestamptz | Soft delete |

#### `ai_models`
| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `name` | varchar(255) | Friendly name (e.g. "nomic-embed-text") |
| `type` | varchar(20) | `embedding` or `llm` |
| `provider` | varchar(50) | `openai` or `ollama` |
| `model` | varchar(255) | Model name (e.g. `nomic-embed-text:latest`) |
| `api_key` | text | OpenAI API key (null for Ollama) |
| `base_url` | varchar(500) | Ollama URL (e.g. `http://localhost:11434`) |
| `dimensions` | integer | Embedding dimension (768, 1024, 1536, 3072) |
| `batch_size` | integer | Embedding batch size |
| `temperature` | decimal | LLM temperature |
| `max_context_tokens` | integer | LLM context limit |
| `timeout` | integer | Request timeout |
| `settings` | jsonb | Per-model pipeline overrides (top_k, threshold, etc.) |
| `is_active` | boolean | |
| `sort_order` | integer | Priority order |

#### Vector storage (`ve_384`, `ve_768`, `ve_1024`, `ve_1536`, `ve_3072`)

This is the most unique part. Vectors are stored in **per-dimension shard tables**:

```
ve_768     ← nomic-embed-text (768 dimensions)
ve_1024    ← mxbai-embed-large (1024 dimensions)
ve_1536    ← text-embedding-3-small (1536 dimensions)
ve_3072    ← text-embedding-3-large (3072 dimensions)
```

Each shard table:
| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | |
| `chunk_id` | ULID | → document_chunks.id |
| `embedding` | `vector(N)` | The actual vector |
| `model_name` | varchar(100) | |
| `content_hash` | varchar(32) | MD5 of content |

There's also a `vector_embeddings` metadata table for bookkeeping (and SQLite fallback).

**Why shard tables?** pgvector's `vector(N)` type fixes dimensions at column creation time, and different models produce different dimensions. So we need separate tables.

---

## 5. Module System

Each module follows the same structure:

```
modules/{Name}Module/
├── Controllers/     → Thin HTTP layer (validation + dispatch, no business logic)
├── Services/        → Business logic (the only layer touching Models)
├── Contracts/       → Interfaces (bound in ServiceProvider)
├── Models/          → Eloquent models
├── Requests/        → Form request validation
├── Routes/          → API route definitions
├── Providers/       → ServiceProvider registration
├── Jobs/            → Queue jobs (DocumentModule only)
├── Commands/        → Artisan commands
└── database/
    ├── migrations/  → Module-specific migrations
    └── Seeders/     → Demo data seeders
```

### Module-by-module breakdown

#### ChatModule
- **Purpose**: RAG orchestration — takes a question, finds relevant chunks, generates answer
- **Key files**:
  - `Services/RAGPipelineService.php` — THE core file (1500+ lines). Orchestrates the entire RAG flow
  - `Controllers/ChatController.php` — Handles streaming and non-streaming chat requests
  - `Models/ChatSession.php`, `Models/ChatMessage.php`
- **Key flow**: `ask()` → extract filters from question → embed → hybrid search → dynamic threshold → context assembly → LLM call → save messages

#### DocumentModule
- **Purpose**: Upload, validate, extract text, chunk, and embed documents
- **Key files**:
  - `Services/DocumentService.php` — Upload validation, CRUD, list with server-side pagination
  - `Services/TextExtractionService.php` — Extract text from PDF/DOCX/TXT/CSV/MD
  - `Services/TextChunkingService.php` — Recursive character text splitter
  - `Jobs/ProcessDocumentJob.php` — Async processing pipeline (extract → chunk → embed)
- **Flow**: Upload → validate → SHA-256 dedup → store file → dispatch job → extract → chunk → batch-embed → upsert vectors

#### EmbeddingModule
- **Purpose**: Convert text → vector, with caching
- **Key files**:
  - `Services/EmbeddingService.php` — Caching wrapper. MD5-caches embeddings for 24h
  - `Services/OpenAIEmbeddingProvider.php` — OpenAI API via curl
  - `Services/OllamaEmbeddingProvider.php` — Ollama API via curl
- **Key detail**: `embedBatch()` checks cache first for each text, only sends uncached texts to the API

#### LLMModule
- **Purpose**: LLM completions (streaming + non-streaming)
- **Key files**:
  - `Services/LLMService.php` — Assembles prompt with context, calls provider
  - `Services/OllamaLLMProvider.php` — Ollama streaming/non-streaming via curl
  - `Services/OpenAILLMProvider.php` — OpenAI via curl
- **Key detail**: `buildContextString()` adds chunks one by one, stopping when `max_context_tokens` is reached. Each chunk gets a source label like `[Source: Q3 Report.pdf (85%)]`

#### VectorStoreModule
- **Purpose**: Store vectors, search by similarity
- **Key files**:
  - `Services/PgvectorDriver.php` — PostgreSQL + pgvector implementation
  - `Services/VectorStoreService.php` — Orchestrates driver selection
- **Search modes**:
  - `vector` → pure cosine similarity (`1 - (embedding <=> ?)`)
  - `fts` → PostgreSQL full-text search (`tsv_content @@ plainto_tsquery('english', ...)`)
  - `hybrid` → Both in parallel, fused via Reciprocal Rank Fusion (RRF)

#### UserModule
- **Purpose**: Registration, login, token authentication
- **Key files**:
  - `Controllers/AuthController.php` — register, login, logout, me
  - `Services/AuthService.php` — Token management
- **Auth**: Custom 80-char hex tokens (not Laravel Sanctum/Sanctum)

#### SettingsModule
- **Purpose**: AI model registry + key/value settings
- **Key files**:
  - `Services/AiModelService.php` — CRUD for embedding/LLM models
  - `Models/AiModel.php` — Model registry Eloquent model
  - `Database/Seeders/SettingsModuleSeeder.php` — Seeds default Ollama models

---

## 6. RAG Pipeline (Core Logic)

This is the heart of the application, in `modules/ChatModule/Services/RAGPipelineService.php`.

### Step-by-step flow

#### Step 1: `ask($question, $options)`

```php
// 1. Truncate long questions
$question = $this->normalizeQuestion($question);

// 2. Get or create chat session
$session = $this->resolveSession($sessionId, $userId);

// 3. Check message limit (max 100 per session)
$this->checkMessageLimit($session);

// 4. Extract filters from question text
$autoFilters = $this->extractFiltersFromQuestion($question);
//   → Detects user names → user_ids
//   → Detects project names → project
//   → Detects date references (today, yesterday, April 2026, etc.) → date range

// 5. Refine FTS query (remove filter terms, stopwords)
$ftsQuery = $this->refineFtsQuery($question, $autoFilters);

// 6. Save user message to DB
$this->saveUserMessage($session, $question);

// 7. Resolve follow-up filters (inherit from previous question)
//   If this question has no user/project, inherit from last question
//   If previous answer was a refusal, DON'T inherit dates

// 8. Dynamic model selection — detect which embedding model
//   the filtered documents use, use that for the question embedding
```

#### Step 2: `extractFiltersFromQuestion($question)`

This method parses natural language to extract structured filters:

- **User names**: matching against all user names in DB (e.g. "အောင်ဇေယျာ", "Sarah Chen")
- **Project names**: matching against known projects (e.g. "Project Orion", "Project Atlas")
- **Date references**:
  - Today, yesterday: resolves to exact dates
  - "2026-04" → April 1-30, 2026
  - "this week", "last month" → relative date ranges
  - "Q1 2025" → January-March 2025
  - "2026" → January 1 - December 31, 2026

These filters are applied as SQL WHERE clauses, NOT as FTS search terms.

#### Step 3: `refineFtsQuery($question, $filters)`

Strips the question down to content words only for FTS search:

1. Remove detected user names (e.g. "အောင်ဇေယျာ", "Sarah Chen")
2. Remove detected project names (e.g. "Project Orion")
3. Remove complete date patterns (`YYYY-MM-DD`, `YYYY-MM`) — done BEFORE individual year stripping to avoid bare `-04` being interpreted as PostgreSQL FTS negation
4. Remove individual years and quarters
5. Remove stopwords (English + Burmese conversational words)
6. Filter out short tokens (< 3 ASCII chars, < 2 non-ASCII chars)
7. Remove hyphen-prefixed numeric tokens (date fragments)
8. Trim leading/trailing hyphens from tokens

**Why so much stripping?** The filters (user, project, date) are already applied as SQL WHERE clauses. The FTS query should only contain the actual content words to search for.

#### Step 4: Search

```php
// Vector search:
//   1 - (embedding <=> question_vector) as similarity_score
//   Filters: user_ids, project, report_date range, similarity_threshold

// FTS search (hybrid mode only):
//   tsv_content @@ plainto_tsquery('english', $ftsQuery)
//   Filters: user_ids, project, report_date range

// Hybrid fusion: Reciprocal Rank Fusion (RRF)
//   Both searches run in parallel, results combined by rank position
//   Scores normalized to 0-1
```

**Threshold logic**:
- Initial search: lowered to `min(0.65, 0.40)` to cast a wider net
- Post-fusion: `applyDynamicThreshold()` uses the configured 0.65 threshold
  - If there's a big score gap (>0.15) between consecutive results, cut at that gap
  - Otherwise: `cutoff = max(0.65, top_score * 0.85)`
  - Safety valve: if all chunks filtered out, keep the single best chunk

#### Step 5: MMR Re-ranking (optional)

Maximal Marginal Relevance reduces redundancy:
- Scores each chunk by `lambda * similarity - (1-lambda) * max_similarity_to_already_selected`
- Default lambda: 0.7 (balance between relevance and diversity)

#### Step 6: Context Assembly

```php
// Chunks sorted by score descending
// Concatenated with source labels:
//   [Source: Document Title (85%)], Page 5
//   Chunk content here...
// Truncated to max_context_tokens (default 32768)
```

#### Step 7: LLM Call

The assembled context + user question are sent to the LLM:
```
System: You are a helpful assistant. Answer based ONLY on the provided context.
        If the context doesn't contain enough info, say so.

Context:
---
[Source: Q3 Report.pdf (92%)], Page 12
Revenue in Q3 reached $45.2 million...

[Source: Q3 Report.pdf (88%)], Page 14
Operating expenses increased by 12%...
---

Question: What was the revenue in Q3?

Answer:
```

#### Step 8: Streaming

When `stream: true`, the server emits Server-Sent Events (SSE):

```
data: {"type":"status","stage":"embedding","message":"Embedding question..."}

data: {"type":"status","stage":"searching","message":"Searching documents..."}

data: {"type":"sources","sources":[...]}

data: {"type":"status","stage":"generating","message":"Generating answer..."}

data: {"type":"chunk","content":"The revenue"}

data: {"type":"chunk","content":" in Q3 was"}

data: {"type":"chunk","content":" $45.2 million."}

data: {"type":"done","session_id":"...","tokens_used":150,"search_time_ms":320,"llm_time_ms":1200,"total_time_ms":1650}
```

#### Step 9: Persistence

Both user message and assistant message are saved to `chat_messages` table. Sources are stored as JSONB.

---

## 7. Document Upload & Processing

### Upload Flow

```
User uploads file → browser sends multipart POST to /api/documents
      ↓
DocumentService::upload()
  1. Validate file type (PDF/DOCX/TXT/CSV/MD)
  2. Validate file size (max 50MB)
  3. Compute SHA-256 hash
  4. Check for duplicate (returns 409 if exists)
  5. Store file to storage/app/documents/
  6. Create Document record (status: 'pending')
  7. Dispatch ProcessDocumentJob to queue
  8. Return document ID (client can poll status)
```

### Processing Flow (Async Queue Job)

```
ProcessDocumentJob::handle()
  1. Delete any previous chunks/vectors (for retries)
  2. Extract text from file
     - PDF: smalot/pdfparser → preserves page boundaries
     - DOCX: phpoffice/phpword
     - TXT/CSV/MD: direct read
  3. Chunk text using recursive character splitter
     - Chunk size: 1000 chars (configurable)
     - Overlap: 200 chars
     - Separator priority: ¶ → line → . → , → space → char
  4. Save chunks to document_chunks table
     - Auto-populate tsv_content (FTS vector) for PostgreSQL
     - Each chunk gets metadata header:
       "Report by: {user}\nProject: {project}\nDate: {date}\n\n{content}"
  5. Generate embeddings (in batches of 100)
     - Checks cache first → only sends uncached texts
     - Uses the document's configured embedding model
  6. Upsert vectors to the matching ve_{dim} shard table
  7. Mark document as 'completed'
```

---

## 8. API Routes

All routes under `/api/`, all authenticated with `Authorization: Bearer <80-char-hex-token>`.

### Auth (`POST /api/auth/*`)

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| POST | `/api/auth/register` | No | Create account |
| POST | `/api/auth/login` | No | Login, returns token |
| POST | `/api/auth/logout` | Yes | Invalidate token |
| GET | `/api/auth/me` | Yes | Current user info |

### Chat

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/chat` | Ask question (streaming or non-streaming) |
| GET | `/api/chat/sessions` | List chat sessions |
| GET | `/api/chat/sessions/{ulid}` | Get session with messages |
| DELETE | `/api/chat/sessions/{ulid}` | Delete session |

Chat POST body:
```json
{
  "question": "What is the Q3 revenue?",
  "session_id": null,
  "document_filter": {
    "user_ids": ["..."],
    "project": "Project Orion",
    "date_from": "2026-04-01",
    "date_to": "2026-04-30"
  },
  "llm_model_id": "...",
  "stream": true
}
```

### Documents

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/documents` | List (server-side pagination: `?page=&per_page=&search=&status=&sort_key=&sort_dir=`) |
| POST | `/api/documents` | Upload (multipart) |
| GET | `/api/documents/{ulid}` | Document detail |
| GET | `/api/documents/{ulid}/status` | Poll status |
| POST | `/api/documents/{ulid}/retry` | Retry failed document |
| PUT | `/api/documents/{ulid}` | Update title/description |
| DELETE | `/api/documents/{ulid}` | Soft-delete + cascading cleanup |

Upload payload (multipart/form-data):
```
file:                 <the file> (required, max 50MB)
title:                (optional)
embedding_model:      (optional) model name
embedding_model_id:   (optional) ai_models ULID
report_date:          (optional) YYYY-MM-DD
project:              (optional) project name
```

### AI Models (Settings)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/settings/ai-models` | List (optional `?type=embedding` or `?type=llm`) |
| POST | `/api/settings/ai-models` | Create |
| GET | `/api/settings/ai-models/{ulid}` | Get one |
| PUT | `/api/settings/ai-models/{ulid}` | Update |
| DELETE | `/api/settings/ai-models/{ulid}` | Delete |

### Settings

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/settings` | All settings with definitions |
| PUT | `/api/settings/bulk` | Bulk update |
| PUT | `/api/settings/{key}` | Single update |
| DELETE | `/api/settings/{key}` | Reset to default |

### Response Envelope

```json
{
  "success": true,
  "message": "...",
  "data": { ... },
  "errors": { "field": ["message"] },
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 100
  }
}
```

---

## 9. Authentication

### How it works

1. **Registration**: `POST /api/auth/register` with name/email/password → creates user + generates 80-char hex `api_token`
2. **Login**: `POST /api/auth/login` → returns existing token (or rotates it)
3. **Authentication**: Every API call sends `Authorization: Bearer <token>` header
4. **Middleware**: `app/Http/Middleware/AuthenticateWithToken.php` looks up `users.api_token` and attaches the user to the request

```php
// Middleware logic (simplified)
$token = $request->bearerToken();
$user = User::where('api_token', $token)->first();
$request->merge(['authenticated_user' => $user]);
```

This is a **custom implementation** — not Laravel Sanctum or Passport. Just a hex token stored in the `users` table.

---

## 10. Frontend (Vue 3 SPA)

### App Bootstrap

`resources/js/app.js`:
1. Create Vue app + Pinia
2. Initialize auth store (check for token, validate with `/api/auth/me`)
3. Mount the router
4. Mount to `#app` div

### Routing

`resources/js/router.ts` — 8 routes:
| Path | Component | Guard |
|------|-----------|-------|
| `/login` | LoginPage | guest (redirect to / if logged in) |
| `/register` | RegisterPage | guest |
| `/` | ChatPage | auth (redirect to /login if not) |
| `/documents` | DocumentsPage | auth |
| `/settings/ai-models` | AiModelsPage | auth |
| `/settings/ai-models/new` | AiModelManager | auth |
| `/settings/ai-models/:id/edit` | AiModelManager | auth |
| `/:pathMatch(.*)*` | → redirect to `/` | catch-all |

### App.vue — The Root Component

Three main states:
1. **Authenticated** (`auth.isAuthenticated`): Shows header (desktop nav + mobile hamburger) + main content area
2. **Loading** (`!auth.isInitialized`): Shows spinning loader while checking auth
3. **Guest** (`else`): Shows login/register pages (no header)

### Pinia Stores

**authStore** — Authentication state:
- `user`, `token`, `isLoading`, `isInitialized`, `isAuthenticated`
- `init()` — check stored token, call `/api/auth/me`
- `login()`, `register()`, `logout()`
- Token stored in `localStorage('lumina_token')`

**chatStore** — Chat functionality:
- `sessions`, `currentSession`, `messages`, `isStreaming`, `currentStage`, `lastStreamMeta`
- `sendMessage()` — streaming (default) or non-streaming
- `abortStream()` — stop button
- Stream callbacks: `onChunk`, `onSources`, `onStatus`, `onDone`, `onError`

**documentStore** — Document management:
- `documents`, `isLoading`, `meta` (pagination)
- `fetchDocuments()` — server-side pagination with status/search/sort filters
- `uploadDocument()`, `deleteDocument()`, `bulkDelete()`

### Key Components

**ChatInterface.vue** — Main chat area:
- Filter bar (document selection, date range, LLM model)
- Message list with auto-scroll
- Streaming indicator (stages: embedding/searching/generating)
- Source citations (expandable)
- Message input with send button
- Shows tokens used + processing time

**ChatSidebar.vue** — Session list:
- List of past chat sessions
- New Chat button
- Session deletion

**DocumentsPage.vue** — Document table:
- Status tabs (All/Pending/Processing/Completed/Failed)
- Search input (debounced 300ms)
- Sortable columns
- Server-side pagination with page size selector
- Bulk select + batch delete
- Upload button → DocumentUpload modal

### API Layer

`resources/js/services/api.ts` — Axios wrapper:
- Base URL: `/api`
- Auto-injects `Authorization: Bearer <token>` header
- Auto-redirects to `/login` on 401
- Helper functions: `get()`, `post()`, `put()`, `del()`, `upload()`

Each feature has its own service file:
- `authService.ts` — login, register, logout, me
- `chatService.ts` — ask (streaming), sessions CRUD
- `documentService.ts` — list, get, upload, update, delete
- `aiModelService.ts` — CRUD for AI models

---

## 11. Configuration

### config/rag.php — The Central Knob

Every RAG parameter is configurable via environment variables:

| Config key | Env variable | Default | Description |
|------------|-------------|---------|-------------|
| `embedding.provider` | `RAG_EMBEDDING_PROVIDER` | `ollama` | `openai` or `ollama` |
| `embedding.model` | `RAG_EMBEDDING_MODEL` | `nomic-embed-text:latest` | Embedding model |
| `embedding.dimensions` | `RAG_EMBEDDING_DIMENSIONS` | `768` | Vector dimensions |
| `embedding.batch_size` | `RAG_EMBEDDING_BATCH_SIZE` | `100` | Embedding batch size |
| `embedding.cache_ttl` | `RAG_EMBEDDING_CACHE_TTL` | `86400` | Cache TTL (24h) |
| `llm.provider` | `RAG_LLM_PROVIDER` | `ollama` | `openai` or `ollama` |
| `llm.model` | `RAG_LLM_MODEL` | `qwen3.5:9b` | LLM model |
| `llm.max_context_tokens` | `RAG_LLM_MAX_CONTEXT_TOKENS` | `32768` | Max context window |
| `vector_store.driver` | `RAG_VECTOR_DRIVER` | `pgsql` | Only `pgsql` implemented |
| `search.mode` | `RAG_SEARCH_MODE` | `hybrid` | `vector`, `fts`, or `hybrid` |
| `search.top_k` | `RAG_SEARCH_TOP_K` | `5` | Number of results |
| `search.similarity_threshold` | `RAG_SEARCH_SIMILARITY_THRESHOLD` | `0.65` | Minimum similarity |
| `search.mmr.enabled` | `RAG_SEARCH_MMR_ENABLED` | `true` | MMR re-ranking |
| `search.mmr.lambda` | `RAG_SEARCH_MMR_LAMBDA` | `0.7` | MMR diversity/relevance balance |
| `chunking.chunk_size` | `RAG_CHUNK_SIZE` | `1000` | Characters per chunk |
| `chunking.overlap` | `RAG_CHUNK_OVERLAP` | `200` | Overlap between chunks |
| `chat.max_question_length` | `RAG_MAX_QUESTION_LENGTH` | `1000` | Max question chars |
| `chat.max_messages_per_session` | `RAG_MAX_MESSAGES_PER_SESSION` | `100` | Message limit |

### AiModel Registry (Database)

The `ai_models` table overrides config defaults. The active model is determined by:
- Type: `embedding` or `llm`
- `is_active = true`
- Ordered by `sort_order`
- First active model wins

Each AiModel can also have a `settings` JSONB column that overrides pipeline settings (top_k, similarity_threshold, search_mode, etc.)

---

## 12. Commands & Tools

### Development

```bash
composer run dev
# Runs 4 processes concurrently:
#   1. php artisan serve        → HTTP server
#   2. php artisan queue:listen → Queue worker
#   3. php artisan pail         → Log viewer
#   4. npm run dev              → Vite dev server
```

### Testing

```bash
composer run test
# Clears config cache, then runs all Pest tests
# 53 tests (as of writing)

# Run individual tests
php artisan test --filter=TestName
php artisan test --testsuite=Unit
php artisan test --testsuite=Feature
```

### PHP Formatting

```bash
./vendor/bin/pint              # Format all PHP files
./vendor/bin/pint --dirty      # Format only changed files
```

### Database

```bash
php artisan db:seed            # Seed demo data
php artisan migrate            # Run pending migrations
```

### Vector Re-embedding

```bash
php artisan rag:reembed                        # Re-embed ALL documents
php artisan rag:reembed --document={ulid}      # Re-embed one document
```

---

## 13. Testing

### Backend (Pest PHP)

53 tests across 5 test files:

| Test file | Type | What it tests |
|-----------|------|---------------|
| `tests/Feature/RAGPipelineEdgeCaseTest.php` | Feature | FTS stopword stripping, empty result fallback, Burmese query handling |
| `tests/Feature/AiModelApiTest.php` | Feature | CRUD operations, validation, error handling |
| `tests/Feature/ExampleTest.php` | Feature | Basic Laravel test |
| `tests/Unit/ExampleTest.php` | Unit | Basic unit test |
| `tests/Unit/LLMProviderTest.php` | Unit | LLM provider streaming logic |
| `tests/Unit/VectorStoreServiceTest.php` | Unit | Vector search and filter behavior |

**Test environment**:
- SQLite `:memory:` (not PostgreSQL) — faster, but means pgvector features aren't tested
- `QUEUE_CONNECTION=sync` — jobs run synchronously
- `tests/Pest.php` — applies `RefreshDatabase` trait to Feature suite only

### Frontend (Vitest)

- `vitest.config.ts` + `resources/js/services/__tests__/`
- Tests API layer and chat service

---

## 14. Key Conventions

### PHP
- `declare(strict_types=1);` at the **top** of every PHP file
- ULIDs for all primary keys (use `HasUlids` trait)
- No DB-level foreign keys — enforce in service code
- Controllers are thin: validate + dispatch to service
- Services are thick: all business logic, interact with Models directly
- No Repository layer — Services + Models is enough

### Frontend
- Composition API + `<script setup lang="ts">`
- Pinia stores use the setup function syntax (not options API)
- TypeScript interfaces in `types/index.ts`
- Components in `components/`, pages in `pages/`
- CSS via Tailwind utility classes
- Services layer sits between stores and API

### Database
- All timestamps: `TIMESTAMPTZ` (not `TIMESTAMP`)
- Soft deletes everywhere (`deleted_at` column)
- JSONB for structured metadata (sources, settings)
- Vectors: per-dimension shard tables with IVFFlat indexes

### AI Models
- OpenAI/Ollama calls go through **raw cURL** — no SDKs
- All AI communication behind interfaces (`EmbeddingProviderInterface`, `LLMProviderInterface`)
- Embeddings are MD5-cached (24h TTL)
- The `ai_models` database table is the **source of truth** for which model/provider is active

---

## Quick Reference: Request → Response Flow

### Chat (Streaming)
```
Browser                    Laravel                     Ollama/OpenAI
  │                          │                            │
  ├─ POST /api/chat ────────→│                            │
  │   {question, stream:true}│                            │
  │                          ├─ Embed question ──────────→│
  │                          │←──── vector ───────────────│
  │                          │                            │
  │                          ├─ Hybrid search ────────────│
  │                          │  (vector + FTS)            │
  │                          │  → relevant chunks          │
  │                          │                            │
  │  ←── SSE: status ────────┤                            │
  │  ←── SSE: sources ───────┤                            │
  │                          ├─ LLM call ────────────────→│
  │  ←── SSE: chunk ─────────┤←── stream tokens ──────────│
  │  ←── SSE: chunk ─────────┤                            │
  │  ←── SSE: done ──────────┤                            │
  │                          ├─ Save messages to DB       │
```

### Document Upload
```
Browser                    Laravel                      Ollama
  │                          │                            │
  ├─ POST /api/documents ───→│                            │
  │   (multipart)            ├─ Validate file              │
  │                          ├─ SHA-256 dedup check        │
  │                          ├─ Store file                 │
  │                          ├─ Create Document (pending)  │
  │                          ├─ Dispatch ProcessDocumentJob│
  │  ←── 201 + document ─────┤                            │
  │                          │                            │
  │                          ├─ ProcessDocumentJob:        │
  │                          │  ├─ Extract text            │
  │                          │  ├─ Chunk text             │
  │                          │  ├─ Save chunks (with FTS)  │
  │                          │  ├─ Embed batch ──────────→│
  │                          │  │←── vectors ─────────────│
  │                          │  └─ Upsert vectors          │
  │                          ├─ Mark completed             │
```

---

> **Tip**: The best way to learn is to run `composer run dev`, open the app, upload a document, and ask questions. Then read the code top-down: start with the route (e.g. `Routes/chat.php`), follow to the Controller, then to the Service. The AGENTS.md files in each module give a high-level overview.
