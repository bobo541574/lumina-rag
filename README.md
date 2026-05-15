# Lumina RAG

> **Self-hosted document Q&A.** Upload PDFs, DOCX, TXT, CSV, or Markdown — ask questions in natural language — get answers with citations to the exact source passages.

Built as a single Laravel monolith with a Vue 3 single-page frontend. Stores both relational data and vector embeddings in **PostgreSQL + pgvector** (no separate vector database). Supports **OpenAI** and **Ollama** as interchangeable embedding + LLM providers — bring your own API key, or run everything locally.

---

## Why

Most RAG systems are notebooks, scripts, or thin wrappers around a vendor API. Lumina is a **complete, deployable application** — multi-user auth, document lifecycle, chat sessions with history, observable async processing, runtime-tunable RAG settings, and a polished web UI. Drop it on a server and your team can start asking questions about their documents the same day.

---

## Features

- 📄 **Document ingestion pipeline** — upload → text extraction (PDF / DOCX / TXT / CSV / MD) → chunking → batch embedding → vector store. Async via Laravel queue, automatically retried on failure.
- 💬 **Chat with sources** — every answer comes with the chunks it was based on, including document title, page number, and similarity score. Streaming responses with a Stop button.
- 🔍 **Hybrid search by default** — combines vector cosine similarity with PostgreSQL full-text search, optionally re-ranked by Maximal Marginal Relevance (MMR) to reduce redundancy. Optional LLM-based query expansion.
- 🎛️ **Runtime-tunable** — every RAG knob (chunk size, similarity threshold, top-K, search mode, etc.) is editable from the Settings page without redeploying.
- 🤖 **Pluggable AI providers** — OpenAI and Ollama both implemented for embedding and LLM independently. Mix and match per-document via the AI Models registry.
- 👥 **Multi-user with API tokens** — register, login, per-user document and session ownership. Tokens are 80-char hex, sent as `Authorization: Bearer`.
- 🧹 **Real-world UI** — search, sort, filter, pagination, bulk select, optimistic confirms, toast notifications, skeleton loaders, full keyboard accessibility, mobile-responsive header.

---

## Tech stack

| Layer | Choice |
|-------|--------|
| Backend | **Laravel 13** (PHP 8.3+) — 7 internal modules with strict service contracts |
| Database | **PostgreSQL 16** + **pgvector 0.6+** — relational data and vectors in the same store |
| Cache / queue / sessions | **Redis** (recommended); database fallback |
| AI | **OpenAI** (`gpt-4o`, `text-embedding-3-small`) and **Ollama** (`llama3.1`, `nomic-embed-text`) |
| Document parsing | `smalot/pdfparser`, `phpoffice/phpword` |
| Frontend | **Vue 3** (Composition API + TypeScript) + **Pinia** + **vue-router** |
| Styling | **Tailwind v4** with a centralized `@theme` design-token system |
| Build | **Vite 8** |
| Testing | **Pest 4** (Feature + Unit) — SQLite `:memory:` test DB |

---

## Quick start

Prerequisites: PHP 8.3+, Composer, Node 20+, PostgreSQL 16 + pgvector, Redis (recommended), OpenAI API key (or Ollama running locally).

```bash
git clone <repo-url> && cd rag
composer run setup                       # install + .env + key + migrate + npm install + build
# Edit .env — at minimum set DB_PASSWORD and OPENAI_API_KEY
composer run dev                         # serves Laravel + queue + logs + Vite
```

Open [http://localhost:8000](http://localhost:8000), register, upload a document, ask a question.

For full setup (PostgreSQL install, pgvector, Ollama, troubleshooting), see [SETUP_GUIDE.md](SETUP_GUIDE.md).

---

## Architecture at a glance

```
┌────────────────────────────────────────────────────────┐
│                   Vue 3 SPA (resources/js/)            │
│   Chat │ Documents │ AI Models │ Settings │ Auth       │
└──────────────────────────┬─────────────────────────────┘
                           │  HTTP + SSE (Authorization: Bearer)
┌──────────────────────────▼─────────────────────────────┐
│                  Laravel 13 (modules/)                  │
│                                                         │
│  ┌─────────────┐  ┌─────────────┐  ┌────────────────┐ │
│  │ ChatModule  │  │DocumentMod. │  │ SettingsModule │ │
│  │  (RAG       │  │ (upload +   │  │ (runtime cfg + │ │
│  │   pipeline) │  │  pipeline)  │  │  AI registry)  │ │
│  └──────┬──────┘  └──────┬──────┘  └────────────────┘ │
│         │                │                              │
│  ┌──────▼────────────────▼──────┐                      │
│  │ EmbeddingModule │ LLMModule  │ ← OpenAI / Ollama    │
│  │ VectorStoreModule (pgvector) │                      │
│  └──────────────────────────────┘                      │
│                                                         │
│  UserModule (auth) — standalone                        │
└──────────────────────────┬──────────────────────────────┘
                           │
                ┌──────────▼──────────┐
                │ PostgreSQL 16       │
                │  + pgvector         │
                │  + IVFFlat index    │
                └─────────────────────┘
```

**Data flow on a question:**
1. SPA sends `POST /api/chat` with the question (and optional filters / chosen LLM).
2. ChatModule embeds the question, runs hybrid vector + FTS search across the user's documents (filterable).
3. Top-K chunks above the similarity threshold are MMR-re-ranked and concatenated into the LLM prompt.
4. LLM streams an answer back over SSE; sources arrive as a final event.
5. Both messages persist in `chat_messages`; the user sees the answer with citations.

If no chunks pass the threshold, the system returns *"I cannot answer this question based on the available documents"* without calling the LLM.

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

---

## Project conventions

- **Strict types** on every PHP file (`declare(strict_types=1);`).
- **ULIDs everywhere** as primary keys (no auto-increment integers).
- **No DB-level FK constraints** — relationships enforced in application code (intentional; supports module isolation).
- **Service layer is the only thing that touches Models** — controllers validate and dispatch; repositories don't bypass services.
- **One Resource per route** — never share API resources between endpoints.
- **Design tokens, not raw colors** — every Vue component uses `brand-*` / `surface-*` / `success-*` / `warning-*` / `danger-*` semantic classes; raw Tailwind palette names (`blue-`, `gray-`, `red-`) don't appear in `resources/`.
- **No raw `<button>` elements** — every clickable element is a reusable component (`AppButton`, `AppConfirm`, etc.).

See [PROJECT_RULES.md](PROJECT_RULES.md) for the full list.

---

## License

See [LICENSE](LICENSE) (or contact the maintainers — there's no license file checked in yet).

---

## Acknowledgments

- [pgvector](https://github.com/pgvector/pgvector) — the extension that makes Postgres usable as a vector store.
- [Trix](https://trix-editor.org/) — rich-text editor used for document and model descriptions.
- [Laravel](https://laravel.com), [Vue](https://vuejs.org), [Tailwind CSS](https://tailwindcss.com), [Pinia](https://pinia.vuejs.org), [Pest](https://pestphp.com) — the foundation everything is built on.
