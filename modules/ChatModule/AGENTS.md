# Chat Module

## Overview
Manages conversational AI interactions through RAG pipeline orchestration. Handles chat sessions, message persistence, and coordinates query processing across EmbeddingModule, VectorStoreModule, and LLMModule.

## Responsibility Boundaries

### This Module OWNS:
- Chat session lifecycle (create, retrieve, archive, delete)
- Message storage and retrieval
- RAG pipeline orchestration (question → answer flow)
- Response formatting (text + source citations)
- Streaming response delivery via SSE

### This Module DOES NOT OWN:
- Document text and metadata (→ DocumentModule)
- Text-to-vector conversion (→ EmbeddingModule)
- Vector similarity search (→ VectorStoreModule)
- LLM API communication (→ LLMModule)

## API Contract

### POST /api/chat
Submit a question for RAG-based answering.

**Request Body**:
```json
{
  "question": "string (required, max:1000)",
  "session_id": "integer|null (optional)",
  "stream": "boolean (optional, default: true)",
  "document_filter": {
    "document_ids": [1, 2, 3],
    "date_from": "2024-01-01",
    "date_to": "2024-12-31",
    "meta": {"project": "Orion"}
  }
}
```

**Success Response (Non-streaming)**:
```json
{
  "success": true,
  "data": {
    "session_id": 42,
    "message": {
      "id": 84,
      "role": "assistant",
      "content": "The revenue in Q3 was $45.2 million...",
      "sources": [
        {
          "document_id": 1,
          "document_title": "Q3 Report.pdf",
          "chunk_index": 12,
          "page_number": 5,
          "similarity_score": 0.89,
          "excerpt": "Q3 revenue reached $45.2 million..."
        }
      ],
      "created_at": "2024-12-15T10:30:00Z"
    }
  }
}
```

**Streaming Response**: Server-Sent Events with chunked content delivery.

**Error Responses**:
- 400: Invalid input (empty question, oversized)
- 404: Session not found
- 422: No documents available for query
- 429: Rate limit exceeded
- 500: Pipeline processing error

### GET /api/chat/sessions
List active chat sessions (paginated, 20 per page).

### GET /api/chat/sessions/{id}
Retrieve single session with all messages.

### DELETE /api/chat/sessions/{id}
Soft-delete session and mark for archival.

## Business Rules

### Session Creation
- New session auto-created on first message (no session_id provided)
- Title derived from first 50 characters of question
- Message limit: 100 per session

### Query Processing
- Minimum similarity threshold: 0.65
- If no chunks above threshold → Polite refusal message
- If 1-2 relevant chunks → Answer with low-confidence note
- Context window: 4000 tokens max
- Query rewriting: `QueryRewriterService` scores complexity; SIMPLE (score <5) uses rule-based rewrite (spelling, dates, synonyms), COMPLEX (≥5) triggers LLM reformulation via `expandQuery()`

### Response Generation
- System prompt: Strict context-only instruction with rules for grounding, completeness, no hallucination, citation, language matching, metadata awareness, structure, tone, markdown formatting, conciseness. Low-confidence and old-document warnings appended when applicable.
- Temperature: 0.3 for factual accuracy
- Always include source citations
- Streaming enabled by default

### Error Fallbacks
- Embedding API failure → Retry 3 times, then error
- Vector search returns empty → Refusal message
- LLM timeout → Return partial answer if streamed
- Session full → Clear error, suggest new chat

## Service Dependencies

### Required Services (via interface contracts)
- `EmbeddingServiceInterface`: Question → Vector
- `VectorStoreServiceInterface`: Vector → Relevant chunks
- `LLMServiceInterface`: Prompt + Context → Answer

### Data Flow
```
ChatController
  ↓ (validated request)
RAGPipelineService
  ├→ QueryRewriterService.rewrite(question) → {embeddingText, ftsQuery, mode}
  │    (SIMPLE: rule-based rewrite; COMPLEX: LLM reformulation)
  ├→ EmbeddingService.embed(embeddingText) → Vector
  ├→ VectorStoreService.searchHybrid(ftsQuery, vector, topK, filters)
  │    (uses to_tsquery with AND/OR/NOT when boolean ftsQuery available)
  ├→ LLMService.complete(systemPrompt, context, question) → Answer
  └→ Save ChatMessage (user + assistant roles)
  ↓
StreamingService (if streaming enabled)
```

## Vue Components

### ChatInterface.vue
Main chat area component.

**States**: Empty (no messages), Loading (streaming), Error (retry option), Active (messages present)

**Features**: Auto-scroll, typing indicator, source citations, input with send button

### ChatSidebar.vue
Session list navigation.

**States**: Empty (no sessions), Loading, Error, Active list

### MessageBubble.vue
Individual message display.

**Props**: role (user/assistant), content, sources, isStreaming

### SourceCitation.vue
Expandable source reference card.

**Displays**: Document title, page number, similarity score, excerpt text

## Database Tables
- `chat_sessions` - Session metadata
- `chat_messages` - Message content with source citations (JSONB)

## Seeder
`ChatModuleSeeder` — creates 2 sessions with 2 messages each. Idempotent (skips if sessions exist). Called automatically by `DatabaseSeeder`.

### Performance Targets

---

## Code Documentation Standards

All classes and methods must include comprehensive PHPDoc blocks.

### Requirements:
1.  **Title & Detailed Description**: Clear explanation of purpose.
2.  **Parameters**: `@param {type} $name Description. Example: {example}`.
3.  **Return Type**: `@return {type} Description. Example: {example}`.
4.  **Exceptions**: `@throws {ExceptionClass} Description of when it's thrown. Example: {example}`.

---

## Testing Strategy

### Feature Tests
- POST /api/chat with valid question → 200 with answer
- POST /api/chat with empty question → 422 validation error
- POST /api/chat with no documents → 200 with refusal message
- GET /api/chat/sessions → paginated list
- DELETE /api/chat/sessions/{id} → soft delete

### Unit Tests
- RAGPipelineService with mock dependencies → correct orchestration
- Message creation with sources → proper JSONB storage
- Session title generation → first 50 chars extraction