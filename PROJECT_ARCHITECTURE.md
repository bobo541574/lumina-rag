# Project Architecture

## System Overview
Monolithic Laravel application with modular internal architecture. Vue.js frontend is Vite, served by the same Laravel application. PostgreSQL with pgvector serves as both relational and vector database.

## Architectural Style
- **Monolithic Application**: Single Laravel deployment
- **Modular Internals**: Feature-based module separation
- **Service Layer Pattern**: Business logic in services
- **Repository Pattern**: Data access through services (not direct model usage across modules)
- **Event-Driven (Internal)**: Laravel events for cross-module communication

## Technology Stack

### Backend
| Technology | Purpose | |
|------------|---------|-|
| Laravel 13 | PHP framework | Core application |
| PHP 8.3+ | Language | Runtime |
| PostgreSQL 16 | Primary database | Data + vector storage |
| pgvector 0.6+ | Vector extension | Similarity search |
| Laravel Queue | Job processing | Async document processing |
| Redis | Cache & sessions | Performance |

### Frontend
| Technology | Purpose | |
|------------|---------|-|
| Vue 3 | JavaScript framework | UI components |
| Tailwind CSS | Styling | Design system |
| Pinia | State management | Frontend stores |
| TypeScript | Type safety | Frontend logic |

### External Services
| Service | Purpose | Alternative |
|---------|---------|-------------|
| OpenAI API | Embeddings + LLM | Anthropic, Voyage AI |
| Pinecone | Vector database (optional) | pgvector (default) |
| S3 | File storage (production) | Local storage (dev) |

## Module Architecture

### High-Level Module Map
```
┌─────────────────────────────────────────────────┐
│                  Vue Frontend                     │
│  Chat UI │ Document Upload │ Settings            │
└──────────────────┬──────────────────────────────┘
                   │
┌──────────────────▼──────────────────────────────┐
│              Laravel Backend                      │
│                                                   │
 │  ┌─────────────┐  ┌──────────────────┐  ┌──────────────┐│
│  │  ChatModule │  │ DocumentModule   │  │ UserModule   ││
│  │             │  │                  │  │              ││
│  │ - Sessions  │  │ - Upload         │  │ - Register   ││
│  │ - Messages  │  │ - Text Extract   │  │ - Login      ││
│  │ - Pipeline  │  │ - Chunk Text     │  │ - Token Auth ││
│  └──────┬──────┘  └────────┬─────────┘  └──────────────┘│
│         │                  │                             │
│  ┌──────▼──────────────────▼─────────┐                  │
│  │      EmbeddingModule              │                  │
│  │  - Generate embeddings            │                  │
│  │  - Batch processing               │                  │
│  │  - Caching                        │                  │
│  └──────┬────────────────────────────┘                  │
│         │                                                │
│  ┌──────▼──────────┐  ┌──────────────────┐             │
│  │ VectorStoreModule│  │   LLMModule      │             │
│  │                 │  │                  │     │
│  │ - Store vectors │  │ - Prompt build   │     │
│  │ - Search        │  │ - Completion     │     │
│  │ - pgvector      │  │ - Streaming      │     │
│  └─────────────────┘  └──────────────────┘     │
│                                                   │
└───────────────────────┬───────────────────────────┘
                        │
            ┌───────────▼───────────┐
            │   PostgreSQL + pgvector │
            │                        │
            │  • chat_sessions       │
            │  • chat_messages       │
            │  • documents           │
            │  • document_chunks     │
            │  • vector_embeddings   │
            └────────────────────────┘
```

## Data Flow

### Question-Answer Flow
```
1. User sends question via ChatInterface.vue
2. ChatController receives request at `POST /api/chat`
3. ChatController validates input
4. RAGPipelineService orchestrates:
   a. EmbeddingService.embed(question) → Vector
   b. VectorStoreService.search(vector, topK=5) → Chunks
   c. If scores < threshold → Return "unable to answer"
   d. LLMService.complete(context, question) → Answer
5. ChatMessage saved (user + assistant)
6. Response returned to frontend
7. ChatInterface updates with answer + sources
```

### Document Processing Flow (Async, Queue-monitored)
```
1. User uploads file via DocumentUpload.vue
2. DocumentController:
   a. Validates file
   b. Creates Document record (status: pending)
   c. Stores file
   d. Dispatches ProcessDocumentJob
3. Response returns immediately (status: pending)
4. Queue worker picks up ProcessDocumentJob (start via `php artisan queue:work`):
   a. TextExtractionService.extract(file) → Raw text
   b. TextChunkingService.chunk(text) → Chunks[]
   c. For each batch of 100 chunks:
      - EmbeddingService.embedBatch(texts) → Vectors[]
      - VectorStoreService.upsert(vectors) → Stored
   d. DocumentChunk records saved
   e. Update status: processing → completed
5. Frontend polls or receives notification of completion
6. Monitor job status via `php artisan queue:work` logs or the `jobs` and `failed_jobs` database tables.
```

## Database Strategy

### Why PostgreSQL + pgvector
- Single database for relational data AND vectors
- No additional infrastructure for development
- ACID compliance for both data types
- pgvector IVFFlat index for fast similarity search
- Transaction support across document and vector operations
- Switch to Pinecone only when scale demands it

### Key Database Design Decisions
- Vector column type: `vector(1536)` matching OpenAI embedding dimensions
- Similarity search: Cosine distance (<=>)
- Index: IVFFlat with appropriate lists parameter
- Chunk table stores both text AND vector reference
- No separate vector database needed for MVP

## Security Architecture

### Authentication
- API token-based auth (80-char random tokens, no Sanctum)
- Token sent via `Authorization: Bearer` header
- Register, login, logout, and profile endpoints in UserModule
- CSRF protection enabled for web routes

### Authorization
- Module-level middleware for enabled/disabled features
- Resource ownership checks (documents belong to users)
- Rate limiting on chat and upload endpoints

### Data Protection
- File upload scanned for malware (optional integration)
- API keys stored in .env, never in codebase
- Vector embeddings at rest in PostgreSQL (same security as relational data)
- No user PII stored in embeddings

## Scalability Considerations

### Current Scale (Monolithic)
- Handles: Up to 100K documents, 1M chunks
- pgvector performance: Sub-second similarity search with IVFFlat
- Queue workers: Horizontally scalable

### Future Scale (Service Transition)
- Extract VectorStoreModule to dedicated Pinecone (if chunks > 10M)
- Extract EmbeddingModule to dedicated service (if throughput needs)
- Add Redis for query caching
- Read replicas for PostgreSQL if read-heavy
