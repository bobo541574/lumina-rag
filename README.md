# Lumina RAG

> **Self-hosted document Q&A.** Upload PDFs, DOCX, TXT, CSV, or Markdown — ask questions in natural language — get answers with citations to the exact source passages.

Built as a single Laravel monolith with a Vue 3 single-page frontend. Stores both relational data and vector embeddings in **PostgreSQL + pgvector** (no separate vector database). Supports **5 interchangeable providers** (OpenAI, Ollama, Gemini, Claude, DeepSeek) for both embedding and LLM — bring your own API key, or run everything locally.

---

## Why

Most RAG systems are notebooks, scripts, or thin wrappers around a vendor API. Lumina is a **complete, deployable application** — multi-user auth, document lifecycle, chat sessions with history, observable async processing, runtime-tunable RAG settings, and a polished web UI. Drop it on a server and your team can start asking questions about their documents the same day.

---

## Features

- 📄 **Document ingestion pipeline** — upload → text extraction (PDF / DOCX / TXT / CSV / MD) → recursive chunking with metadata embedding (author, project, date, section) → batch embedding → vector store. Async via Laravel queue, automatically retried on failure.
- 💬 **Chat with sources** — every answer comes with the chunks it was based on, including document title, page number, and similarity score. Streaming responses with a Stop button.
- 🔍 **Hybrid search by default** — combines vector cosine similarity with PostgreSQL full-text search (tsvector with English config), optionally re-ranked by Maximal Marginal Relevance (MMR) to reduce redundancy. Optional LLM-based query expansion. Filter by document metadata (project, date, author, etc.).
- 🎛️ **Runtime-tunable** — every RAG knob (chunk size, similarity threshold, top-K, search mode, max tokens, etc.) is configurable per-model via the AI Models registry's `settings` JSONB without redeploying.
- 🤖 **5 pluggable AI providers** — OpenAI, Ollama, Gemini, Claude, and DeepSeek implemented for embedding and/or LLM independently. Mix and match per-document via the AI Models registry.
- 👥 **Multi-user with API tokens** — register, login, per-user document and session ownership. Tokens are 80-char hex, sent as `Authorization: Bearer`.
- 🧹 **Real-world UI** — search, sort, filter, pagination, bulk select, optimistic confirms, toast notifications, skeleton loaders, full keyboard accessibility, mobile-responsive header.
- 🔤 **Term alias registry** — maps alternative names (Burmese/English) to canonical terms, auto-expanding search queries.
- 📋 **Report date and project metadata on documents** — sort, filter, and edit document metadata fields (project, date, author). Metadata embedded into every chunk for precision filtering.
- 📝 **Document template generation** — `php artisan rag:generate-templates` produces 5 role-specific report templates (general, finance, customer-service, software-developer, project-coordinator) in .md, .txt, .csv, and .docx formats.

---

## Tech stack

| Layer | Choice |
|-------|--------|
| Backend | **Laravel 13** (PHP 8.3+) — 7 internal modules with strict service contracts |
| Database | **PostgreSQL 16** + **pgvector 0.6+** — relational data and vectors in the same store |
| Cache / queue / sessions | **Redis** (recommended); database fallback |
| AI | **5 interchangeable providers** — OpenAI, Ollama (local), Gemini, Claude, DeepSeek — configurable per-document via AiModel registry. Term aliasing enables cross-language search (Burmese ↔ English). |
| Document parsing | `smalot/pdfparser`, `phpoffice/phpword` |
| Frontend | **Vue 3** (Composition API + TypeScript) + **Pinia** + **vue-router** |
| Styling | **Tailwind v4** with a centralized `@theme` design-token system |
| Build | **Vite 8** |
| Testing | **Pest 4** (Feature + Unit) — SQLite `:memory:` test DB |

---

## Quick start

Prerequisites: PHP 8.3+, Composer, Node 20+, PostgreSQL 16 + pgvector, Redis (recommended), at least one AI provider (Ollama running locally, or an API key for OpenAI / Gemini / Claude / DeepSeek).

```bash
git clone <repo-url> && cd rag
composer run setup                       # install + .env + key + migrate + npm install + build
# Edit .env — set DB_PASSWORD and at least one AI provider key
composer run dev                         # serves Laravel + queue + logs + Vite
```

