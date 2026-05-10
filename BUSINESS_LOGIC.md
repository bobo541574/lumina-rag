# Business Logic & Domain Rules

## System Purpose
Intelligent document Q&A system using RAG (Retrieval-Augmented Generation). Users upload documents and can ask natural language questions. The system retrieves relevant document sections and generates accurate, sourced answers.

## Document Processing Domain

### Upload Validation Rules
- **Allowed Formats**: PDF, DOCX, TXT, CSV, Markdown
- **Maximum File Size**: 10MB per document
- **Duplicate Detection**: SHA-256 file hash comparison before processing
- **File Integrity**: Verify file is readable and not corrupted
- **Content Check**: Document must contain extractable text (not image-only PDF)

### Text Extraction Rules
- **PDF**: Extract text stream, maintain paragraph structure where possible
- **DOCX**: Extract with heading hierarchy (H1-H6) as structure hints
- **TXT/CSV**: Direct read with UTF-8 encoding detection, fallback to auto-detect
- **Markdown**: Strip markup, preserve link text, maintain section structure
- **Empty Documents**: Reject with clear error message

### Chunking Strategy
- **Algorithm**: Recursive Character Text Splitter
- **Chunk Size**: 1000 characters (configurable)
- **Chunk Overlap**: 200 characters (configurable)
- **Separator Priority**: Paragraph breaks → Line breaks → Sentence endings → Commas → Spaces → Character
- **Chunk Metadata**: Each chunk records original position, page number (if PDF), and sequence

**Overlap Rationale**: 200-character overlap prevents critical information from being split across chunk boundaries. A sentence spanning position 950-1050 appears in both chunks 1 and 2.

### Embedding Generation
- **Model**: OpenAI text-embedding-ada-002 (1536 dimensions)
- **Batch Size**: 100 texts per API call (cost optimization)
- **Caching**: MD5 hash-based cache, 24-hour TTL
- **Retry Strategy**: 3 attempts with exponential backoff (1s, 5s, 25s)
- **Error Fallback**: If embedding fails, mark chunk as "failed", document as partial

## Question-Answer Domain

### RAG Pipeline Logic
1. **Question Embedding**: Convert question to vector using same embedding model
2. **Vector Search**: 
   - Search all document chunks for top 5 most similar
   - Use cosine distance (<=>) operator via pgvector
   - Filter by similarity threshold (default: 0.65)
3. **Threshold Handling**:
   - Top result < 0.65 → Respond "I cannot answer this question based on the available documents."
   - 1-2 results > 0.65 → Provide answer with low confidence note
   - 3-5 results > 0.65 → Provide full answer with citations
4. **Context Assembly**:
   - Sort chunks by similarity score descending
   - Concatenate with source labels
   - Truncate if exceeds 4000 tokens
5. **Prompt Construction**: Inject context into system prompt template
6. **LLM Completion**: Generate answer with temperature 0.3 (factual focus)
7. **Response Packaging**: Return answer + source citations

### Source Citation Rules
- Every answer MUST include source citations
- Citation format: Document title + position reference
- If answer draws from multiple documents, cite each
- Chunk similarity score included in citation metadata
- In streaming mode, sources sent as final event

### Answer Quality Rules
- **No Hallucination**: Model instructed to ONLY use provided context
- **Uncertainty Expression**: If context is ambiguous, acknowledge uncertainty
- **Language Consistency**: Answer in same language as question
- **Outdated Warning**: If document is > 1 year old, add "This document is X months old" note

## Chat Session Domain

### Session Management
- **Auto-Creation**: New session created on first message if no session ID provided
- **Session Title**: First 50 characters of user's first question
- **Message Limit**: Maximum 100 messages per session (user + assistant combined)
- **Session Expiry**: Sessions inactive for 24 hours are archived
- **Auto-Deletion**: Archived sessions deleted after 30 days

### Message Structure
- **User Message**: role=user, content=question text
- **Assistant Message**: role=assistant, content=answer, sources=JSON array
- **No Editing**: Messages are immutable (no update, only soft delete)

### Streaming Rules
- **Default**: Streaming enabled for all chat responses
- **Fallback**: Non-streaming HTTP response if client doesn't support SSE
- **Buffer Size**: Stream chunks of 10-20 tokens for smooth UI
- **Connection Drop**: If SSE connection drops, message saved as partial

## Authentication Domain

### Registration Rules
- Name, email, and password (min 8 chars) required
- Email must be unique across all users
- Password hashed with Bcrypt before storage
- Returns user profile + 80-char random API token

### Login Rules
- Valid email + password combination required
- Invalid credentials return generic "Invalid email or password" message (no enumeration)
- Each login generates a new API token, invalidating the previous one
- Token must be sent as `Authorization: Bearer <token>` header

### Token Security
- Tokens are 80-character hex strings (`bin2hex(random_bytes(40))`)
- Tokens stored in `users.api_token` column (unique, nullable)
- Logout sets token to null
- No token expiry (session persists until logout)

## Module Interaction Rules

### ChatModule → EmbeddingModule
- ChatModule requests embedding for user's question
- EmbeddingModule handles provider selection and caching
- ChatModule never calls OpenAI API directly

### ChatModule → VectorStoreModule
- ChatModule requests top-K chunks using embedded question
- VectorStoreModule handles pgvector query optimization
- ChatModule receives only chunks + scores, not raw vectors

### ChatModule → LLMModule
- ChatModule sends assembled prompt + context
- LLMModule handles provider selection and streaming
- ChatModule receives either stream or complete response

### DocumentModule → EmbeddingModule
- DocumentModule sends batch of chunk texts
- EmbeddingModule returns batch of vectors
- DocumentModule never stores vectors (that's VectorStoreModule's job)

### DocumentModule → VectorStoreModule
- DocumentModule sends vectors + metadata for storage
- VectorStoreModule handles pgvector INSERT + index maintenance
- DocumentModule updates chunk records with vector IDs

## Edge Cases & Error Handling

### Document Processing Edge Cases
- **Large File**: 100MB PDF → Reject (exceeds 10MB limit)
- **Image-Only PDF**: Text extraction returns empty → Fail with message
- **Single Document, 1000 Chunks**: Process in 10 batches (100/batch) through queue
- **Embedding API Down**: Job fails, retries 3 times, then marks document as failed
- **Duplicate Upload**: Detect by hash → Return existing document ID with message

### Query Edge Cases
- **Empty Question**: Return validation error
- **Question > 1000 chars**: Truncate to 1000 chars before embedding
- **No Documents in System**: Return "No documents have been uploaded yet."
- **All Chunks Below Threshold**: Return "No relevant information found."
- **Contradictory Chunks**: Acknowledge in answer: "Documents contain conflicting information..."

### Session Edge Cases
- **Deleted Session**: Return 404 on session retrieval
- **Expired Session**: Auto-create new session on next message
- **100-Message Limit**: Return error on 101st message: "Session full, start new chat"
- **Concurrent Messages**: Lock session to prevent race conditions


## Queue Monitoring

Document processing jobs use the `database` queue driver by default. Start the worker with `php artisan queue:work`. Monitoring is via `php artisan queue:table` logs or queue job inspection.

## Monitoring & Observability

### Key Metrics
- Document processing time (upload → completed)
- Embedding API latency and error rate
- Vector search query time (pgvector performance)
- LLM response time
- Chat session activity

### Logging Standards
- **INFO**: Document upload, processing start/complete, chat session created
- **WARNING**: Retry attempts, threshold misses, long processing times
- **ERROR**: API failures, extraction failures, database errors
- **CRITICAL**: Data loss, system unavailable
```
