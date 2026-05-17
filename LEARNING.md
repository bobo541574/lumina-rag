# Lumina RAG — Learning Guide (Tutorial Style)

> ဒီ document က codebase ကို သင်ကြားရေးပုံစံနဲ့ ရှင်းပြထားတာပါ။ ဘာကြောင့် ဒီလိုလုပ်ထားလဲ၊ ဘာပြဿနာကိုဖြေရှင်းတာလဲဆိုတာကို အဓိကထားပါတယ်။

---

## 1. Project Overview — ဒီ Project က ဘာလဲ?

**ပြဿနာ:** ကုမ္ပဏီတွေမှာ PDF, DOCX, စာရွက်စာတမ်းတွေ အများကြီးရှိတယ်။ ဒါပေမယ့် အဲဒီထဲက အချက်အလက်တွေကို ပြန်ရှာဖို့ခက်တယ်။ တစ်ခုချင်းဖွင့်ကြည့်နေရတယ်။

**အဖြေ:** RAG (Retrieval-Augmented Generation) — စာရွက်စာတမ်းတွေကို တင်လိုက် → မေးခွန်းမေး → စာရွက်ထဲကနေ ဆိုင်ရာအပိုင်းကိုရှာပြီး AI နဲ့အဖြေထုတ်ပေးတယ်။

### RAG ဆိုတာဘာလဲ? (ရှင်းရှင်းလေးရှင်းပြရရင်)

LLM (GPT, Qwen, etc.) တွေက သူတို့လေ့ကျင့်ထားတဲ့ data ထဲကပဲ ဖြေနိုင်တယ်။ ကိုယ့်ကုမ္ပဏီရဲ့ လျှို့ဝှက်စာရွက်တွေအကြောင်း မသိဘူး။ ဒါကြောင့်:

1. ကိုယ့်စာရွက်တွေကို ကြိုတင်ပြင်ဆင်ထားတယ် (text ထုတ် → အတုံးလေးတွေဖြတ် → vector အဖြစ်ပြောင်း)
2. မေးခွန်းမေးလာရင် အဲဒီအတုံးတွေထဲက ဆိုင်ရာတွေကို ရှာတယ် (vector search + text search)
3. တွေ့တဲ့အတုံးတွေကို LLM ဆီ ကော်ပီကူးထည့်ပေးတယ် ("ဒီအချက်အလက်တွေကိုကြည့်ပြီး ဖြေပေးပါ" ဆိုပြီး)
4. LLM က သူ့ကိုယ်ပိုင်အသိမသုံးပဲ ကိုယ်ပေးထားတဲ့ data ထဲကနေ ဖြေတယ်

**ဒါက ဘာလို့ LLM ကိုတန်းမေးတာထက် ကောင်းလဲ?**
- LLM က ကိုယ့် company ရဲ့ internal data ကို မသိဘူး
- LLM က အချက်အလက်တွေကို လိမ်လည်ဖြေနိုင်တယ် (hallucination)
- RAG က ဘယ်စာရွက်ကနေ ဖြေတယ်ဆိုတာ ပြတယ် (source citation)

---

## 2. Tech Stack — ဘာတွေသုံးထားလဲ၊ ဘာလို့သုံးတာလဲ?

### Laravel (PHP)
PHP က ရွေးချယ်စရာမဟုတ်ဘူး — Laravel က ရွေးချယ်စရာ။ ဘာလို့ Laravel လဲ?
- Eloquent ORM — DB queries တွေကို လွယ်လွယ်ကူကူရေးလို့ရတယ်
- Queue system — Document processing က heavy ဖြစ်တယ် (PDF ကနေ text ထုတ်၊ chunk ဖြတ်၊ vector ပြောင်း) — ဒါတွေကို background job အနေနဲ့ queue ထဲထည့်ပြီး async လုပ်လို့ရတယ်
- Service Container — Dependency injection က testing အတွက်အဆင်ပြေတယ်

### PostgreSQL + pgvector
**ဘာလို့ separate vector database မသုံးတာလဲ?** (ဥပမာ Pinecone, Weaviate)

ကိုယ့် data က vector ရော metadata ရော တွဲပြီးသုံးရတယ်။ PostgreSQL မှာဆိုရင်:
- Document ရဲ့ project, user, date တွေက SQL WHERE နဲ့လွယ်လွယ်ကူကူစစ်လို့ရတယ်
- Vector search ကော FTS ကော အတူတူတွဲသုံးလို့ရတယ် (hybrid search)
- DB ၂ ခု manage လုပ်စရာမလိုဘူး
- pgvector က production-ready ဖြစ်နေပြီ

### Ollama (သို့) OpenAI
နှစ်မျိုးလုံး support လုပ်ထားတယ်။ ဘာလို့ interface တစ်ခုတည်းနဲ့ထားလဲ?
- Provider တွေက API format မတူဘူး (OpenAI က chat completion, Ollama က /api/chat)
- ဒါပေမယ့် အပေါ်ယံကနေကြည့်ရင် တူတူပဲ — "text ထည့် → vector ထုတ်" (embedding) သို့ "prompt ထည့် → text ထုတ်" (LLM)
- Interface/Implementation pattern သုံးထားတယ် — ဘယ် provider ပြောင်းပြောင်း calling code က ပြောင်းစရာမလိုဘူး

**ဘာလို့ raw cURL သုံးတာလဲ? ဘာလို့ official SDK မသုံးတာလဲ?**
SDK တွေက:
- Dependencies တွေများတယ် (guzzlehttp, psr-7, etc.)
- Version တက်လိုက်ကျလိုက်နဲ့ breaking changes တွေရှိတယ်
- တကယ်တော့ AI API တွေက simple HTTP POST ပဲ — curl နဲ့တန်းခေါ်ရတာ ပိုမြန်တယ်၊ ပိုရှင်းတယ်

### Vue 3 + TypeScript + Tailwind
Frontend framework တွေအများကြီးရှိပေမယ့်:
- Vue က React ထက်သင်ရတာလွယ်တယ် (အထူးသဖြင့် Composition API)
- TypeScript က autocompletion + type safety အတွက်
- Tailwind v4 — utility classes တွေနဲ့ မြန်မြန်ဆန်ဆန် UI ဆောက်လို့ရတယ်

