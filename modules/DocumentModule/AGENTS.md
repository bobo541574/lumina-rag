# Document Module

## Overview
Manages the complete document lifecycle: upload, validation, text extraction, intelligent chunking, and initiating async processing. Does not handle embedding generation or vector storage directly.

## Responsibility Boundaries

### This Module OWNS:
- File upload and validation
- Document record management (CRUD)
- Text extraction from multiple formats
- Recursive character text splitting
- Processing job orchestration
- Processing status tracking

### This Module DOES NOT OWN:
- Embedding generation (→ EmbeddingModule)
- Vector storage and search (→ VectorStoreModule)
- Chat or query functionality (→ ChatModule)

## API Contract

### POST /api/documents
Upload a new document for processing.

**Request**: multipart/form-data
- `file`: Document file (required)
- `title`: Custom title (optional, defaults to filename)
- `embedding_model`: Free-form embedding model name override (optional)
- `embedding_model_id`: ULID of an `ai_models` row of `type = embedding` (optional)

**Validation**:
- MIME types: PDF, DOCX, TXT, CSV, MD
- Max size: 50MB
- SHA-256 deduplication check

**Success Response**:
```json
{
  "success": true,
  "message": "Document uploaded successfully and queued for processing",
  "data": {
    "id": 1,
    "title": "Q3 Financial Report.pdf",
    "status": "pending",
    "file_size": 2456789,
    "mime_type": "application/pdf",
    "created_at": "2024-12-15T10:30:00Z"
  }
}
```

**Error Responses**:
- 400: Invalid file type
- 413: File too large
- 409: Duplicate file detected
- 422: Corrupted or unreadable file

### GET /api/documents
List all documents with status (paginated, 20 per page).

### GET /api/documents/{id}
Single document details including chunk count.

### GET /api/documents/{id}/status
Processing status endpoint for polling.

### DELETE /api/documents/{id}
Soft delete document, chunks, and associated vectors.

## Business Rules

### Upload Validation
- SHA-256 hash computed on upload
- Compared against existing file_hash column (unique index)
- Duplicate returns HTTP 409 with existing document record

### Text Extraction
- PDF: Extract text stream, preserve paragraphs
- DOCX: Extract with heading structure
- TXT/CSV: Direct read, UTF-8 detection
- MD: Strip markup, preserve link text

### Chunking Strategy
- Algorithm: Recursive Character Text Splitter
- Separator priority: \n\n → \n → . → , → space → char
- Chunk size: 1000 characters (configurable)
- Overlap: 200 characters (configurable)
- Metadata captured per chunk: position, page, character range, section (heading), document-level fields (user_id, user_name, project, report_date, document_title) stored in JSONB `metadata` column

### Processing Pipeline (Async)
1. Text extraction
2. Text chunking
3. Batch embedding generation (via EmbeddingModule)
4. Vector storage (via VectorStoreModule)
5. Document chunk record creation
6. Status update: pending → processing → completed

### Error Handling
- Extraction failure → Status: failed, store error message
- Embedding API failure → Retry 3x, then fail
- Partial processing → Mark completed with error count
- All cases: Update error_message column

### Status Machine
```
pending → processing → completed
                    ↘ failed → retry → processing
```

## Queue Job: ProcessDocumentJob

### Job Lifecycle
- **Queue**: `document-processing` (dedicated queue)
- **Retries**: 3 attempts with backoff (30s, 5min, 30min)
- **Timeout**: 10 minutes per attempt
- **Failure**: Document marked as failed after all retries exhausted

### Processing Steps
1. Load document record
2. Extract text based on mime_type
3. Split text into chunks
4. Process chunks in configurable batches (default 100):
   - Send texts to EmbeddingModule
   - Receive vectors
   - Send vectors+metadata to VectorStoreModule
   - Create DocumentChunk records
5. Update document: status=completed, chunks_count, processed_at

## Vue Components

### DocumentUpload.vue
Drag-and-drop upload zone.

**States**: Idle, Dragging, Uploading (progress %), Success, Error

**Features**: File type validation, size check, progress indicator

### DocumentList.vue
Table display of uploaded documents.

**Columns**: Title, Status (badge), Size, Chunks, Date, Actions

**Features**: Sort, filter by status, delete with confirmation

### DocumentStatusBadge.vue
Status indicator with color coding.

**Mapping**: pending→yellow, processing→blue, completed→green, failed→red

## Database Tables
- `documents` - Document metadata and status
- `document_chunks` - Text chunks with position metadata + JSONB metadata + tsvector FTS column

## Seeder
`DocumentModuleSeeder` — creates 1 document ("Sample Report") with 3 chunks. Idempotent (skips if document exists). Called automatically by `DatabaseSeeder`.

## Testing Strategy

### Feature Tests
- POST /api/documents with valid PDF → Upload successful, status pending
- POST /api/documents with invalid type → 400 error
- POST /api/documents with duplicate file → 409 with existing ID
- GET /api/documents → Paginated list
- DELETE /api/documents/{id} → Soft delete

### Unit Tests
- TextChunkingService.chunk() → Correct chunk count and overlap
- TextExtractionService.extract() for each format → Expected text output
- File hash computation → Consistent SHA-256

## Processing Considerations

### Large Documents
- 500-page PDF: Extract text in sections
- 10,000+ chunks: Process in configurable batches (default 100)
- Memory management: Stream large files, don't load entirely
- Queue timeout: 10 minutes per attempt

### Performance Targets
- 50MB PDF: Complete processing within 2 minutes
- 1,000 chunks: Process within 30 seconds
- Embedding batch (100): < 5 seconds API call

---

## Code Documentation Standards

All classes and methods must include comprehensive PHPDoc blocks.

### Requirements:
1.  **Title & Detailed Description**: Clear explanation of purpose.
2.  **Parameters**: `@param {type} $name Description. Example: {example}`.
3.  **Return Type**: `@return {type} Description. Example: {example}`.
4.  **Exceptions**: `@throws {ExceptionClass} Description of when it's thrown. Example: {example}`.