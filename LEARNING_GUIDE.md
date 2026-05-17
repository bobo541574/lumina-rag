# Lumina RAG Project - Comprehensive Learning Guide (မြန်မာဘာသာ)

ဤ Guide သည် Lumina RAG project ၏ codebase structure၊ အသေးစိတ် business logic၊ database structure နှင့် frontend architecture တို့ကို အခြေခံမှ အဆင့်မြင့်အထိ တစ်နေရာတည်းတွင် လေ့လာနိုင်ရန် ပြုစုထားခြင်း ဖြစ်ပါသည်။

---

## **၁။ Project Overview & Technology Stack**

Lumina RAG သည် Retrieval-Augmented Generation (RAG) စနစ်တစ်ခုဖြစ်ပြီး၊ ကိုယ်ပိုင် document များ (PDF, DOCX, TXT) ကို upload တင်၍ ၎င်းတို့အပေါ် အခြေခံကာ AI ကို မေးခွန်းများ မေးမြန်းနိုင်သော system တစ်ခုဖြစ်သည်။

- **Backend**: Laravel 11 (Modular Monolith Architecture)
- **Database**: PostgreSQL 16 + pgvector (Vector storage အတွက်)
- **Frontend**: Vue 3 + Pinia + Tailwind CSS v4
- **AI Integration**: OpenAI (GPT-4o) သို့မဟုတ် Ollama (Local)

---

## **၂။ Codebase Structure (Modules)**

Project ကို `modules/` directory အောက်တွင် အပိုင်း (၇) ပိုင်း ခွဲခြားထားသည်။ Module တစ်ခုစီတွင် ၎င်းနှင့်ဆိုင်သော Controller, Service, Model နှင့် Migrations များ သီးသန့်ရှိသည်။