### Key PHP Libraries (နှစ်ခုပဲရှိတယ် — ဘာလို့?)
- `smalot/pdfparser` — PDF ကနေ text ထုတ်ဖို့
- `phpoffice/phpword` — DOCX ကနေ text ထုတ်ဖို့

ဒါပဲရှိတယ်။ **ဘာလို့ဒီလောက်နည်းတာလဲ?** ဘာလို့ langchain လိုမျိုး LLM framework မသုံးတာလဲ?
- RAG pipeline က ရှုပ်ထွေးတာမဟုတ်ဘူး — embed → search → LLM call → respond ပဲ
- Framework တွေက abstraction တွေအများကြီးထပ်ထားတယ် — debugging ခက်တယ်
- ကိုယ်တိုင်ရေးရင် ဘာဖြစ်နေလဲဆိုတာ ရှင်းရှင်းလင်းလင်းသိတယ်
- Codebase က 1500 line ပဲရှိတယ် (RAGPipelineService တစ်ခုလုံး) — ဒါက framework သုံးရင် မဖြစ်နိုင်ဘူး

---

## 3. Project Structure — ဘာလို့ဒီလိုဖွဲ့စည်းထားတာလဲ?

### Module System (ဘာလို့ ၇ ခုကွဲထားတာလဲ?)

RAG system က component ၃ ခုရှိတယ်:
- **Embedding** — text → vector ပြောင်း
- **Vector Store** — vector တွေကို သိမ်း + ရှာ
- **LLM** — အဖြေထုတ်

ဒီ ၃ ခုက အချင်းချင်း မှီခိုမှုမရှိဘူး။ ဒါကြောင့် သပ်သပ်ခွဲထားတယ်။

ဘယ် module တွေက ဘယ် module တွေကိုသုံးလဲ:
```
ChatModule → EmbeddingModule + VectorStoreModule + LLMModule
DocumentModule → EmbeddingModule + VectorStoreModule
SettingsModule → standalone
UserModule → standalone
```

**ဒီပုံစံရဲ့ အားသာချက်:**
- Component တစ်ခုကို ပြင်ရင် ကျန်တာတွေ မထိခိုက်ဘူး
- ဥပမာ — vector store ကို pgvector ကနေ another DB ပြောင်းချင်ရင် `VectorStoreModule` ကိုပဲပြင်ရတယ်
- Testing လုပ်ရတာလွယ်တယ် (component တစ်ခုချင်းစီကို သပ်သပ် mock လုပ်လို့ရ)

### ဘာလို့ Repository layer မပါတာလဲ?

Standard Laravel မှာ Controller → Service → Repository → Model ဆိုပြီးရှိတယ်။ ဒါပေမယ့် ဒီ project မှာ **Service က Model ကိုတိုက်ရိုက်သုံးတယ်**။

ဘာလို့လဲ?
- Repository pattern က ရှုပ်ထွေးတယ် — abstraction တစ်ထပ်ထပ်ထည့်တာ
- ဒီ project က CRUD-heavy မဟုတ်ဘူး — RAG logic က အဓိက
- တကယ်တော့ `RAGPipelineService` က Eloquent queries တွေကို တိုက်ရိုက်သုံးတယ် (User::where(), Document::query())
- ဒါက ပိုရှင်းတယ် — ဘာ query ပြေးလဲဆိုတာ မျက်စိရှေ့မှာမြင်ရတယ်

### Module တစ်ခုရဲ့ standard structure

```
modules/{Name}Module/
├── Controllers/     → HTTP layer (validation + dispatch)
├── Services/        → Business logic (Models တွေကို ဒီမှာပဲ touch တယ်)
├── Contracts/       → Interfaces (ServiceProvider မှာ bind)
├── Models/          → Eloquent models
├── Requests/        → FormRequest validation
├── Routes/          → API route definitions
├── Providers/       → ServiceProvider registration
├── Jobs/            → Background jobs (DocumentModule မှာပဲရှိ)
├── Commands/        → Artisan commands
└── database/
    ├── migrations/
    └── Seeders/
```

---

## 4. Database Schema — ဘာလို့ဒီလိုဒီဇိုင်းလုပ်ထားတာလဲ?

### ULID (ဘာလို့ auto-increment integer မသုံးတာလဲ?)

Auto-increment ID တွေက:
- Predictable ဖြစ်တယ် — ပထမ document က 1, ဒုတိယက 2 (ပြင်ပလူက ခန့်မှန်းလို့ရ)
- Merge conflict ဖြစ်နိုင်တယ် — branch ၂ ခုမှာ တူညီတဲ့ ID ရနိုင်တယ်
- Distributed system အတွက် မကောင်းဘူး

ULID တွေက:
- Time-sortable — ID အလိုက် sorting လုပ်ရင် created_at အတိုင်းရတယ်
- URL-safe — base32 encoding
- Collision-resistant — 26 characters, random enough

### ဘာလို့ DB-level foreign key constraints မပါတာလဲ?

```sql
-- ဒီလိုမျိုး DB constraint မရှိဘူး
FOREIGN KEY (user_id) REFERENCES users(id)
```

ဘာလို့လဲ?
- ULID တွေက string တွေဖြစ်တယ် — FK constraint တွေက performance ကျစေတယ်
- အနာဂတ်မှာ sharding လုပ်ရင် FK တွေက အဆင်မပြေဘူး
- Application code မှာပဲ စစ်တယ် (Service layer က စစ်တယ် — user မရှိရင် မသိမ်းဘူး)
- Migrations တွေက ပိုလွယ်တယ် (constraint error တွေမရှိတော့)

### Vector Shard Tables — ဘာလို့ table တစ်ခုတည်းမသုံးတာလဲ?

pgvector ရဲ့ `vector(N)` type က dimension အရေအတွက်ကို column creation မှာတည်ဆောက်တယ်။ တစ်ခါသတ်မှတ်ပြီးရင် ပြောင်းလို့မရဘူး။