Open [http://localhost:8000](http://localhost:8000), register, upload a document, ask a question.

For full setup (PostgreSQL install, pgvector, Ollama, troubleshooting), see [SETUP_GUIDE.md](SETUP_GUIDE.md).

---

## Architecture at a glance

```mermaid
flowchart TB
    classDef frontend fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px,color:#1b5e20
    classDef module fill:#e3f2fd,stroke:#1565c0,stroke-width:1px,color:#0d47a1
    classDef infra fill:#fff3e0,stroke:#e65100,stroke-width:1px,color:#bf360c
    classDef storage fill:#f3e5f5,stroke:#6a1b9a,stroke-width:1px,color:#4a148c
    classDef config fill:#f5f5f5,stroke:#616161,stroke-width:1px,color:#212121,stroke-dasharray:4 2
    classDef external fill:#e0f2f1,stroke:#00695c,stroke-width:1px,color:#004d40

    subgraph Frontend["Vue 3 SPA (resources/js/)"]
        direction TB
        Pages["Pages:\nChat · Documents\nAI Models · Settings\nTerm Aliases · Auth"]:::frontend
        UI["Stack:\nTailwind v4 · Pinia\nvue-router · TypeScript\nVite 8 build"]:::frontend
    end

    subgraph Backend["Laravel 13 (modules/)"]
        direction TB

        UserMod["UserModule\n• Register / Login / Logout\n• Token auth (80-char hex)\n• Bcrypt · Rate limiting\n• GET /auth/me"]:::module

        DocMod["DocumentModule\n• Upload → SHA-256 dedup\n• ProcessDocumentJob (queue)\n• Text extraction (PDF/DOCX/TXT)\n• Chunk → Batch embed → Upsert"]:::module

        ChatMod["ChatModule\n• RAG pipeline orchestrator\n• Alias expansion → Embed\n• Hybrid search → RRF fusion\n• Threshold → MMR → LLM\n• SSE streaming · Source citations"]:::module

        EmbedMod["EmbeddingModule\n• EmbeddingService\n• MD5-cached 24h\n• OpenAI / Ollama / Gemini"]:::module

        LLMMod["LLMModule\n• LLMService::complete()\n• Streaming via SSE\n• Temperature 0.3\n• 5 providers (OpenAI / Ollama\n  Gemini / Claude / DeepSeek)"]:::module

        VectorMod["VectorStoreModule\n• pgvector shard tables\n• IVFFlat indexes\n• Hybrid search (cosine + FTS)\n• Reciprocal rank fusion"]:::module

        SettingsMod["SettingsModule\n• CRUD ai_models\n• CRUD term_aliases\n• config/rag.php defaults\n• Settings JSONB overrides"]:::config
    end

    subgraph Storage["PostgreSQL 16 + pgvector 0.6+"]
        Relational["Relational tables:\nusers · documents · document_chunks\nchat_sessions · chat_messages\nai_models · term_aliases\npersonal_access_tokens"]:::storage
        Vectors["Vector shards:\nve_{384,768,1024,1536,3072}\nembedding vector(N) · model_name\ncontent_hash · IVFFlat index"]:::storage
    end

    subgraph AI["AI Providers"]
        OpenAI["OpenAI\ntext-embedding-3-small\ngpt-4o"]:::external
        Ollama["Ollama (local)\nnomic-embed-text\nqwen3.5:9b"]:::external
        Gemini["Gemini\ntext-embedding-004\ngemini-2.5-flash"]:::external
        Claude["Claude\nclaude-sonnet-4-5"]:::external
        DeepSeek["DeepSeek\ndeepseek-chat"]:::external
    end

    subgraph Infra["Infrastructure"]
        Redis["Redis\nCache · Queue · Sessions"]:::infra
        Filesystem["Filesystem\nDocument file storage"]:::infra
    end

    Frontend -->|"HTTP / SSE\nAuthorization: Bearer"| Backend

    UserMod --> DocMod
    DocMod --> ChatMod
    ChatMod --> EmbedMod
    ChatMod --> LLMMod
    ChatMod --> VectorMod
    EmbedMod --> VectorMod

    SettingsMod -.->|"reads config"| DocMod
    SettingsMod -.->|"reads config"| ChatMod
    SettingsMod -.->|"reads config"| EmbedMod
    SettingsMod -.->|"reads config"| LLMMod

    Backend -->|"reads/writes"| Storage
    Backend -->|"cache / queue"| Redis
    DocMod -->|"stores files"| Filesystem

    EmbedMod --> OpenAI
    EmbedMod --> Ollama
    EmbedMod --> Gemini
    LLMMod --> OpenAI
    LLMMod --> Ollama
    LLMMod --> Gemini
    LLMMod --> Claude
    LLMMod --> DeepSeek
```

**flow diagram:**

```mermaid
flowchart TD
    classDef user fill:#e8f5e9,stroke:#2e7d32,stroke-width:2px,color:#1b5e20
    classDef process fill:#e3f2fd,stroke:#1565c0,stroke-width:1px,color:#0d47a1
    classDef async fill:#fff3e0,stroke:#e65100,stroke-width:1px,color:#bf360c
    classDef storage fill:#f3e5f5,stroke:#6a1b9a,stroke-width:1px,color:#4a148c
    classDef decision fill:#fce4ec,stroke:#c62828,stroke-width:1px,color:#b71c1c
    classDef config fill:#f5f5f5,stroke:#616161,stroke-width:1px,color:#212121,stroke-dasharray:4 2
    classDef refusal fill:#ffebee,stroke:#d32f2f,stroke-width:2px,color:#b71c1c
    classDef api fill:#e8eaf6,stroke:#283593,stroke-width:1px,color:#1a237e

    Register["Register\nPOST /api/auth/register"]:::user --> Login["Login\nPOST /api/auth/login"]:::process
    Login --> Token["Receive API token\n80-char hex string"]:::process
    Token --> Bearer["Authorization: Bearer {token}"]:::process

    Bearer --> Upload["Upload document\nPOST /api/documents\nPDF / DOCX / TXT / CSV / MD"]:::user
    Upload --> Validate["Validate:\n• File size < 50MB\n• Allowed MIME type\n• User authenticated"]:::process
    Validate --> CheckHash["SHA-256 hash → check file_hash"]:::process
    CheckHash -->|"Duplicate"| Conflict["409 Conflict:\n'Document already exists'"]:::refusal
    CheckHash -->|"New file"| CreateRec["Create pending document\n(status: processing)"]:::process
    CreateRec --> DispatchJob["Dispatch ProcessDocumentJob\n(Laravel queue — async)"]:::async

    ConfigFile["config/rag.php — global defaults:\n• Provider selection\n• chunk_size/overlap\n• similarity_threshold\n• topK / MMR params"]:::config -.-> DispatchJob

    TermAliases["term_aliases table:\n• alias → canonical\n• Redis-cached 24h"]:::config -.->|"alias expansion"| DispatchJob

    DispatchJob --> Extract["Extract text\nsmalot/pdfparser (PDF)\nphpoffice/phpword (DOCX)\nraw (TXT/CSV/MD)"]:::async
    Extract --> Chunk["Recursive character splitter\n1000 chars · 200 overlap"]:::async
    Chunk --> BatchEmbed["Batch embed (100/chunk)\nUses doc's embedding_model_id\nMD5-cached 24h"]:::async

    AiModelDb["ai_models table:\n• provider • model\n• dims • api_key\n• settings JSONB overrides\n• is_active + sort_order"]:::config -.->|"reads active model"| BatchEmbed

    BatchEmbed --> Upsert["Upsert vectors to ve_{dim}\nINSERT … ON CONFLICT DO UPDATE"]:::async
    Upsert --> UpdateFTS["Update tsv_content\n→ to_tsvector('english')"]:::async
    UpdateFTS --> MarkComplete["Mark document completed\nstatus = 'completed'"]:::async

    MarkComplete --> Ask["User asks question\nPOST /api/chat { question }"]:::user
    Ask --> ResolveSession["Resolve/create chat_session"]:::process
    ResolveSession --> CheckLimit["Check session msg count\nmax 100"]:::process
    CheckLimit -->|"Exceeded"| SessionFull["Return: Session limit reached"]:::refusal
    CheckLimit -->|"OK"| SaveQuestion["Save user message"]:::process
    SaveQuestion --> AliasExpand["Term alias expansion:\n• expandText() → substitute\n• expandFtsQuery() → OR canonical"]:::process

    TermAliases -.->|"reads"| AliasExpand

    AliasExpand --> EmbedQuestion["Embed question\n(MD5-cached 24h)"]:::process

    AiModelDb -.->|"reads active model"| EmbedQuestion

    EmbedQuestion --> SearchType{"Search\nmode?"}:::decision
    SearchType -->|"hybrid"| VecSearch["Vector cosine search\n<=> distance · ORDER BY · topK"]:::process
    SearchType -->|"hybrid"| FTSSearch["Full-text search\nplainto_tsquery · ts_rank"]:::process
    SearchType -->|"vector"| VecOnly["Vector cosine search\n(same)"]:::process
    VecSearch & FTSSearch --> Fusion["Reciprocal rank fusion"]:::process
    VecOnly --> Fusion

    Fusion --> FilterResults["Filter chunks:\n• threshold ≥ 0.65\n• dynamic elbow (start 0.20)\n• document filter"]:::process
    FilterResults --> NoChunks{"Any chunks\npass?"}:::decision
    NoChunks -->|"No"| Refusal["Return: I cannot answer\nNo LLM call → saves tokens"]:::refusal
    NoChunks -->|"Yes"| MMR{"MMR\nenabled?"}:::decision
    MMR -->|"Yes"| MMRReRank["MMR re-rank\nλ = 0.7 · deduplicate"]:::process
    MMR -->|"No"| Truncate
    MMRReRank --> Truncate
    Truncate["Truncate context\n→ max_context_tokens"]:::process
    Truncate --> BuildPrompt["Build LLM prompt\nsystem + context + question"]:::process

    BuildPrompt --> QExpand{"Query\nexpansion?"}:::decision
    QExpand -->|"Yes"| ExpandQueries["Generate N reformulated\nqueries → search → merge"]:::process
    QExpand -->|"No"| StreamLLM
    ExpandQueries --> StreamLLM

    AiModelDb -.->|"reads active model"| StreamLLM

    StreamLLM["Call LLM · temperature 0.3\nSSE stream /api/chat/stream\nLLMService.complete()"]:::process
    StreamLLM --> AttachSources["Attach sources:\ndoc_id · title · page · score · excerpt"]:::process
    AttachSources --> SaveAssistant["Save assistant message\ncontent + sources (JSONB)"]:::process
    SaveAssistant --> Response["Return to user\n{ message, session_id, sources }"]:::user

    sublegend["───  direct flow\n- - -  config/registry read\n────  Storage layer"]:::config
```

---

## Documentation

| Doc | What's in it |
|-----|--------------|
| [SETUP_GUIDE.md](SETUP_GUIDE.md) | Install, environment, run, verify, Ollama setup, troubleshooting |
| [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) | Module layout, frontend organization, design system, dependency graph |
| [PROJECT_ROUTES.md](PROJECT_ROUTES.md) | All API endpoints + SPA routes with payloads and response shapes |
| [BUSINESS_LOGIC.md](BUSINESS_LOGIC.md) | RAG pipeline, document lifecycle, session rules, auth, edge cases |
| [PROJECT_RULES.md](PROJECT_RULES.md) | Coding standards (typing, DI, naming, testing, module isolation) |
| [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) | Per-table column reference |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production deployment notes |
| [CLAUDE.md](CLAUDE.md) / [AGENTS.md](AGENTS.md) | AI-agent reference for working in this codebase |
| [LEARNING.md](LEARNING.md) | Tutorial-style walkthrough of the codebase for new developers |

---

## Project conventions

- **Strict types** on every PHP file (`declare(strict_types=1);`).
- **ULIDs everywhere** as primary keys (no auto-increment integers).
- **No DB-level FK constraints** — relationships enforced in application code (intentional; supports module isolation).
- **Service layer is the only thing that touches Models** — controllers validate and dispatch; repositories don't bypass services.
- **Chunk metadata JSONB** — every document chunk carries structured metadata (user_id, user_name, project, report_date, document_title, section, page_number) enabling precision filtering via `@>` jsonb operators.
- **Migrations consolidated into base** — no standalone "add column" migrations; all columns defined in the initial create-table migrations to keep migration history clean.
- **One Resource per route** — never share API resources between endpoints.
- **Design tokens, not raw colors** — every Vue component uses `brand-*` / `surface-*` / `success-*` / `warning-*` / `danger-*` semantic classes; raw Tailwind palette names (`blue-`, `gray-`, `red-`) don't appear in `resources/`.
- **No raw `<button>` elements** — every clickable element is a reusable component (`AppButton`, `AppConfirm`, etc.).

See [PROJECT_RULES.md](PROJECT_RULES.md) for the full list.

---

## License

See [LICENSE](LICENSE).

---

## Acknowledgments

- [pgvector](https://github.com/pgvector/pgvector) — the extension that makes Postgres usable as a vector store.
- [Trix](https://trix-editor.org/) — rich-text editor used for document and model descriptions.
- [Laravel](https://laravel.com), [Vue](https://vuejs.org), [Tailwind CSS](https://tailwindcss.com), [Pinia](https://pinia.vuejs.org), [Pest](https://pestphp.com) — the foundation everything is built on.