| Module | တာဝန်ယူမှု (Responsibility) |
| :--- | :--- |
| [ChatModule](file:///Users/za/Sites/projects/lumina_rag/modules/ChatModule) | Chat sessions နှင့် RAG pipeline တစ်ခုလုံးကို ထိန်းချုပ်ခြင်း။ |
| [DocumentModule](file:///Users/za/Sites/projects/lumina_rag/modules/DocumentModule) | Document upload၊ Extraction နှင့် Chunking ပြုလုပ်ခြင်း။ |
| [EmbeddingModule](file:///Users/za/Sites/projects/lumina_rag/modules/EmbeddingModule) | စာသားများကို Vector (နံပါတ်စဉ်များ) အဖြစ် ပြောင်းလဲပေးခြင်း။ |
| [VectorStoreModule](file:///Users/za/Sites/projects/lumina_rag/modules/VectorStoreModule) | Vector များကို သိမ်းဆည်းခြင်းနှင့် Similarity Search လုပ်ခြင်း။ |
| [LLMModule](file:///Users/za/Sites/projects/lumina_rag/modules/LLMModule) | AI Model (LLM) နှင့် ဆက်သွယ်ပြီး အဖြေထုတ်ပေးခြင်း။ |
| [SettingsModule](file:///Users/za/Sites/projects/lumina_rag/modules/SettingsModule) | AI models များ၏ config များကို စီမံခန့်ခွဲခြင်း။ |
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

---

### **E. UserModule & SettingsModule - စနစ်စီမံခန့်ခွဲခြင်း**

#### **၁။ [AuthService.php](file:///Users/za/Sites/projects/lumina_rag/modules/UserModule/Services/AuthService.php)**
လုံခြုံရေးနဲ့ user စီမံခန့်ခွဲမှုကို ဆောင်ရွက်ပါတယ်။
- **Token Generation**: Login ဝင်တဲ့အခါ `bin2hex(random_bytes(40))` သုံးပြီး unique ဖြစ်တဲ့ ၈၀-character API token ကို ထုတ်ပေးပါတယ်။ ဒါကို database မှာ သိမ်းပြီး user ရဲ့ requests တိုင်းကို စစ်ဆေးပါတယ်။
- **Password Hashing**: User ရဲ့ password တွေကို plain text မသိမ်းဘဲ Bcrypt hashing သုံးပြီး သိမ်းတဲ့အတွက် database ပေါက်ကြားခဲ့ရင်တောင် password တွေကို သိနိုင်မှာ မဟုတ်ပါဘူး။

#### **၂။ [AiModelService.php](file:///Users/za/Sites/projects/lumina_rag/modules/SettingsModule/Services/AiModelService.php)**
AI Models တွေရဲ့ configuration တွေကို စီမံပေးပါတယ်။
- **Validation Rules**: Model တစ်ခုချင်းစီရဲ့ type (embedding/llm) ပေါ်မူတည်ပြီး သက်ဆိုင်ရာ validation rules တွေကို dynamically ထုတ်ပေးပါတယ်။ (ဥပမာ- embedding ဆိုရင် dimensions ပါရမယ်၊ llm ဆိုရင် temperature ပါရမယ်)
- **Model Registry**: System တစ်ခုလုံးမှာ code ပြင်စရာမလိုဘဲ UI ကနေ OpenAI သို့မဟုတ် Ollama model တွေကို အလွယ်တကူ တိုးနိုင်၊ လျှော့နိုင်အောင် စီစဉ်ထားပါတယ်။

---

### **F. ChatModule - RAG Orchestration**

#### **၁။ [RAGPipelineService.php](file:///Users/za/Sites/projects/lumina_rag/modules/ChatModule/Services/RAGPipelineService.php)**
System တစ်ခုလုံးရဲ့ step-by-step စီးဆင်းမှုကို စီမံခန့်ခွဲပါတယ်။
- **Burmese Normalization**: မြန်မာဂဏန်း (၀-၉) တွေကို English (0-9) ပြောင်းပြီးမှ ရက်စွဲတွေ ရှာပါတယ်။
- **Inheritance Logic**: User က "ဘယ်သူ့အစီရင်ခံစာလဲ" လို့ မေးပြီးနောက် "အဲ့ဒါက ဘယ်အချိန်ကလဲ" လို့ ထပ်မေးရင် (follow-up) အရှေ့မေးခွန်းက user id/project တွေကို အလိုအလျောက် ယူသုံးပေးပါတယ်။
- **Confidence Assessment**: ရှာတွေ့တဲ့ similarity score တွေအရ အဖြေက စိတ်ချရမှု ရှိမရှိ (High/Low) သတ်မှတ်ပြီး AI ကို instruction ပြောင်းပေးပါတယ်။

---

## **၄။ Summary Table of Key Logics**

| Feature | Service | Logic/Algorithm | Why? |
| :--- | :--- | :--- | :--- |
| Chunking | `TextChunkingService` | Recursive Separator Priority | အဓိပ္ပာယ်မပြတ်ဘဲ AI ဖတ်ရလွယ်ရန် |
| Search | `PgvectorDriver` | RRF Fusion (Vector + FTS) | စာလုံးရော အဓိပ္ပာယ်ပါ တိကျစွာ ရှာနိုင်ရန် |
| Caching | `EmbeddingService` | MD5 Hashing (24h TTL) | API cost သက်သာစေပြီး ပိုမြန်စေရန် |
| Optimization | `RAGPipelineService` | Dynamic Threshold (Elbow Method) | မဆိုင်တဲ့ chunks တွေကို ဖြတ်ချရန် |
| Context | `LLMService` | Token-aware concatenation | Model ၏ limit ထက်မကျော်စေရန် |
| Security | `AuthService` | Secure Random Tokens | လုံခြုံစိတ်ချရသော API access ရရှိရန် |

---

## **၅။ နိဂုံး**

ဤ project ရှိ Service တစ်ခုချင်းစီသည် ၎င်းတို့၏ တာဝန်ကို အသေးစိတ် logic များဖြင့် တိကျစွာ ဆောင်ရွက်နေကြပြီး `RAGPipelineService` မှ ၎င်းတို့ကို စုစည်းကာ အကောင်းဆုံး အဖြေတစ်ခု ထွက်လာအောင် လုပ်ဆောင်ပေးနေခြင်း ဖြစ်ပါသည်။

**ဆက်လက်လေ့လာရန်**:
- [RAGPipelineService.php](file:///Users/za/Sites/projects/lumina_rag/modules/ChatModule/Services/RAGPipelineService.php) ထဲက `extractFiltersFromQuestion()` method ကို အသေးစိတ် ထပ်ဖတ်ကြည့်ပါ။
- [PgvectorDriver.php](file:///Users/za/Sites/projects/lumina_rag/modules/VectorStoreModule/Services/PgvectorDriver.php) ထဲက `fuseResults()` method ကို လေ့လာပါ။