ဒါပေမယ့် model တစ်ခုက 768d, နောက်တစ်ခုက 1536d — မတူဘူး။ ဒါကြောင့်:
```
ve_768    ← nomic-embed-text (768 dimensions)
ve_1024   ← mxbai-embed-large
ve_1536   ← text-embedding-3-small
ve_3072   ← text-embedding-3-large
```

**ဘာလို့ EAV (Entity-Attribute-Value) style မသုံးတာလဲ?** (ဥပမာ `(chunk_id, dimension_index, value)`)
- EAV က performance ဆိုးတယ် — vector ရှာဖို့ row 768 ခုကို join ရမယ်
- pgvector ရဲ့ native vector operations သုံးလို့မရတော့ဘူး
- IVFFlat index က တစ်ခုချင်းစီအတွက် သပ်သပ်ဆောက်လို့ရ

### တခြားထူးခြားချက်တွေ

**`tsv_content` column in `document_chunks`:**
- PostgreSQL full-text search vector
- `to_tsvector('english', content)` နဲ့ auto-populate
- `plainto_tsquery('english', ...)` နဲ့ search
- **ဘာလို့ `english` config သုံးတာလဲ?** `simple` က stopword တွေကိုမဖယ်ဘူး၊ stemming မလုပ်ဘူး။ `english` က "running" → "run" ပြောင်းပေးတယ်

**`settings` JSONB in `ai_models`:**
- Per-model overrides အတွက် (top_k, similarity_threshold, search_mode, etc.)
- Config file က fallback — ai_models DB table က source of truth
- **ဒီပုံစံရဲ့ အားသာချက်:** Admin က UI ကနေ တစ်ခါတည်းပြင်လို့ရတယ် — `.env` ပြင်စရာ၊ deploy ပြန်လုပ်စရာမလိုဘူး

---

## 5. Module System — Module တစ်ခုချင်းစီက ဘာလုပ်လဲ၊ ဘာလို့ဒီလိုလုပ်ထားလဲ?

### ChatModule — RAG Orchestration

ဒါက project ရဲ့ဗဟို။ `RAGPipelineService.php` (1500+ lines) က ဒီ flow ကို orchestrate လုပ်တယ်:

```
Question → extract filters → embed → hybrid search → dynamic threshold → MMR → LLM → save
```

**ဘာလို့ Controller က thin လဲ?**
Controller က validation လုပ်ပြီး Service ကိုခေါ်ရုံပဲ။ Business logic က Service ထဲမှာ။
- Testing လုပ်ရတာလွယ်တယ် — Service ကိုပဲ unit test လုပ်လို့ရ
- Controller က HTTP အတွက်ပဲ — business logic နဲ့မရောဘူး

**ဘာလို့ streaming ကော non-streaming ကော ရှိတာလဲ?**
- Non-streaming: အဖြေအပြည့်စောင့်ပြီးမှ ပြန် (API consumer တွေအတွက်)
- Streaming: အဖြေကို တစ်ပိုင်းချင်းပြန် (UI အတွက် — user က typing effect မြင်ရတယ်)
- ChatController က `?stream=true` query param ကိုကြည့်ပြီး ဘယ် method ခေါ်မလဲဆုံးဖြတ်တယ်

### DocumentModule — Upload → Process Pipeline

**ဘာလို့ job queue သုံးတာလဲ?**
Document processing က တစ်ခါတည်းမပြီးဘူး:
- PDF (100 pages) ကနေ text ထုတ်ရတာ 5-10 စက္ကန့်ကြာတယ်
- Text ကို chunk တွေဖြတ်တယ်
- Chunk အရေအတွက်ပေါ်မူတည်ပြီး embedding ကို batch နဲ့ခေါ်တယ် (API call တစ်ခုက 1-3 စက္ကန့်)
- Vector upsert လုပ်တယ်

User က upload လုပ်ပြီး ၃၀ စက္ကန့်စောင့်နေရင် ဆိုးတယ်။ ဒါကြောင့်:
1. Upload → "pending" status နဲ့ record လုပ် → 201 ပြန်
2. Job ကို queue ထဲထည့်
3. User က status ကို poll လုပ်လို့ရတယ် (GET /api/documents/{id}/status)
4. Job က background မှာ processing လုပ်

### EmbeddingModule — Text → Vector

**ဘာလို့ MD5 cache လုပ်တာလဲ?**
Embedding API calls တွေက:
- စျေးကြီးတယ် (OpenAI ဆိုရင် token တွေအတွက်ပိုက်ဆံပေးရ)
- နှေးတယ် (network round-trip)
- တူတဲ့ text ကို ထပ်ခါထပ်ခါ embed လုပ်စရာမလိုဘူး

Cache key: `md5($text)` — 24h TTL

**ဘာလို့ `embedBatch()` ဆိုတဲ့ method ရှိတာလဲ?**
Document တစ်ခုမှာ chunk ၁၀၀ ရှိတယ်ဆိုပါစို့။ တစ်ခုချင်းခေါ်ရင် API call ၁၀၀ လိုမယ်။
Batch နဲ့ဆို:
- Cache ကိုအရင်စစ် → မရှိသေးတာတွေကိုပဲ API ကိုပို့
- API ကို တစ်ခါတည်း array လိုက်ပို့ (OpenAI: `input` array, Ollama: sequential)
- Configurable batch size (default: 100)

### LLMModule — အဖြေထုတ်

`LLMService.php` က context ကို assemble လုပ်တယ်:

```php
// assemblePrompt က ဒီလိုပုံစံမျိုး prompt ဆောက်တယ်
"Context:
---
[Source: Q3 Report.pdf (92%)], Page 12
Revenue in Q3 reached $45.2 million...

[Source: Q3 Report.pdf (88%)], Page 14
Operating expenses increased by 12%...
---

Question: What was the revenue in Q3?

Answer:"
```

**ဘာလို့ `max_context_tokens` နဲ့ truncate လုပ်တာလဲ?**
LLM တွေမှာ context window limit ရှိတယ်။ Chunk တွေအားလုံးထည့်လိုက်ရင် limit ကျော်သွားနိုင်တယ်။
ဒါကြောင့်:
1. Chunks တွေကို similarity score အရ sort လုပ် (အကောင်းဆုံးက အရင်)
2. တစ်ခုချင်းထည့် → token count စစ်
3. Limit ကျော်တော့မယ်ဆို ရပ်လိုက်

