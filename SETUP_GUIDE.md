# Setup Guide

End-to-end installation for local development. For production deployment see [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

---

## Prerequisites

| Tool | Version | Why |
|------|---------|-----|
| PHP | **8.3+** | Laravel 13 requires PHP 8.3 |
| Composer | 2.x | PHP dependency manager |
| Node.js | 20.x | Frontend build (Vite 8 + Vue 3) |
| npm | 10.x | Comes with Node 20 |
| PostgreSQL | **16** | Primary database |
| pgvector | 0.6+ | Vector similarity search (Postgres extension) |
| Redis | 7+ | Cache + sessions + queue (recommended) |
| Ollama | — | Default embedding + LLM provider (seeded models: `nomic-embed-text`, `all-MiniLM-L6-v2` for embedding; `qwen3.5:9b`, `gemma4:e4b` for LLM). Optional OpenAI API key if you want to use OpenAI models — configure via AI Models registry. |

You can substitute OpenAI for Ollama on either embedding or LLM independently — see "Using Ollama" below (or configure via the AI Models UI at `/settings/ai-models`).

---

## 1. PostgreSQL + pgvector

### Install Postgres 16

```bash
# Debian/Ubuntu
sudo apt install postgresql-16 postgresql-contrib

# macOS
brew install postgresql@16
brew services start postgresql@16
```

### Install pgvector

Most package managers ship a `postgresql-16-pgvector` package; otherwise build from source:

```bash
git clone https://github.com/pgvector/pgvector.git
cd pgvector
make
sudo make install
```

### Create database and enable the extension

```bash
sudo -u postgres psql
```

```sql
CREATE DATABASE lumina_rag;
\c lumina_rag
CREATE EXTENSION vector;
SELECT extname, extversion FROM pg_extension WHERE extname = 'vector';
```

(The default `.env.example` uses `DB_DATABASE=lumina_rag` — match here or update both.)

---

## 2. Project install

```bash
git clone <repo-url>
cd rag

# One-shot setup: composer install + .env + key:generate + migrate + npm install + build
composer run setup
```

Or step by step:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm install --ignore-scripts        # .npmrc disables postinstall scripts
npm run build
```

> **Why `--ignore-scripts`?** The repo's `.npmrc` sets `ignore-scripts=true` to keep installs reproducible. If you need scripts (e.g. for a native dep), pass `--include-scripts` explicitly.

---

## 3. Configure `.env`

Edit `.env` and fill these in:

```env
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=lumina_rag
DB_USERNAME=postgres            # or your role
DB_PASSWORD=                    # set if your role has one

# Required for OpenAI provider
OPENAI_API_KEY=sk-…

# Default RAG providers (override per-model via the AI Models registry once running)
RAG_EMBEDDING_PROVIDER=openai
RAG_EMBEDDING_MODEL=text-embedding-3-small
RAG_EMBEDDING_DIMENSIONS=1536

RAG_LLM_PROVIDER=openai
RAG_LLM_MODEL=gpt-4o

QUEUE_CONNECTION=database         # or redis if you have Redis up
CACHE_STORE=redis                 # array if no Redis (no caching → slower embeds)
SESSION_DRIVER=redis              # database/file ok if no Redis
```

### Full RAG knob list

All knobs read by [config/rag.php](config/rag.php) (most have sane defaults — set only what you need to change):

| Env var | Default | Purpose |
|---------|---------|---------|
| `RAG_EMBEDDING_PROVIDER` | `openai` | `openai` or `ollama` |
| `RAG_EMBEDDING_BASE_URL` | `http://localhost:11434` | Used by Ollama provider |
| `RAG_EMBEDDING_MODEL` | `text-embedding-3-small` | Embedding model name |
| `RAG_EMBEDDING_DIMENSIONS` | `1536` | Vector dimensions (must match the chosen model) |
| `RAG_EMBEDDING_BATCH_SIZE` | `100` | Texts per provider call |
| `RAG_EMBEDDING_CACHE_TTL` | `86400` (24h) | Embedding cache TTL |
| `RAG_EMBEDDING_TIMEOUT` | `30` | Request timeout (seconds) |
| `RAG_LLM_PROVIDER` | `openai` | `openai` or `ollama` |
| `RAG_LLM_BASE_URL` | `http://localhost:11434` | Ollama URL |
| `RAG_LLM_MODEL` | `gpt-4o` | LLM model name |
| `RAG_LLM_TEMPERATURE` | `0.3` | Generation temperature |
| `RAG_LLM_MAX_CONTEXT_TOKENS` | `4000` | Truncate context to fit |
| `RAG_LLM_TIMEOUT` | `60` | Request timeout |
| `RAG_VECTOR_DRIVER` | `pgsql` | Only `pgsql` is implemented |
| `RAG_VECTOR_INDEX_LISTS` | `100` | IVFFlat lists parameter |
| `RAG_SEARCH_MODE` | `hybrid` | `vector` / `fts` / `hybrid` |
| `RAG_SEARCH_TOP_K` | `5` | Chunks retrieved per query |
| `RAG_SEARCH_SIMILARITY_THRESHOLD` | `0.65` | Below this → "cannot answer" |
| `RAG_SEARCH_HYBRID_VECTOR_WEIGHT` | `0.7` | Hybrid scoring weight (vector) |
| `RAG_SEARCH_HYBRID_FTS_WEIGHT` | `0.3` | Hybrid scoring weight (FTS) |
| `RAG_SEARCH_MMR_ENABLED` | `true` | Re-rank top-K with MMR |
| `RAG_SEARCH_MMR_LAMBDA` | `0.7` | MMR diversity (0=diverse, 1=relevant) |
| `RAG_QUERY_EXPANSION_ENABLED` | `false` | LLM-based query expansion |
| `RAG_QUERY_EXPANSION_NUM_QUERIES` | `3` | Variants per query |
| `RAG_CHUNK_SIZE` | `1000` | Characters per chunk |
| `RAG_CHUNK_OVERLAP` | `200` | Overlap between chunks |
| `RAG_MAX_QUESTION_LENGTH` | `1000` | Question length cap |
| `RAG_MAX_MESSAGES_PER_SESSION` | `100` | Session message cap |
| `RAG_LOG_CHANNEL` | `rag` | Logging channel name |
| `RAG_LOG_LEVEL` | `info` | `debug` / `info` / `warning` / `error` |

> **Runtime override**: settings in the `settings` table override these env values without a redeploy. The Settings page in the SPA is the editor.

---

## 4. Seed data (optional but recommended)

```bash
php artisan db:seed
```

Creates:

| Module | Seed |
|--------|------|
| UserModule | 2 users with API tokens |
| ChatModule | 2 chat sessions with messages |
| DocumentModule | 1 document with 3 chunks |
| VectorStoreModule | Embeddings for the seeded chunks (skipped if pgvector isn't installed) |
| SettingsModule | Default RAG settings + a couple of AI model registry entries |

---

## 5. Run

### Single command (recommended)

```bash
composer run dev
```

Spawns four concurrent processes via `concurrently`:

| Name | Command | Default port |
|------|---------|--------------|
| `server` | `php artisan serve` | http://localhost:8000 |
| `queue` | `php artisan queue:listen --tries=1 --timeout=0` | — |
| `logs` | `php artisan pail` | streams logs to stdout |
| `vite` | `npm run dev` | http://localhost:5173 (HMR for the SPA) |

### Manually (separate terminals)

```bash
# Terminal 1 — Laravel
php artisan serve

# Terminal 2 — queue worker (required for document processing)
php artisan queue:work

# Terminal 3 — Vite dev server (HMR)
npm run dev
```

> **Without the queue worker**, document uploads stay in `pending` forever — the upload endpoint returns immediately and dispatches a `ProcessDocumentJob` that needs a worker to pick it up.

---

## 6. First run — verify install

### Health check

```bash
curl http://localhost:8000/api/health
# {"status":"ok"}
```

### Test pgvector

```sql
-- in psql
SELECT '[1,2,3]'::vector <=> '[1,2,3]'::vector;
-- expects: 0
```

### Register + login

```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Alice","email":"alice@example.com","password":"password123"}'

# Save the token from the response, then:
TOKEN=...
curl http://localhost:8000/api/auth/me -H "Authorization: Bearer $TOKEN"
```

### Browser smoke test

Open http://localhost:8000 and:

1. Register / sign in.
2. Go to **Documents** → upload a PDF, DOCX, TXT, CSV, or Markdown file.
3. Wait for status to flip from `pending` → `processing` → `completed` (watch the queue terminal for activity).
4. Go to **Chat** → ask a question about the document.
5. Verify the answer comes back with sources cited beneath it.

---

## Using Ollama (no OpenAI account needed)

[Ollama](https://ollama.com) lets you run open-source models locally. You can use it for embeddings, LLM, or both.

### 1. Install + pull models

```bash
# install Ollama (see ollama.com/download)
ollama serve                        # in the background

# embedding model (768 dims for nomic-embed-text)
ollama pull nomic-embed-text

# LLM
ollama pull llama3.1:8b
```

### 2. Configure via the AI Models registry (recommended)

Once the app is running and you've signed in:

1. Go to **AI Models** in the header.
2. Click **+ Add Model** for each — set provider to `ollama`, base URL to `http://localhost:11434`, and the model name.
3. For embedding: set Dimensions to match the model (e.g. 768 for `nomic-embed-text`).
4. Mark the new entries as Active.

### 3. Or via env vars (defaults for all queries)

```env
RAG_EMBEDDING_PROVIDER=ollama
RAG_EMBEDDING_BASE_URL=http://localhost:11434
RAG_EMBEDDING_MODEL=nomic-embed-text
RAG_EMBEDDING_DIMENSIONS=768

RAG_LLM_PROVIDER=ollama
RAG_LLM_BASE_URL=http://localhost:11434
RAG_LLM_MODEL=llama3.1:8b
```

> Documents embedded with one model **stay** linked to that model — switching the default doesn't re-embed existing documents. Re-uploads or per-document selection lets you mix models.

---

## Running tests

```bash
composer run test               # config:clear + full Pest suite
php artisan test                # same, without the config clear
php artisan test --filter=ChatApiTest         # one test class
php artisan test --testsuite=Unit             # one suite (Unit | Feature)
php artisan test --parallel                   # parallelize across cores
```

Test environment uses SQLite `:memory:` and `QUEUE_CONNECTION=sync` (see `phpunit.xml`). The `RefreshDatabase` trait is applied to the Feature suite only.

---

## Code formatting

```bash
./vendor/bin/pint                  # format all PHP files (Laravel Pint, opinionated)
./vendor/bin/pint --dirty          # only files changed against the current branch
```

There's no JS formatter wired (no Prettier config) — Vue files follow the patterns in existing components.

---

## Troubleshooting

### pgvector extension not found

```bash
psql -U postgres -d lumina_rag -c "SELECT * FROM pg_available_extensions WHERE name = 'vector';"
```

If empty, pgvector wasn't installed for this Postgres version. Reinstall and restart Postgres.

### Documents stuck on `pending`

The queue worker isn't running. Start it:

```bash
php artisan queue:work
```

If it processes but immediately fails, check `failed_jobs`:

```bash
php artisan queue:failed
php artisan queue:retry all
```

### "OpenAI API errors"

```bash
echo $OPENAI_API_KEY                                          # is the key set?
curl https://api.openai.com/v1/models -H "Authorization: Bearer $OPENAI_API_KEY"   # does it work?
```

Check usage limits / billing on the OpenAI dashboard. The app surfaces the underlying error via toast and in `storage/logs/`.

### `composer run dev` exits silently

`concurrently` requires Node — make sure `npm install --ignore-scripts` ran. Try the manual three-terminal flow to isolate which process is failing.

### Vite HMR not connecting

The Vite dev server runs on port 5173 by default. If `php artisan serve` is on `localhost:8000` but you opened the SPA on a different host, set `APP_URL` in `.env` to match — Laravel's `vite()` helper uses it to construct asset URLs.

---

## What to read next

- [README.md](README.md) — project overview and tech stack
- [PROJECT_STRUCTURE.md](PROJECT_STRUCTURE.md) — module layout and file organization
- [PROJECT_ROUTES.md](PROJECT_ROUTES.md) — API + SPA route reference
- [BUSINESS_LOGIC.md](BUSINESS_LOGIC.md) — domain rules, RAG pipeline, sessions, auth
- [PROJECT_RULES.md](PROJECT_RULES.md) — coding standards
- [docs/DATABASE_SCHEMA.md](docs/DATABASE_SCHEMA.md) — table-by-table schema
- [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) — production deployment
