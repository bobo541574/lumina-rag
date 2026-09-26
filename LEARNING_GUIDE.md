# Lumina RAG Project - Comprehensive Learning Guide (မြန်မာဘာသာ)

ဤ Guide သည် Lumina RAG project ၏ codebase structure၊ အသေးစိတ် business logic၊ database structure နှင့် frontend architecture တို့ကို အခြေခံမှ အဆင့်မြင့်အထိ တစ်နေရာတည်းတွင် လေ့လာနိုင်ရန် ပြုစုထားခြင်း ဖြစ်ပါသည်။

---

## **၁။ Project Overview & Technology Stack**

Lumina RAG သည် Retrieval-Augmented Generation (RAG) စနစ်တစ်ခုဖြစ်ပြီး၊ ကိုယ်ပိုင် document များ (PDF, DOCX, TXT) ကို upload တင်၍ ၎င်းတို့အပေါ် အခြေခံကာ AI ကို မေးခွန်းများ မေးမြန်းနိုင်သော system တစ်ခုဖြစ်သည်။

- **Backend**: Laravel 13 (Modular Monolith Architecture)
- **Database**: PostgreSQL 16 + pgvector (Vector storage အတွက်)
- **Frontend**: Vue 3 + Pinia + Tailwind CSS v4
- **AI Integration**: Provider ၅ မျိုး — OpenAI, Ollama (local), Gemini, Claude, DeepSeek (Embedding: OpenAI/Ollama/Gemini, LLM: ၅ မျိုးလုံး)

---

## **၂။ Codebase Structure (Modules)**

Project ကို `modules/` directory အောက်တွင် အပိုင်း (၇) ပိုင်း ခွဲခြားထားသည်။ Module တစ်ခုစီတွင် ၎င်းနှင့်ဆိုင်သော Controller, Service, Model နှင့် Migrations များ သီးသန့်ရှိသည်။