### VectorStoreModule — Search

**ဘာလို့ hybrid search (vector + FTS) သုံးတာလဲ?**

**Vector search** (cosine similarity):
- "ကားအသစ်ရဲ့ဈေးနှုန်း" နဲ့ "automobile pricing" ကို ဆက်စပ်ပေးနိုင်တယ် (semantic matching)
- ဒါပေမယ့် specific keyword ("Orion-2024-Q3") ကို ရှာမတွေ့ဘူး

**FTS** (full-text search):
- "Orion-2024-Q3" အတိအကျပါတဲ့ chunk ကိုရှာနိုင်တယ် (keyword matching)
- ဒါပေမယ့် "ကားအသစ်" နဲ့ "automobile" ကို ဆက်စပ်မပေးနိုင်ဘူး

**Hybrid (RRF fusion):**
နှစ်ခုလုံးကို parallel ပြေး → rank တွေကိုပေါင်း → အကောင်းဆုံးကိုယူ
```php
// RRF: reciprocal rank fusion
$score = 1 / (60 + $vectorRank) + 1 / (60 + $ftsRank)
// 60 = constant (RRF parameter)
```

### SettingsModule — AI Model Registry

**ဘာလို့ Model Registry လိုတာလဲ?**
- Document တစ်ခုစီက မတူတဲ့ embedding model သုံးနိုင်တယ်
- LLM ကိုလည်း ပြောင်းသုံးနိုင်တယ် (ဥပမာ code document ဆို qwen2.5-coder)
- တစ်ချိန်တည်းမှာ model ၂ ခု active ဖြစ်နေလို့ရတယ် (primary + fallback)

`is_active` + `sort_order` က ဘယ် model က active လဲဆုံးဖြတ်တယ်:
```php
AiModel::active()->embedding()->orderBy('sort_order')->first();
```

---

## 6. RAG Pipeline — အသေးစိတ် Code Walkthrough

ဒါက project ရဲ့အဓိက အပိုင်း — `RAGPipelineService.php` ကို step-by-step လိုက်ကြည့်ရအောင်။

### Step 1: `ask($question, $options)`

```php
public function ask(string $question, array $options = []): array
```

**ဘာလို့ parameter တွေက `$question` နဲ့ `$options` ပဲရှိတာလဲ?**
- Question က main input
- Options က session_id, user_id, document_filter, stream, llm_model_id — ဒါတွေက optional metadata

**ပထမဆုံး ဘာလုပ်လဲ?**
```php
$question = $this->normalizeQuestion($question);
```
- Trim + truncate (maxQuestionLength)
- `2026-04` → `2026-April` ပြောင်း (embedding model က "04" ကို month အဖြစ်နားမလည်ဘူး, "April" ကနားလည်တယ်)

```php
$session = $this->resolveSession($options['session_id'] ?? null, ...);
```
- session_id ပါရင် → DB ကနေရှာ (24h inactivity ဆို expire)
- session_id မပါရင် → အသစ်ဆောက်

```php
$this->checkMessageLimit($session);
```
- Session တစ်ခုမှာ message 100 ပဲရှိတယ်

### Step 2: Filter Extraction

```php
$autoFilters = $this->extractFiltersFromQuestion($question);
```

ဒီ method က မေးခွန်းထဲက filter တွေကို ထုတ်တယ်။

**ဘာလို့ filter extraction လိုတာလဲ?**
"အောင်ဇေယျာ Project Orion report 2026-04 လပိုင်းအတွက်ရှိလား?"
→ user=အောင်ဇေယျာ, project=Project Orion, date=2026-04

ဒီ filter တွေကို SQL WHERE clause အနေနဲ့သုံးတယ် (FTS မဟုတ်ဘူး)။

**Filter တွေကို ဘယ်လိုထုတ်လဲ?**

1. **User name** — DB က user name အားလုံးကို cache လုပ် → question ထဲမှာပါလားစစ်
2. **Project name** — Document ရဲ့ distinct project တွေကို cache လုပ် → question ထဲမှာပါလားစစ်
3. **Date** — အောက်ပါ pattern တွေကို priority နဲ့စစ်:
   - `YYYY-MM-DD` (2026-04-15)
   - `YYYY-MM` (2026-04)
   - `YYYY-MonthName` (2026-April)
   - `MonthName DD` (April 15 → current year သုံး)
   - `Q1 2026`, `2026`, `April 2026`

**ဘာလို့ cache လုပ်တာလဲ?**
User နဲ့ project list က ခဏခဏမပြောင်းဘူး။ Request တိုင်း DB query ပြန်စီးစရာမလိုဘူး (300s cache)။

### Step 3: FTS Query Refinement

```php
$ftsQuery = $this->refineFtsQuery($question, $autoFilters);
```

ဒီ method က FTS search အတွက် question ကို content words ချည်းပဲထုတ်တယ်။

**ဘာလို့ stripping တွေလုပ်တာလဲ?**

ဥပမာ: "အောင်ဇေယျာ Project Orion report 2026-04 လပိုင်းအတွက်ရှိလား?"

| Step | ဘာလုပ်လဲ | Result |
|------|-----------|--------|
| normalizeQuestion | "2026-04" → "2026-April" | "အောင်ဇေယျာ Project Orion report 2026-April လပိုင်းအတွက်ရှိလား?" |
| Strip user | "အောင်ဇေယျာ" ကိုဖယ် | " Project Orion report 2026-April လပိုင်းအတွက်ရှိလား?" |
| Strip project | "Project Orion" ကိုဖယ် | " report 2026-April လပိုင်းအတွက်ရှိလား?" |
| Strip YYYY-MonthName | "2026-April" ကိုဖယ် | " report လပိုင်းအတွက်ရှိလား?" |
| Strip stopwords | "report", "လပိုင်း", "အတွက်" etc. ဖယ် | "ရှိလား" → "" |
| Fallback | empty ဖြစ်ရင် "report" သုံး | "report" |

**ဒီလောက်တောင် stripping လုပ်ရတဲ့ အဓိကအကြောင်းရင်း:**
Filter တွေ (user, project, date) ကို SQL WHERE clause အနေနဲ့ပြီးသားထည့်ထားတယ်။ FTS မှာ ထပ်ထည့်စရာမလိုဘူး။ အမှန်တကယ် content word တွေချည်းပဲ FTS မှာထားရင် ပိုတိကျတယ်။

### Step 4: Follow-up Inheritance

```php
// ဒီ code က နောက်ထပ်မေးတဲ့မေးခွန်းတွေအတွက်
// အရင် question ရဲ့ filter တွေကို အမွေဆက်ခံတယ်
if (empty($autoFilters['user_ids']) && empty($autoFilters['project'])) {
    $inheritedFilters = $this->extractFiltersFromQuestion($prevUserMsg->content);
    // user, project, date တွေကို inherit လုပ်
}
```

**ဘာလို့ဒီလိုလုပ်တာလဲ?**

User က:
1. "အောင်ဇေယျာ Project Orion report 2026-April လအတွက်ရှိလား?"
2. "April 15 ရက်အတွက် အသေးစိတ်ရှင်းပြပေးပါ"

ဒုတိယမေးခွန်းမှာ "အောင်ဇေယျာ" နဲ့ "Project Orion" ကိုထပ်မပြောဘူး။
ဒါပေမယ့် ပထမမေးခွန်းက သူ့အတွက်ပဲဆိုတာ သိတယ်။ ဒါကြောင့် အမွေဆက်ခံတယ်။

**ဘာလို့ refusal ဆို date ကို inherit မလုပ်တာလဲ?**
ပထမမေးခွန်းကို အဖြေမရဘူးဆိုရင် (ဥပမာ "ဒီရက်အတွက်မရှိဘူး") → user က "ဒါဆိုဘာရှိလဲ?" ပြန်မေးတယ်။
ဒီအခါမှာ အရင် date filter ကို inherit လုပ်ရင် ပြန်မရဘူး။ ဒါကြောင့် date ကိုချန်လိုက်တယ်။

### Step 5: Dynamic Model Selection

```php
// ဒီကောင်က document တွေက ဘယ် embedding model သုံးထားလဲဆိုတာကြည့်ပြီး
// question ကိုလည်း အဲဒီ model နဲ့ပဲ embed လုပ်တယ်
$usedModelIds = $modelQuery->distinct()->pluck('embedding_model_id');
if ($usedModelIds->count() === 1) {
    // တစ်မျိုးတည်းပဲရှိရင် အဲဒါကိုသုံး
}
```

**ဘာလို့ဒီလိုလုပ်တာလဲ?**
Document A ကို nomic-embed-text (768d) နဲ့ embed လုပ်ထားတယ်။
Question ကို text-embedding-3-small (1536d) နဲ့ embed လုပ်ရင် dimension မတူတော့ဘူး → ရှာလို့မရဘူး။

### Step 6: Query Expansion

```php
$searchQueries = $this->expandQuery($question, $llm);
```

Default: disabled (`config('rag.search.query_expansion.enabled', false)`)

Enabled ဆိုရင် LLM ကို မေးတယ်: "ဒီမေးခွန်းကို ပုံစံအမျိုးမျိုးနဲ့ပြန်ရေးပေးပါ" → query 3 ခုရ → အကုန် embed → အကုန် search → results တွေကိုပေါင်း

**ဘာလို့ default disable လဲ?**
- LLM call တစ်ခုထပ်တိုးတယ် (နှေးတယ်၊ cost များတယ်)
- Simple query တွေအတွက် မလိုဘူး
- Complex query တွေအတွက်ပဲ enable လုပ်သင့်တယ်

### Step 7: Search

```php
$chunks = $this->searchMode === 'hybrid'
    ? $this->vectorStore->searchHybrid($ftsQuery, $questionVector, $this->topK * 3, $filters)
    : $this->vectorStore->search($questionVector, $this->topK * 3, $filters);
```

**ဘာလို့ `topK * 3` ကိုသုံးတာလဲ?**
နောက်ပိုင်းမှာ dynamic threshold နဲ့ MMR က အများကြီးထဲက ရွေးထုတ်တယ်။
အစကနေလက်တစ်ဆုပ်စာပဲယူရင် ရွေးစရာမရှိတော့ဘူး။

**Initial threshold ကို `0.20` အထိလျှော့ထားတယ် — ဘာလို့?**
`applyDynamicThreshold` က elbow method သုံးတယ် — similarity scores တွေကို sort လုပ် → အကွာအဝေးအကြီးဆုံးနေရာမှာဖြတ်။
ဒါပေမယ့် အစကနေ threshold မြင့်ရင် elbow ကိုမတွေ့ဘူး။ ဒါကြောင့် အစကနေ အနိမ့်ထား → အကုန်ယူ → ပြီးမှ dynamic ဖြတ်။

### Step 8: Dynamic Threshold

```php
private function applyDynamicThreshold(array $chunks, array $filters): array
```

**ဒီ method က ဘာလုပ်လဲ?**
1. Chunks တွေကို score နဲ့ sort
2. Consecutive scores တွေကြားက gap ကိုရှာ
3. အကြီးဆုံး gap (>0.15) ရှိရင် အဲဒီမှာဖြတ်
4. Gap မရှိရင်: `cutoff = max(0.65, top_score * 0.85)`
5. အကုန်ပါသွားရင် safety valve: အကောင်းဆုံး 1 chunk ကိုထားပေး

**ဘာလို့ ဒီနည်းလမ်းကို fixed threshold ထက်သုံးတာလဲ?**
Fixed threshold (ဥပမာ 0.65) က အလုပ်မဖြစ်တဲ့အခါတွေရှိတယ်:
- ရှာလို့ရတဲ့ chunk တွေက 0.64-0.70 အကောင်းဆုံးဖြစ်နေရင် 0.65 က cutoff က အလယ်မှာဖြတ်မယ်
- Dynamic threshold က score distribution ကိုကြည့်ပြီး ဉာဏ်ရှိရှိဖြတ်တယ်