| Module | တာဝန်ယူမှု (Responsibility) |
| :--- | :--- |
| [ChatModule](file:///Users/za/Sites/projects/lumina_rag/modules/ChatModule) | Chat sessions နှင့် RAG pipeline တစ်ခုလုံးကို ထိန်းချုပ်ခြင်း။ |
| [DocumentModule](file:///Users/za/Sites/projects/lumina_rag/modules/DocumentModule) | Document upload၊ Extraction နှင့် Chunking ပြုလုပ်ခြင်း။ |
| [EmbeddingModule](file:///Users/za/Sites/projects/lumina_rag/modules/EmbeddingModule) | စာသားများကို Vector (နံပါတ်စဉ်များ) အဖြစ် ပြောင်းလဲပေးခြင်း။ Provider: OpenAI, Ollama, Gemini |
| [VectorStoreModule](file:///Users/za/Sites/projects/lumina_rag/modules/VectorStoreModule) | Vector များကို သိမ်းဆည်းခြင်းနှင့် Similarity Search လုပ်ခြင်း။ Metadata JSONB filtering (project, date, user, section) |
| [LLMModule](file:///Users/za/Sites/projects/lumina_rag/modules/LLMModule) | AI Model (LLM) နှင့် ဆက်သွယ်ပြီး အဖြေထုတ်ပေးခြင်း။ Provider: OpenAI, Ollama, Gemini, Claude, DeepSeek |
| [SettingsModule](file:///Users/za/Sites/projects/lumina_rag/modules/SettingsModule) | AI models များ၏ config များကို စီမံခန့်ခွဲခြင်းနှင့် term alias registry (မြန်မာ/အင်္ဂလိပ် ဝေါဟာရမြေပုံ) ကို စီမံခြင်း။ |
| [UserModule](file:///Users/za/Sites/projects/lumina_rag/modules/UserModule) | User authentication (Login/Register) ပိုင်း။ |

---

## **၃။ Services Deep Dive: Step-by-Step Internal Logic**

Service တစ်ခုချင်းစီရဲ့ အတွင်းပိုင်း အလုပ်လုပ်ပုံကို အသေးစိတ် စာသင်သလို လေ့လာကြည့်ကြရအောင်။

### **A. DocumentModule - စာရွက်စာတမ်းများ စီမံခြင်း**

#### **၁။ [TextExtractionService.php](file:///Users/za/Sites/projects/lumina_rag/modules/DocumentModule/Services/TextExtractionService.php)**
File format ပေါင်းစုံကနေ စာသားတွေကို သန့်စင်ပြီး ထုတ်ယူပေးတဲ့ နေရာဖြစ်ပါတယ်။
- **PDF Extraction**: `Smalot\PdfParser` ကို သုံးပါတယ်။ `getText()` နဲ့ စာသားတွေ အကုန်ယူပြီးရင် `preg_replace` သုံးပြီး စာကြောင်းအလွတ် အပိုတွေကို ရှင်းထုတ်ပါတယ်။
- **Word (.docx) Extraction**: `PhpWord` ကို သုံးပြီး Section တစ်ခုချင်းစီကို ဖြတ်ဖတ်ပါတယ်။ `Title` (ခေါင်းစဉ်)၊ `ListItem` (စာရင်းများ) နဲ့ `Table` (ဇယားများ) တွေကို သီးသန့် logic တွေနဲ့ ဖတ်ပါတယ်။ ဥပမာ- Table ဆိုရင် `| cell1 | cell2 |` ဆိုတဲ့ markdown format ပြောင်းပေးပါတယ်။
- **Markdown Cleanup**: Markdown file တွေကို ဖတ်တဲ့အခါ image links တွေ၊ link syntax တွေကို ဖယ်ရှားပြီး စာသားစစ်စစ် (content words) တွေကိုပဲ ယူပါတယ်။

#### **၂။ [TextChunkingService.php](file:///Users/za/Sites/projects/lumina_rag/modules/DocumentModule/Services/TextChunkingService.php)**
စာသားတွေကို AI ဖတ်လို့ရမယ့် အပိုင်းအစ (Chunks) လေးတွေ ဖြစ်အောင် လုပ်ပေးပါတယ်။
- **`splitByHeadings()`**: စာသားတွေကို အရင်ဆုံး Heading (`#`, `##`) တွေနဲ့ ခွဲလိုက်ပါတယ်။ ဒါမှ chunk တစ်ခုချင်းစီက ဘယ်ခေါင်းစဉ်အောက်ကလဲဆိုတာ သိနိုင်မှာပါ။
- **`chunkText()` (Recursive Logic)**: စာသားက characters ၁၀၀၀ ထက် ကျော်နေရင် ဖြတ်ဖို့ ကြိုးစားပါတယ်။
- **`findSplitPoint()`**: စာကြောင်း အလယ်ကနေ ပြတ်မသွားအောင် separator ဦးစားပေး စနစ်ကို သုံးပါတယ်။ `\n\n` (paragraph) ကို အရင်ရှာတယ်၊ မရှိရင် `\n`၊ မရှိရင် `.` (sentence)၊ အဲ့ဒါမှ မရှိရင်တော့ space နေရာမှာ ဖြတ်ပါတယ်။
- **Overlap**: Chunk အသစ် စတဲ့အခါ အရှေ့ chunk ရဲ့ နောက်ဆုံး characters ၂၀၀ ကနေ ပြန်စတဲ့အတွက် အဓိပ္ပာယ် ဆက်စပ်မှု မပျောက်သွားပါဘူး။
- **Document Metadata**: Document တိုင်းတွင် `report_date` (date) နှင့် `project` (varchar) ကော်လံများပါဝင်ပြီး၊ ဤ metadata များကို UI မှတစ်ဆင့် sort, filter နှင့် edit ပြုလုပ်နိုင်ပါတယ်။
- **Chunk Metadata JSONB**: Chunk တစ်ခုချင်းစီတွင် structured metadata JSONB (user_name, project, report_date, document_title, section, page_number, chunk_index) ပါဝင်ပြီး PostgreSQL `@>` jsonb operator သုံးပြီး precision filtering လုပ်နိုင်ပါတယ်။ PgvectorDriver က vector search ရော FTS ရော မှာ meta filter ကို support လုပ်ပါတယ်။
- **Section Tracking**: Chunk တစ်ခုချင်းစီရဲ့ ဘယ် section/heading အောက်ကလဲဆိုတာကို `section` field မှာသိမ်းပါတယ်။ ဒါက `TextChunkingService` ရဲ့ `splitByHeadings()` method ကနေ ရယူပါတယ်။

---

### **B. VectorStoreModule - Vector များ ရှာဖွေသိမ်းဆည်းခြင်း**

#### **၁။ [PgvectorDriver.php](file:///Users/za/Sites/projects/lumina_rag/modules/VectorStoreModule/Services/PgvectorDriver.php)**
PostgreSQL ရဲ့ pgvector ကို သုံးပြီး vector search လုပ်တဲ့ အဓိက နေရာပါ။
- **Shard Tables**: `ve_1536`, `ve_768` စသဖြင့် dimension အလိုက် table တွေ ခွဲထားပါတယ်။ `resolveDimTable()` ကနေ model dimension နဲ့ အနီးစပ်ဆုံး table ကို ရွေးပေးပါတယ်။
- **`searchHybrid()` (The Core)**:
    1. **Vector Search**: `1 - (ve.embedding <=> ?::vector)` ဆိုတဲ့ Cosine Distance ကို သုံးပြီး အဓိပ္ပာယ်တူရာ ရှာပါတယ်။
    2. **FTS Search**: `ts_rank(tsv_content, plainto_tsquery(...))` ကို သုံးပြီး စာလုံးအတိအကျ ပါတဲ့ chunks တွေကို ရှာပါတယ်။
    3. **`fuseResults()` (RRF Fusion)**: Vector ရလဒ်နဲ့ FTS ရလဒ်တွေကို **Reciprocal Rank Fusion (k=60)** algorithm နဲ့ ပေါင်းစပ်ပါတယ်။ ဒါမှ ရှာဖွေမှုက ပိုတိကျပါတယ်။
    4. **Normalization**: RRF score တွေကို 0-1 range ထဲရောက်အောင် ပြန်ညှိပေးတဲ့အတွက် downstream logic တွေမှာ similarity threshold နဲ့ စစ်ရတာ အဆင်ပြေစေပါတယ်။
- **Metadata Filtering**: `applyFiltersVector()` နဲ့ `applyFiltersFts()` မှာ `meta` filter array ကို support လုပ်ပါတယ်။ PostgreSQL မှာ `dc.metadata @> ?::jsonb` (contains operator), SQLite မှာ `json_extract()` ကိုသုံးပါတယ်။ ဒါမှ "project Orion နဲ့ သက်ဆိုင်တဲ့ chunks တွေပဲရှာမယ်" ဆိုတဲ့ filter မျိုး ထည့်လို့ရပါတယ်။
- **Metadata in Results**: `searchHybrid()`, `search()`, FTS — အကုန်လုံးက `dc.metadata` ကို SELECT ထဲမှာထည့်ပြီး return လုပ်ပါတယ်။

---

### **C. EmbeddingModule - စာသားမှ Vector ပြောင်းခြင်း**

#### **၁။ [EmbeddingService.php](file:///Users/za/Sites/projects/lumina_rag/modules/EmbeddingModule/Services/EmbeddingService.php)**
API ခေါ်ဆိုမှုတွေကို စီမံပေးတဲ့ နေရာပါ။
- **MD5 Caching**: စာသားတစ်ခုရဲ့ MD5 hash ကို key အဖြစ် သုံးပြီး Vector ကို သိမ်းပါတယ်။
- **Batch Optimization**: `embedBatch()` မှာ စာသား ၁၀ ခု ပါလာရင် အရင်ဆုံး cache ထဲမှာ ရှိပြီးသားတွေကို ရှာပါတယ်။ မရှိသေးတဲ့ စာသား (uncached) တွေကိုပဲ စုပြီး API ဆီ တစ်ခါတည်း ပို့ပါတယ်။ API ကနေ ပြန်လာတဲ့ vector တွေကို cache ထဲ ပြန်ထည့်ပြီး မူလ order အတိုင်း ပြန်စီပေးပါတယ်။

---

### **D. LLMModule - AI အဖြေထုတ်ပေးခြင်း**

#### **၁။ [LLMService.php](file:///Users/za/Sites/projects/lumina_rag/modules/LLMModule/Services/LLMService.php)**
AI ကို prompt ပို့ဖို့ ပြင်ဆင်ပေးတဲ့ နေရာပါ။
- **`buildContextString()`**: ရှာတွေ့ထားတဲ့ chunks တွေကို AI အတွက် စာသားပြောင်းပေးပါတယ်။
- **Token Counting**: Chunk တစ်ခုချင်းစီကို context ထဲ မထည့်ခင် token ဘယ်လောက်ရှိလဲအရင်တွက်ပါတယ်။ `maxContextTokens` (default 4000) ထက် ကျော်သွားရင် နောက်ထပ် chunks တွေကို ထည့်မပေးတော့ပါဘူး။
- **Source Labeling**: Chunk တစ်ခုချင်းစီရဲ့ ထိပ်မှာ `[Source: Title, Similarity %, Page X]` ဆိုတဲ့ အချက်အလက်တွေ ထည့်ပေးတဲ့အတွက် AI က အကိုးအကား ပြန်ပေးနိုင်ပါတယ်။

**Provider ၅ မျိုး**: OpenAI, Ollama, Gemini, Claude, DeepSeek — အားလုံးက `LLMProviderInterface` ကို implement လုပ်ထားပြီး `ProviderFactory` က `AiModel->provider` အလိုက် auto-create လုပ်ပေးပါတယ်။

---

### **E. UserModule & SettingsModule - စနစ်စီမံခန့်ခွဲခြင်း**

#### **၁။ [AuthService.php](file:///Users/za/Sites/projects/lumina_rag/modules/UserModule/Services/AuthService.php)**
လုံခြုံရေးနဲ့ user စီမံခန့်ခွဲမှုကို ဆောင်ရွက်ပါတယ်။
- **Token Generation**: Login/Register ဝင်တဲ့အခါ `ApiToken::make()` (bin2hex(random_bytes(40)) == ၈၀-character / 320 bits entropy hex) နဲ့ raw API token ကိုထုတ်ပေးပါတယ်။ ဒါပေမယ့် **database မှာတော့ SHA-256 digest (64-char hex) ကိုပဲ သိမ်းပါတယ်** (`ApiToken::hash()`) — raw token ကို ဘယ်နေရာမှမထားဘူး (ISO 27002:8.24 credential-at-rest protection)။ `AuthenticateWithToken` middleware က request တိုင်း token ကို SHA-256 ပြောင်းပြီး DB နဲ့ယှဉ်ပါတယ်။
- **Expiry + Sliding Renewal**: Token တွေက expiry ရှိပါတယ် (`RAG_API_TOKEN_TTL_DAYS`, default 30 ရက်)။ Expire ဖြစ်နေရင် 401 ပြန်ပြီး၊ သက်တမ်းမကုန်သေးရင် `saveQuietly()` နဲ့ expiry ကို နောက်ကိုတွန်းပေးတယ် (active user တွေ ပြန်ဝင်စရာမလို)။ Login fail ရင် generic message ပဲပြန်တယ် (enumeration ကာကွယ်)။ `DELETE /api/auth/me` က `AccountDeletionService` ကနေတစ်ဆင့် data အကုန်သိမ်းဖျက်ပေးတယ်။
- **Password Hashing**: User ရဲ့ password တွေကို plain text မသိမ်းဘဲ Bcrypt hashing သုံးပြီး သိမ်းတဲ့အတွက် database ပေါက်ကြားခဲ့ရင်တောင် password တွေကို သိနိုင်မှာ မဟုတ်ပါဘူး။
- **Audit Log**: `auth.security` channel မှာ register/login/logout/failed-login/account-deletion တွေကို log လုပ်ပါတယ် (90 ရက် retention, daily files)။

#### **၂။ [AiModelService.php](file:///Users/za/Sites/projects/lumina_rag/modules/SettingsModule/Services/AiModelService.php)**
AI Models တွေရဲ့ configuration တွေကို စီမံပေးပါတယ်။
- **Validation Rules**: Model တစ်ခုချင်းစီရဲ့ type (embedding/llm) ပေါ်မူတည်ပြီး သက်ဆိုင်ရာ validation rules တွေကို dynamically ထုတ်ပေးပါတယ်။ (ဥပမာ- embedding ဆိုရင် dimensions ပါရမယ်၊ llm ဆိုရင် temperature ပါရမယ်)
- **Model Registry**: System တစ်ခုလုံးမှာ code ပြင်စရာမလိုဘဲ UI ကနေ provider စုံ (OpenAI, Ollama, Gemini, Claude, DeepSeek) က model တွေကို အလွယ်တကူ တိုးနိုင်၊ လျှော့နိုင်အောင် စီစဉ်ထားပါတယ်။ Model တစ်ခုချင်းစီမှာ ကိုယ်ပိုင် `api_key`, `base_url`, `temperature`, `timeout`, `settings` JSONB ပါရှိပါတယ်။

#### **၃။ [TermAliasService.php](file:///Users/za/Sites/projects/lumina_rag/modules/SettingsModule/Services/TermAliasService.php)**
မြန်မာ-အင်္ဂလိပ် ဝေါဟာရ မြေပုံဆွဲခြင်းကို စီမံပေးပါတယ်။
- **Cached Alias Map**: alias-canonical mapping ကို Redis တွင် MD5 hash key ဖြင့် သိမ်းဆည်းပြီး 24h TTL သတ်မှတ်ထားပါတယ်။
- **expandText()**: ရှာဖွေမေးခွန်းတွင် ပါဝင်သော ဝေါဟာရများကို အခြားဘာသာစကားများသို့ ချဲ့ထွင်ပေးပါတယ် (ဥပမာ - "ရန်ကုန်" → "Yangon" နှင့် "ရန်ကုန်")။
- **expandFtsQuery()**: Full-text search (FTS) query တွင် အလားတူ ဝေါဟာရချဲ့ထွင်မှုကို ပြုလုပ်ပေးပါတယ်။ RAG pipeline ထဲတွင် hybrid search မလုပ်မီ ဤ service ကိုခေါ်ဆိုပါတယ်။
- **UI Route**: `/settings/term-aliases` မှတစ်ဆင့် ဝေါဟာရများကို စီမံနိုင်ပါတယ်။

---

### **F. ChatModule - RAG Orchestration**

#### **၁။ [RAGPipelineService.php](file:///Users/za/Sites/projects/lumina_rag/modules/ChatModule/Services/RAGPipelineService.php)**
System တစ်ခုလုံးရဲ့ step-by-step စီးဆင်းမှုကို စီမံခန့်ခွဲပါတယ်။
- **Burmese Normalization**: မြန်မာဂဏန်း (၀-၉) တွေကို English (0-9) ပြောင်းပြီးမှ ရက်စွဲတွေ ရှာပါတယ်။
- **Inheritance Logic**: User က "ဘယ်သူ့အစီရင်ခံစာလဲ" လို့ မေးပြီးနောက် "အဲ့ဒါက ဘယ်အချိန်ကလဲ" လို့ ထပ်မေးရင် (follow-up) အရှေ့မေးခွန်းက user id/project တွေကို အလိုအလျောက် ယူသုံးပေးပါတယ်။ Refusal ဖြစ်ရင် date ကို inherit မလုပ်ပါဘူး (user က "ဒါဆိုဘာရှိလဲ" ပြန်မေးနိုင်တယ်)။
- **Dynamic Model Selection**: Document တွေ ဘယ် embedding model သုံးထားလဲဆိုတာကို auto-detect လုပ်ပြီး question ကိုလည်း အဲဒီ model နဲ့ပဲ embed လုပ်တယ်။ တစ်မျိုးတည်းပဲရှိရင် အဲဒါကိုသုံး၊ အမျိုးစုံရှိရင် default ကိုသုံး။
- **FTS Non-ASCII Stripping**: FTS query ကနေ Burmese/Unicode tokens တွေကိုဖယ်တယ် — English `plainto_tsquery` parser က မကိုင်တွယ်နိုင်လို့။ Non-English query တွေအတွက် vector search ကိုပဲ အားကိုးတယ်။
- **Confidence Assessment**: ရှာတွေ့တဲ့ similarity score တွေအရ အဖြေက စိတ်ချရမှု ရှိမရှိ (High/Low) သတ်မှတ်ပြီး AI ကို instruction ပြောင်းပေးပါတယ်။ Low confidence ဆို "Based on limited available information..." နဲ့စပြီး uncertainty ကိုဖော်ပြပါတယ်။
- **Old Document Detection**: Document တွေက တစ်နှစ်ကျော်ရင် system prompt မှာ "Some source documents are over a year old" ဆိုတဲ့ rule ကိုထည့်ပေးတယ်။
- **System Prompt Rewrite**: Core rules (grounding, completeness, citation, language), behavior (tone, formatting, conciseness), conditional sections (low confidence, old documents) — ဆိုပြီး structured prompt ကိုသုံးထားပါတယ်။
- **Pagination**: List view များတွင် pagination ကိုထောက်ပံ့ထားပြီး `RAG_PAGINATION_PER_PAGE` နှင့် `RAG_PAGINATION_MAX_PER_PAGE` environment variables ဖြင့် configure လုပ်နိုင်ပါတယ်။

---

## **၄။ RAG Pipeline Deep Dive — Default Flow + Improvement Layers**

ဒီ section က **base (default) RAG flow** နဲ့ သူ့အပေါ်ကို တင်ထားတဲ့ **improvement layers** အားလုံးကို တစ်ချက်တည်း မြင်ရအောင် ရှင်းပြပါမယ်။ Base flow က ရိုးရှင်းပါတယ်:

```
Set up → normalize → embed → search → threshold → context → LLM → save
```

Improvement တွေက အဲဒီ ခြေလှမ်းတစ်ခုချင်းဆီမှာ ဝင်ထားတာပါ။ ခေတ်မီ flow အပြည့်အစုံ:

```
POST /api/chat (question / document_filter / llm_model_id / think)
   │ ChatController: validate → throttle(20/min) → document_filter whitelist + owner-scope
   ▼
1. normalizeQuestion()            trim / truncate / "2026-04" → "2026-April"
2. QuestionClassifier.classify()  comparative / analytical / factual / generic
3. SessionManager.resolveSession() resume or create; expiry + ownership
4. FilterExtractor.extract()      user / project / date / quarter / Burmese month (၀-၉→0-9)
5. QueryRewriterService.rewrite() complexity score → SIMPLE (rule-based) / COMPLEX (+LLM)
6. termAlias.expandText/+expandFtsQuery()  မြန်မာ↔အင်္ဂလိပ် alias ချဲ့
7. FtsQueryBuilder.refine()       content words ချည်းထုတ် (strip stopwords/non-ASCII)
8. saveUserMessage()
9. Follow-up inheritance          user/project/date (+ refusal ဆို date မယူ) + citation scope
10. applyTenantIsolation()        ကိုယ့် document တွေထဲပဲ ရှာလို့ရအောင် ချုပ်
11. resolveLLM() + embedding model auto-select (target docs နဲ့ကိုက်တာကိုယူ)
12. lookupSemanticCache() ───────── HIT → answer+ sources ပြန် (retrieval/LLM ကျော်)
     │                              MISS → ဆက်သွား
13. expandQuery()                 analytical/comparative (သို့) enable ရင် LLM reformulations
14. runSearchQueries()
    a. embed(each query)
    b. searchHybrid() → vector cosine + FTS ကို RRF (1/(k+rank)) နဲ့ fuse
       (factual type က hybrid ကို အတင်းသုံးဆွဲတယ်)
    c. dedupe by chunk_id
    d. applyDynamicThreshold() → elbow method
    e. reranker.rerank() → optional cross-encoder (bge-reranker, candidate 8 → keep 5)
    f. applyMMR() → diversity selection (topK)
15. assessConfidence + hasOldDocuments + buildSystemPrompt
16. reorderForLostInTheMiddle()   best-first, worst-last
17. history (last 4) + "Search Scope" note prepend
18. llm.complete()/completeStream()  temperature / max_tokens / num_ctx / think
19. finish_reason='length'        → truncation မှတ်ချက်
20. saveAssistantMessage(sources JSONB)
21. writeSemanticCache()
22. Log (rag channel) — timing, tokens
```

### Improvement တစ်ခုချင်းစီ — အတိုချုပ်

| # | Improvement | File | ဘာလို့လဲ |
|---|---|---|---|
| 1 | Query Rewriting (SIMPLE/COMPLEX) | `Pipeline/QueryRewriterService.php` | ရှုပ်ထွေးမှုရမှတ်နဲ့ rule-based (spelling/date/synonym) ဒါမှမဟုတ် LLM reformulation ရွေးချယ် |
| 2 | Boolean FTS (`& \| !`) | `QueryRewriterService::buildBooleanFtsQuery` + `PgvectorDriver` | exact multi-term match — `to_tsquery` syntax error မဖြစ်အောင် date/number/non-ASCII ဖယ်ပေးတယ် |
| 3 | Question Classifier | `QuestionClassifier.php` | lexical pattern နဲ့ type ခွဲ → comparative/analytical က recall boost, factual က hybrid အတင်းသုံး |
| 4 | Hybrid Search (RRF) | `VectorStoreModule/Services/PgvectorDriver.php` | semantic (vector) + keyword (FTS) နှစ်မျိုးလုံးရအောင် fuse (k=60) |
| 5 | Dynamic Threshold (elbow) | `Pipeline/ChunkProcessor.php` | score distribution ကြည့်ပြီး cutoff ရှာ — fixed 0.65 ရဲ့ drawback ကိုဖြေ |
| 6 | MMR Diversity | `ChunkProcessor::applyMMR` | `λ×relevance − (1−λ)×redundancy` — context window ထဲ အကြောင်းအရာမျိုးစုံပါအောင် |
| 7 | Cross-Encoder Reranker (optional) | `RerankerService.php` | question+chunk တွဲ ထည့်ပြီး relevance တစ်ခါတည်း ထုတ် — order ပိုမှန် (fail ရင် original ပြန်) |
| 8 | Semantic Cache | `SemanticCacheService.php` | အဓိပ္ပါယ်တူ question (cosine ≥ 0.95) ကို retrieval+LLM ကျော်ဖြေ — version/user/model_hash နဲ့ scope |
| 9 | Query Expansion | `RAGPipelineService::expandQuery` | LLM နဲ့ reformulation အမျိုးမျိုးခေါ် → recall မြှင့် (analytical/comparative က auto-enable) |
| 10 | Filter Extraction + Inheritance | `Pipeline/FilterExtractor.php` | NL → user/project/date; follow-up မှာ အရင်ကတန်ဖိုးတွေ + source docs scope ဆက်ခံ |
| 11 | Model Auto-Select | `RAGPipelineService::buildPipelineContext` | target docs သုံးထားတဲ့ embedding model ကို auto-detect → dimension mismatch ကာကွယ် |
| 12 | SSE Streaming | `RAGPipelineService::askStream` | rewrite_info → status → sources → chunk → done events; မြန်မာ/English bilingual status |
| 13 | Answer Guardrails | `ChunkProcessor` + `ResponseBuilder` | confidence note, old-doc warning, Lost-in-the-Middle reorder, Search Scope note, num_ctx |
| 14 | Tenant Isolation | `RAGPipelineService::applyTenantIsolation` | user_ids ကို ကိုယ့် id ထဲပဲထား, document_ids ကို ကိုယ်ပိုင် docs ထိပဲဖြတ် (ISO 27002:8.3 / 27701) |

### Deep Dive — တစ်ခုချင်းစီ

**(၁) Query Rewriting** — `Pipeline/QueryRewriterService.php`

Raw question ကို တန်းထည့်ရင် "မနေ့က ရာသီဥတုရော" ကို vector/FTS က မှားတွေ့တယ်။ `rewrite()` က 3-stage လုပ်:
1. **Complexity score** (`calculateComplexity`): word count (0–3) + negation ရှိတိုင်း +2 (`မဟုတ်/မ/မရှိ`) + logical operator (`နဲ့/သို့/ဒါပေမယ့်`) + ambiguous term (`အဲဒါ/ဒါ/ဟို/ဘယ်လို`) +2
2. **SIMPLE (score < 5):** rule-based — `resolveTimeReferences()` (မနေ့က → literal date) → `fixSpelling()` (ရသဥတု→ရာသီဥတု, static `SPELLING_MAP`) → `expandSynonyms()` (embedding text မှာ "ရာသီဥတု မိုးလေဝသ" နှစ်မျိုးလုံးထည့်)
3. **COMPLEX (score ≥ 5):** အပေါ်က အကုန်လုပ်ပြီး **LLM reformulation ပါ** — `expandQuery()`

Result က `RewrittenQuery{embeddingText, ftsQuery, mode, score}` — streaming မှာ `rewrite_info` SSE event နဲ့ ပို့တယ်။

**(၂) Boolean FTS Query** — `QueryRewriterService::buildBooleanFtsQuery`

Question ကို boolean expression အဖြစ်ပြောင်း: adjacent ASCII words → `&` (AND); synonym ရှိတဲ့ word → `(word | synonym)`; negation → `!` (NOT)။ **ဖျက်ထုတ်တာ:** non-ASCII tokens (`to_tsvector('english')` က non-English ကို index မလုပ်လို့), hyphenated dates (`2026-06-09` — date filtering က SQL `report_date` filter နဲ့ပြီးသား), bare numbers, pure stopwords (တယ်/သည်/မှာ/အတွက်…). `PgvectorDriver` က `boolean_fts_query` ရှိရင် `to_tsquery('english', ...)`, မရှိရင် `plainto_tsquery` fallback. `sanitiseBooleanFts()` က syntax error မဖြစ်အောင် နောက်တစ်ခါ ဆန်းစစ်။

**(၃) Question Classifier** — `QuestionClassifier.php`

LLM မခေါ်ဘဲ lexical pattern နဲ့ ခွဲတယ်:
- `comparative` (compare/vs/ကွဲပြား/နှိုင်း) → recall boost
- `analytical` (trend/analy/explain/why/ဘာကြောင့်) → recall boost + multi-query expansion
- `factual` (who/what/when/how much/ဘယ်သူ/ဘယ်လောက်) → **keyword_boost** — search mode ကို hybrid အတင်းပြောင်း (exact names/numbers က FTS နဲ့ကိုက်တာ ပိုမှန်လို့)
- `generic` → default

**(၄) Hybrid Search + Adaptive Mode** — `PgvectorDriver`

Default mode `hybrid`: vector (cosine) + FTS ပြိုင်ပြေး → `fuseResults()` **RRF** (`score = 1/(60+vectorRank) + 1/(60+ftsRank)`) နဲ့ပေါင်း။ `factual` ဖြစ်ရင် mode ဘာပဲထားထား hybrid ကိုပဲသုံး။ Query အနည်းမျိုးစုံရှိရင် (`expandQuery`) တစ်ခုချင်းစီ embed + search → `chunk_id` နဲ့ dedupe → score sort။

**(၅) Dynamic Threshold (Elbow Method)** — `ChunkProcessor::applyDynamicThreshold`

Fixed cutoff (0.65) ရဲ့ အဆင်မပြေတာကို အဆင့် 4 နဲ့ဖြေ:
1. Scores sort → ဆက်တိုက် score တွေကြားက **အကြီးဆုံး gap** ကိုရှာ
2. Gap က `gap_significance` (0.15) ကျော်ရင် အဲဒီနေရာမှာဖြတ်
3. Gap မရှိရင် `max(base, top_score × 0.85)` — `score_scaling`
4. Filter ရှိနေရင် base threshold ကို 0.45 (`filtered_base`) လျှော့ — narrow search ဖြစ်လို့
5. အကုန်ကျလွန်ရင် safety valve: top score ≥ 0.25 (`fallback_min`) ဆို အကောင်းဆုံး 1 chunk ပြန်ထား

vector store ကို floor `min_initial_threshold` (0.20) နဲ့ခေါ်ပြီး elbow က အပေါ်မှာ cutoff ရှာတယ် — floor မမြင့်ရင် elbow ကိုတွေ့မှာမဟုတ်ဘူး။

**(၆) MMR Diversity** — `ChunkProcessor::applyMMR`

```php
MMR = λ×relevance − (1−λ)×(same-document penalty)
// λ = 0.7 (mmr_lambda), penalty = contentJaccard()
```
တူတဲ့အကြောင်းအရာ ၃ ခုထည့်မယ့်အစား မတူတဲ့ ၃ ခုထည့်ဖို့ — context window အသုံးချမှု ပိုထိရောက်။ ပထမ chunk က အမြဲ highest-scoring။

**(၇) Cross-Encoder Reranker (optional, default off)** — `RerankerService.php`

Bi-encoder (retrieval) က question နဲ့ chunk ကို သပ်သပ်ထည့်ပြီး cosine တွက် — order က အကြမ်း။ Cross-encoder က question+chunk **တွဲထည့်ပြီး** relevance score တစ်ခါတည်းထုတ် — အရည်အသွေးပိုမြင့်။ Ollama-compatible `/api/rerank` (bge-reranker:latest), `candidate_k=8` → rerank → ကျန် `final_k=5`. Fail ဖြစ်ရင် original order ပြန် (graceful degradation). Enable: `RAG_RERANKER_ENABLED=true`။

**(၈) Semantic Cache** — `SemanticCacheService`

Exact cache (MD5 embedding cache) နဲ့ကွာသွားတာက semantic cache က **အဓိပ္ပါယ်တူတဲ့** question ကိုပါ hit တယ် ("Q3 revenue?", "သုံးလပတ် ဝင်ငွေ?", "third-quarter earnings?" — exact text တူစရာမလို)။
- **Hit detection:** incoming question embed → `rag_answer_caches` table နဲ့ cosine ≥ `semantic_threshold` (0.95) → hit
- **Scope key ၃ ခု (leak မဖြစ်ဖို့):** `version` — `KnowledgeVersion::current()` (corpus ပြောင်းတိုင်း cache တစ်ပြားလုံး invalidate); `user_id` — user အချင်းချင်း မမြင်ရ; `model_hash` — `md5("model|temperature|max_tokens")`
- **Fine-grained invalidation (Phase 2):** entry တစ်ခုစီမှာ `document_ids` + `doc_versions` snapshot — `isStale()` က source doc ပြောင်းထားလား စစ်ပြီး entry ချည်းပဲ ဖျက်
- **ဘယ်အခါ skip:** session history ရှိနေရင် (follow-up) နဲ့ `cacheEnabled=false`။ Refusal ကိုလည်း cache လုပ်။ TTL 7 ရက် (`ttl_days`) → prune

**(၉) Query Expansion** — `RAGPipelineService::expandQuery`

`RAG_QUERY_EXPANSION_ENABLED` ရင် LLM ကို "ရလဒ်ပိုရအောင် ပုံစံအမျိုးမျိုးနဲ့ပြန်ရေး" → reformulation `num_queries` ခု → original ပါထည့်။ **Adaptive boost:** question type က analytical/comparative ဖြစ်ရင် global toggle off ဖြစ်နေရင်တောင် auto-enable (recall အရေးကြီးလို့). LLM fail ရင် original question တစ်ခုတည်းနဲ့ fallback။

**(၁၀) Filter Extraction + Follow-up Inheritance** — `Pipeline/FilterExtractor.php`

- **Extract:** user names (users table, 5min cache) / project names (distinct project, 5min cache) / date patterns (YYYY-MM-DD, YYYY-MM, YYYY-MonthName, MonthName DD, Q1 2026, bare year) / Burmese month နာမည် (ဇန်နဝါရီ…ဒီဇင်ဘာ) + Burmese digit normalization (၀-၉ → 0-9) + quarter
- **Inheritance (follow-up):** user/project မပါရင် အရင်မေးခွန်းကယူ; **date ကိုတော့ အရင်အဖြေက refusal ဖြစ်ခဲ့ရင် inherit မလုပ်**; follow-up ဆို အရင်အဖြေရဲ့ `document_ids` ကိုပါ citation scope အဖြစ်ထည့်
- **Time reference:** `resolveTimeReferences()` က "ဒီနေ့/မနေ့/ဒီလ/ပြီးခဲ့တဲ့အပတ်" (မြန်မာ+အင်္ဂလိပ်) → literal date — LLM က "ယနေ့" မှန်းရမလိုဘူး

**(၁၁) Dynamic Embedding Model Selection** — `buildPipelineContext`

Filter နဲ့ target documents တွေက ဘယ် embedding model နဲ့ embed လုပ်ထားလဲ (`distinct embedding_model_id`) ကြည့်ပြီး:
- တစ်မျိုးတည်းပဲရှိရင် → question ကိုလည်း အဲဒီ model နဲ့ embed (`ProviderFactory` → `new EmbeddingService`) — **dimension mismatch ကာကွယ်**
- ကွဲနေရင် / မသိနိုင်ရင် → active default model

**(၁၂) Streaming SSE Lifecycle** — `RAGPipelineService::askStream()`

Question ထဲ Burmese (`\x{1000}-\x{109F}`) ပါရင် status messages တွေ မြန်မာလိုပြ။ Event sequence:

| Event | ဘယ်အချိန် | Payload |
|---|---|---|
| `rewrite_info` | query rewrite ပြီး | score, mode, embedding_text, fts_query, question_type |
| `status (embedding)` | embed လုပ်နေ | မြန်မာ/English message |
| `status (searching)` | search လုပ်နေ | — |
| `cached` | semantic cache hit | sources |
| `sources` | chunks တွေ့ | document citations |
| `status (generating)` | LLM ခေါ်နေ | — |
| `chunk` | token တစ်ပိုင်းစီ | content |
| `done` | ပြီးဆုံး | session_id, times, tokens_used, cached |

**(၁၃) Answer Quality Guardrails**

- **`assessConfidence`:** ≥3 chunks & avg ≥0.60 → high; else low → system prompt မှာ "Based on limited available information…" note
- **`hasOldDocuments`:** source > 1 နှစ် → "Some sources are over a year old" rule
- **`reorderForLostInTheMiddle`:** best-first, worst-last — LLM တွေ context အလယ်ကို မေ့တတ်တာကို balance
- **Search Scope note:** applied filters (e.g. "date: 2026-05-15") ကို context ရှေ့ထည့် — LLM က "yesterday" အနက် ကိုယ့်ဟာကိုယ် မှန်းရမလို
- **History window (4):** အရင် message အနည်းငယ် prompt ထဲထည့်
- **`finish_reason='length'`:** token limit ကျော်ရင် "ဖြတ်တောက်ထားပါသည်" မှတ်ချက်
- **num_ctx:** `buildLlmOptions()` က active LLM ရဲ့ `max_context_tokens` ကို Ollama `num_ctx` အဖြစ်ပို့

**(၁၄) Tenant Isolation** — `RAGPipelineService::applyTenantIsolation()`

Controller မှာ document_filter ကို whitelist + owner-scope လုပ်ပြီးသားဖြစ်ပေမယ့် pipeline ထဲမှာ defense-in-depth အဖြစ် **တစ်ခါထပ် ချုပ်**: user ၀င်ထားရင် `user_ids` ကို `[ကိုယ့် user id]` ထဲပဲထား; `document_ids` ကို ကိုယ်ပိုင် documents အထိပဲ ဖြတ်; `RAG_TENANT_ISOLATION=false` ထားမှသာ org-wide corpus (ISO 27002:8.3 / 27701)။

**Config priority (အရေးကြီး):** `config/rag.php` → AiModel `settings` JSONB → request params — နောက်ပိုင်းက ရှေ့ကို override။ `RAGPipelineService` constructor က `$this->embedder` / `$this->llm` တွေကို active AiModel ရဲ့ DB record ကနေ `ProviderFactory` နဲ့ ပြန်တည်ဆောက်နေတဲ့အတွက် `temperature`, `max_context_tokens`, `timeout`, `model`, `api_key` တွေက အမြဲအနိုင်ရတယ်။

---

## **၅။ Summary Table of Key Logics**

| Feature | Service | Logic/Algorithm | Why? |
| :--- | :--- | :--- | :--- |
| Chunking | `TextChunkingService` | Recursive Separator Priority | အဓိပ္ပာယ်မပြတ်ဘဲ AI ဖတ်ရလွယ်ရန် |
| Search | `PgvectorDriver` | RRF Fusion (Vector + FTS) | စာလုံးရော အဓိပ္ပာယ်ပါ တိကျစွာ ရှာနိုင်ရန် |
| Metadata Filter | `PgvectorDriver` | `metadata @> ?::jsonb` (jsonb contains) | Project/user/date အလိုက် chunks စစ်ထုတ်ရန် |
| Caching | `EmbeddingService` | MD5 Hashing (24h TTL) | API cost သက်သာစေပြီး ပိုမြန်စေရန် |
| Optimization | `RAGPipelineService` | Dynamic Threshold (Elbow Method) | မဆိုင်တဲ့ chunks တွေကို ဖြတ်ချရန် |
| Providers | `ProviderFactory` | Strategy Pattern (5 providers) | Provider အသစ်ထည့်ရန် interface implement လုပ်ရုံပဲ |
| Context | `LLMService` | Token-aware concatenation | Model ၏ limit ထက်မကျော်စေရန် |
| Security | `AuthService` + `AuthenticateWithToken` | SHA-256 Hashed Tokens + Expiry + Sliding Renewal | Token ကို plaintext မသိမ်းဘဲ DB leak ရင်တောင် ပြန်မရနိုင်အောင် (ISO 27002:8.24) |
| Query Rewriting | `QueryRewriterService` | Complexity Scoring → SIMPLE/COMPLEX rewrite | Date/spelling/synonym ကြိုပြင်ပြီး complex query ကို LLM reformulate လုပ်ရန် |
| Semantic Cache | `SemanticCacheService` | Cosine ≥ 0.95 + Version/User/ModelHash scope | အဓိပ္ပါယ်တူ question ကို retrieval+LLM ကျော်ဖြေရန် |
| Reranker | `RerankerService` | Cross-Encoder (bge-reranker) | Bi-encoder order ထက် ပိုမှန်တဲ့ final ordering ရရန် |
| Question Classifier | `QuestionClassifier` | Lexical Type Detection | Factual → FTS-boost, analytical/comparative → recall-boost routing |
| Tenant Isolation | `RAGPipelineService` | user_ids/document_ids self-only scoping | User ချင်း data ရောထွေးမှု ကာကွယ်ရန် (ISO 27002:8.3 / 27701) |
| Credential Encryption | `EncryptedString` cast + `SettingsModuleSeeder` | AES at-rest via Laravel Crypt | ai_models.api_key ကို DB မှာ ciphertext သာ သိမ်းရန် |

---

## **၆။ နိဂုံး**

ဤ project ရှိ Service တစ်ခုချင်းစီသည် ၎င်းတို့၏ တာဝန်ကို အသေးစိတ် logic များဖြင့် တိကျစွာ ဆောင်ရွက်နေကြပြီး `RAGPipelineService` မှ ၎င်းတို့ကို စုစည်းကာ အကောင်းဆုံး အဖြေတစ်ခု ထွက်လာအောင် လုပ်ဆောင်ပေးနေခြင်း ဖြစ်ပါသည်။ Base flow (embed → search → LLM → save) အပြင် semantic caching, query rewriting, dynamic threshold, MMR, optional cross-encoder reranking, embedded security စတဲ့ improvement layer တွေက စနစ်ကို ပိုတိကျ၊ ပိုမြန်ပြီး လုံခြုံစေပါတယ်။

**ဆက်လက်လေ့လာရန်**:
- [RAGPipelineService.php](file:///Users/za/Sites/projects/lumina_rag/modules/ChatModule/Services/RAGPipelineService.php) ထဲက `buildPipelineContext()` → `runSearchQueries()` → `ask()` flow ကို အသေးစိတ် ထပ်ဖတ်ကြည့်ပါ။
- [PgvectorDriver.php](file:///Users/za/Sites/projects/lumina_rag/modules/VectorStoreModule/Services/PgvectorDriver.php) ထဲက `searchHybrid()` + `fuseResults()` method ကို လေ့လာပါ။
- [SemanticCacheService.php](file:///Users/za/Sites/projects/lumina_rag/modules/ChatModule/Services/SemanticCacheService.php) ထဲက version-aware invalidation logic ကို လေ့လာပါ။