### Step 9: MMR Re-ranking

```php
$chunks = $this->applyMMR($chunks);
```

**MMR (Maximal Marginal Relevance) ဆိုတာဘာလဲ?**
တူညီတဲ့အကြောင်းအရာပါတဲ့ chunk တွေကိုရှောင်ပြီး မတူညီတဲ့ information ကိုဦးစားပေးတယ်။

```php
// MMR score = λ * similarity - (1-λ) * maxSimilarityToAlreadySelected
// λ (lambda) = 0.7 → similarity (70%) vs diversity (30%) balance
```

**ဥပမာ:** Chunk ၅ ခုရှိတယ် — ၃ ခုက revenue အကြောင်း, ၂ ခုက expenses အကြောင်း။
MMR က revenue ၂ ခု + expenses ၂ ခုကိုရွေးမယ် (အကုန် revenue မယူဘူး)။

**ဘာလို့ default enable လဲ?**
Context window က အကန့်အသတ်ရှိတယ်။ တူတဲ့အကြောင်းအရာ ၃ ခုထည့်မယ့်အစား မတူတဲ့ ၃ ခုထည့်ရင် LLM က ပိုကောင်းတဲ့အဖြေပေးနိုင်တယ်။

### Step 10: Context Assembly + LLM Call

```php
$response = $llm->complete($systemPrompt, $llmQuestion, $context, [
    'temperature' => 0.3,
    'max_tokens' => $this->maxTokens,
]);
```

**ဘာလို့ temperature 0.3 လဲ?**
- 0.0 → deterministic (တူတူမေးရင် တူတူဖြေ)
- 1.0 → creative (စိတ်ကြိုက်တွေပါလာနိုင်တယ်)
- RAG အတွက်က factual ဖြေဖို့လိုတယ် → 0.3 က balance ကောင်းတယ်

### Step 11: Finish Reason Check

```php
if ($response->getFinishReason() === 'length') {
    $content .= "\n\n*[မှတ်ချက်: အဖြေသည် သတ်မှတ်ထားသော token ကန့်သတ်ချက်ကို ကျော်လွန်နေသောကြောင့် ဖြတ်တောက်ထားပါသည်။]*";
}
```

**ဘာလို့ဒီစစ်ဆေးမှုလိုတာလဲ?**
LLM တွေမှာ max output token limit ရှိတယ်။ Limit ရောက်ရင် `finish_reason: "length"` ပြန်တယ် — စာကြောင်းမပြည့်ခင် ဖြတ်သွားတယ်။
ဒီ code မရှိရင် user က ပြတ်နေတဲ့စာကိုမြင်ရမယ်။

### Step 12: Save + Log

```php
$message = $this->saveAssistantMessage($session, $content, $sources);
Log::channel(config('rag.logging.channel', 'rag'))->info('RAG pipeline: complete', [
    'search_time_ms' => ..., 'llm_time_ms' => ..., 'tokens_used' => ...,
]);
```

**ဘာလို့ log လုပ်တာလဲ?**
- Performance monitoring (search က LLM ထက်မြန်လား? နှေးလား?)
- Error tracking (ဘယ် step မှာ fail ဖြစ်လဲ?)
- Token usage tracking (cost estimation အတွက်)

---

## 7. Document Upload & Processing — Flow အသေးစိတ်

### ဘာလို့ processing က async လဲ?

Document processing pipeline:
```
Upload → Validate → SHA-256 dedup → Store file → [JOB] Extract text → Chunk → Embed → Upsert vectors
```

တစ်ဆင့်ချင်းစီက ဘာလို့ဒီလိုလုပ်တာလဲ။

### SHA-256 Dedup

```php
$fileHash = hash_file('sha256', $file->getPathname());
$existing = Document::where('file_hash', $fileHash)->first();
if ($existing) {
    return response()->json([...], 409); // Conflict
}
```

**ဘာလို့ file name မစစ်တာလဲ?**
File name က ပြောင်းလို့ရတယ်။ "report.pdf" ဆိုတဲ့ file ၂ ခုရှိရင် တူတူလား? SHA-256 hash က content ကိုစစ်တယ် — file content တူရင် duplicate။

**ဘာလို့ 409 (Conflict) ပြန်တာလဲ?**
File content တူတယ် → အသစ်ထပ်တင်လို့မရဘူးဆိုတဲ့အဓိပ္ပါယ်။ 422 (Validation Error) မဟုတ်ဘူး — error မဟုတ်ဘူး၊ conflict ပဲ။

### Text Extraction

```php
// PDF
$parser = new \Smalot\PdfParser\Parser();
$pdf = $parser->parseFile($path);
$text = $pdf->getText();

// DOCX
$phpWord = \PhpOffice\PhpWord\IOFactory::load($path);
// iterate sections → elements → get text
```

**ဘာလို့ PDF parser က page boundaries ကိုထိန်းတာလဲ?**
Chunk ဖြတ်တဲ့အခါမှာ page break က natural separator ဖြစ်တယ်။
ဥပမာ — "ဒီအကြောင်းအရာက စာမျက်နှာ ၅ မှာစပြီး ၆ မှာဆက်တယ်" ဆိုရင် page break မှာဖြတ်တာက logical ဖြစ်တယ်။

### Recursive Character Text Splitter

**ဘာလို့ recursive splitter သုံးတာလဲ?**
ပုံမှန် splitter တွေက:
- Fixed size: "aaaaaa\nbbbbbb" → "aaaaaa\nbb" ဆိုပြီး စာကြောင်းပြတ်သွားနိုင်တယ်
- Sentence splitter: sentence တွေက အရမ်းရှည်နေရင် မဖြတ်နိုင်ဘူး

Recursive splitter က:
1. Paragraph (`\n\n`) နဲ့အရင်ဖြတ်ကြည့်
2. မရရင် line break (`\n`) နဲ့
3. မရရင် sentence (`.`) နဲ့
4. မရရင် comma (`,`) နဲ့
5. မရရင် character by character

**ဘာလို့ overlap 200 လဲ?**
Chunk 1 ရဲ့အဆုံးနဲ့ chunk 2 ရဲ့အစပိုင်းကို ထပ်ထားတယ်။
ဒါမှ "ရောင်းအားက ၂၀၂၆ ခုနှစ်မှာ" ဆိုတဲ့ sentence က chunk 1 ရဲ့အဆုံးမှာပါပြီး chunk 2 ရဲ့အစမှာလည်းပါမယ်။
Search လုပ်ရင် နှစ်မျိုးလုံးမှာတွေ့နိုင်တယ်။

### Metadata Header

```php
$metaHeader = "Report by: {$userName}\nProject: {$project}\nDate: {$reportDate}\n\n";
```

**ဘာလို့ chunk content ရဲ့အစမှာ metadata ထည့်တာလဲ?**
- LLM က "ဒီအပိုင်းက ဘယ် project အတွက်လဲ၊ ဘယ်ရက်စွဲလဲ" ဆိုတာ context ထဲမှာမြင်ရမယ်
- FTS က "Project Orion" ဆိုတဲ့ keyword ကိုလည်းရှာနိုင်တယ် (document level column မှာလည်းရှိပေမယ့် chunk content ထဲမှာပါထည့်ထားတာ)
- Vector embedding မှာလည်း metadata ပါသွားတယ် → "Project Orion" နဲ့ "2026-04" က semantic ဆက်စပ်မှုကိုလည်းဖမ်းတယ်

---

## 8. Authentication — ဘာလို့ Custom Token Auth လဲ?

### ဘာလို့ Laravel Sanctum/Passport မသုံးတာလဲ?

Sanctum က:
- Cookie/Session based (SPA အတွက်ဆိုရင် CSRF protection လိုတယ်)
- API token ထုတ်ပေးတယ် — ဒါပေမယ့် tokens table သပ်သပ်လိုတယ်

Passport က:
- OAuth2 (access token + refresh token) — SPA အတွက် overkill
- Database tables ၆ ခုလောက်လိုတယ်
- Complexity များတယ်

**ဒီ project ရဲ့ auth:**
```php
// User မှာ api_token column တစ်ခုပဲ
$token = bin2hex(random_bytes(40)); // 80-char hex
$user = User::where('api_token', $token)->first();
```

- Simple: token က user table ထဲမှာပဲရှိတယ်
- Secure: random hex (80 chars = 320 bits entropy)
- Stateless: token ကို DB မှာပဲစစ်

---

## 9. Frontend Architecture — Vue 3 Components တွေဘယ်လိုအလုပ်လုပ်လဲ

### App.vue — Root Component

State ၃ ခု:
- **Loading**: `!auth.isInitialized` — app က boot လုပ်နေတုန်း (pinia store က `/api/auth/me` ကိုခေါ်နေတယ်)
- **Guest**: `!auth.isAuthenticated` — login/register page ပဲပြ
- **Authenticated**: Header + sidebar + main content

**ဘာလို့ `/api/auth/me` ကို app boot မှာခေါ်တာလဲ?**
Token က localStorage မှာရှိတယ်။ ဒါပေမယ့် token က expire ဖြစ်နေနိုင်တယ် (server က regenerate)။
Token ရှိရုံနဲ့ authenticated လို့မယူဘူး — server ကိုသွားစစ်မှရတယ်။

### ChatInterface.vue — Main Chat

**Streaming ကို ဘယ်လိုကိုင်တွယ်လဲ?**

```typescript
// chatService.ts
async function* askStream(question: string, ...): AsyncGenerator<StreamChunk> {
    const response = await fetch('/api/chat?' + params);
    const reader = response.body!.getReader();
    // SSE: data: {...}\n\n
    // တစ်ပိုင်းချင်းဖတ် → JSON parse → yield
}
```

1. `fetch` ကို stream: true နဲ့ခေါ်
2. `response.body.getReader()` ကိုသုံးပြီး chunk တွေကိုဖတ်
3. Status event: "embedding..." → "searching..." → "generating..." (user ကိုပြ)
4. Sources event: ဘယ် document တွေကိုသုံးလဲဆိုတာပြ
5. Chunk events: typing effect အတွက်

**ဒီပုံစံရဲ့ အားသာချက်:**
- User က စက္ကန့် ၃၀ စောင့်နေစရာမလိုဘူး
- Processing stages တွေကိုမြင်ရတယ်
- အဖြေစထွက်လာတာနဲ့ စဖတ်လို့ရတယ်

### DocumentsPage.vue — Document Table

**ဘာလို့ server-side pagination သုံးတာလဲ?**
Document ၁၀၀၀၀ ရှိတယ်ဆိုပါစို့။ Client ကို အကုန်ပို့ရင်:
- Network: 10MB data တစ်ခါတည်းပို့ရမယ်
- Memory: Browser က item 10000 ကို DOM မှာထားရမယ်
- Search: JS နဲ့ filter လုပ်ရင် client မှာလေးတယ်

Server-side pagination:
- Request: `GET /api/documents?page=1&per_page=20&search=Orion&sort_key=created_at&sort_dir=desc`
- Response: 20 items + pagination meta
- Client: လက်ရှိစာမျက်နှာကို ပြရုံပဲ

---

## 10. Configuration — Config vs Database Override

### config/rag.php — Default Values

```php
'search' => [
    'similarity_threshold' => (float) env('RAG_SEARCH_SIMILARITY_THRESHOLD', 0.65),
],
```

ဒါက default ပဲ။ AiModel ရဲ့ `settings` JSONB က override လုပ်နိုင်တယ်:
```php
// RAGPipelineService constructor
if (isset($s['similarity_threshold'])) {
    $this->similarityThreshold = (float) $s['similarity_threshold'];
}
```

**ဘာလို့ config file ရှိနေသေးတာလဲ? AiModel က source of truth မဟုတ်ဘူးလား?**

၂ ခုလုံးက လိုတယ် — ဒါပေမယ့် layer ကွာတယ်:

| | config/rag.php | AiModel DB |
|---|---|---|
| **Purpose** | Build-time defaults + env binding | Runtime overrides |
| **When used** | AiModel not found / field not set | When explicitly configured |
| **Scope** | Global | Per-model |
| **Examples** | `vector_store.driver`, `chunk_size`, `base_url`, `api_key` | `top_k`, `similarity_threshold`, `search_mode` |

Config file ကို ဖျက်လို့မရတဲ့အကြောင်းရင်းတွေ:
1. **AiModel table က empty ဖြစ်နိုင်တယ်** — fresh install, seeder မပြေးရသေး
2. **Infrastructure settings** — `vector_store.driver`, `chunking.*`, `logging.*` တို့က per-model မဟုတ်ဘူး
3. **Env binding** — Laravel က `env()` ကို config file ထဲမှာပဲသုံးလို့ရတယ်
4. **58 call sites** — code တစ်ခုလုံးက `config('rag.xxx')` ကို ၅၈ နေရာမှာသုံးထားတယ်

---

## 11. Testing Strategy

### Backend: Pest PHP

**ဘာလို့ in-memory SQLite သုံးတာလဲ?**
- PostgreSQL ကို testing အတွက်ဆောက်စရာမလိုဘူး
- CI/CD pipeline မှာ PostgreSQL ထည့်စရာမလိုဘူး
- Test တစ်ခုစီက `RefreshDatabase` သုံးတယ် → ပြီးရင် data တွေပျက်
- SQLite က `:memory:` ဖြစ်တယ် → အမြန်ဆုံး

**ဒါပေမယ့် pgvector features တွေကို မစမ်းနိုင်ဘူးဆိုတာ သိထားရမယ်**
- Vector search ကို mock လုပ်ထားတယ်
- FTS (tsvector) ကို SQLite မှာ support မလုပ်ဘူး

**ဘာလို့ `QUEUE_CONNECTION=sync` လဲ?**
Job တွေက background မှာပြေးရင် test က job ပြီးတာကိုစောင့်ရမယ်။
`sync` ဆိုရင် job က synchronous ပြေးတယ် — test ထဲမှာ result ကိုချက်ချင်းစစ်လို့ရတယ်။

### Root-level PHP Scripts

`diagnostic.php`, `test_ask.php`, `bulk_fix_vectors.php` — ဒါတွေက test suite မဟုတ်ဘူး၊ **ad-hoc debugging scripts** တွေပဲ။

| | tests/ (Pest) | Root scripts |
|---|---|---|
| **Run ပုံ** | `php artisan test` | `php script.php` |
| **DB** | SQLite `:memory:` | Real PostgreSQL |
| **Mocking** | Yes (services တွေကို mock) | No (real API calls) |
| **ဘာအတွက်လဲ** | Automated CI + regression | Manual debugging, one-off ops |

---

## 12. Key Design Decisions Summary

### ဘာလို့ဒီလိုတွေလုပ်ထားတာလဲ — Quick Reference

| Decision | Reason |
|----------|--------|
| **Raw cURL instead of SDK** | Minimal dependencies, transparent debugging |
| **No Repository layer** | CRUD-heavy မဟုတ်, abstraction overkill |
| **No DB foreign keys** | ULIDs + future sharding, easier migrations |
| **Vector shard tables** | pgvector dimension fixity, different models = different dims |
| **Hybrid search (vector + FTS)** | Semantic + keyword matching, RRF fusion |
| **Dynamic threshold** | Adaptive to score distribution, better than fixed cutoff |
| **MMR re-ranking** | Diversity in context window, avoids redundant chunks |
| **Streaming SSE** | Real-time UX, stages display, early text rendering |
| **Custom token auth** | Simple, stateless, no extra tables |
| **Module system** | Separation of concerns, independent testing |
| **MD5 embedding cache** | Avoid redundant API calls, save cost |
| **Recursive chunking** | Semantic boundaries preserved, graceful fallback |
| **Metadata header in chunks** | LLM sees context (user/project/date) in each chunk |
| **Follow-up inheritance** | Natural conversation flow without repeating context |
| **Burmese language support** | Myanmar digit conversion, Burmese stopwords, month names |

---

## 13. Common Pitfalls & How to Debug

### "No chunks found" / Refusal

Possible reasons:
1. **Similarity threshold too high** → Check `config('rag.search.similarity_threshold')`
2. **Wrong embedding model** → Document က model A, question က model B (dimension mismatch)
3. **Date filter too narrow** → Check `extractFiltersFromQuestion()` output
4. **FTS query empty** → `refineFtsQuery()` က fallback "report" ပဲကျန်တယ်

Debug: `php diagnostic.php` — ဒီ script က step-by-step debug လုပ်ပေးတယ်

### "Response cut off mid-sentence"

→ `finish_reason: "length"` — LLM က max output token limit ကိုရောက်သွားတယ်

Fix: `.env` မှာ `RAG_LLM_MAX_TOKENS=8192` ထည့် (သို့) AiModel settings မှာ `max_tokens` ကိုမြှင့်

### "Follow-up doesn't work"

1. Check if previous answer was a refusal (date inheritance skips on refusal)
2. Check if `extractFiltersFromQuestion()` extracts the new date correctly
3. Run `php test_extraction.php` to see what filters are extracted

---

## 14. How to Extend This Project

### Add a new embedding/LLM provider (e.g., Anthropic)

1. Create provider class: `AnthropicLLMProvider.php` implements `LLMProviderInterface`
2. Register in `ProviderFactory.php`
3. Add provider option in `AiModelForm.vue`
4. Update validation in `AiModelService.php`

### Add a new search mode

1. Add mode to `VectorStoreService.php`
2. Add config option in `config/rag.php`
3. Add setting in `AiModelForm.vue`

### Add a new module

1. Create `modules/NewModule/` directory
2. Add PSR-4 namespace in `composer.json`
3. Register `ServiceProvider` in `config/app.php`
4. Run `composer dump-autoload`

---

> **အကြံပြုချက်:** Codebase ကို နားလည်ဖို့အကောင်းဆုံးနည်းလမ်းက `composer run dev` နဲ့ run ပြီး document upload → chat လုပ် → ပြီးရင် route → controller → service ဆိုပြီး top-down လိုက်ဖတ်ကြည့်ပါ။ Module တစ်ခုချင်းစီမှာရှိတဲ့ AGENTS.md က quick overview ပေးပါတယ်။
