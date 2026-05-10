# API Reference

## Base URL
```
http://localhost:8000/api
```

## Authentication
API token-based auth. Token sent via `Authorization: Bearer` header. Obtain token via register or login endpoints.

## Common Response Format
```json
{
  "success": true|false,
  "message": "Human-readable message",
  "data": { ... },
  "errors": { ... }
}
```

## Endpoints

### Health Check
```
GET /api/health
```

### Auth Module

#### Register
```
POST /api/auth/register
```
**Body**: `{name, email, password}`
**Response**: `{user: {id, name, email}, token}`
**Errors**: 409 (duplicate email), 422 (validation)

#### Login
```
POST /api/auth/login
```
**Body**: `{email, password}`
**Response**: `{user: {id, name, email}, token}`
**Errors**: 401 (invalid credentials), 422 (validation)

#### Logout
```
POST /api/auth/logout
```
**Headers**: `Authorization: Bearer <token>`
**Response**: Success message

#### Get Current User
```
GET /api/auth/me
```
**Headers**: `Authorization: Bearer <token>`
**Response**: `{id, name, email}`
**Errors**: 401 (unauthenticated)

### Chat Module

#### Ask Question
```
POST /api/chat
```
**Body**: `{question, session_id?, stream?, document_filter?}`
**Response**: `{session_id, message: {role, content, sources}}`

#### List Sessions
```
GET /api/chat/sessions?page=1&per_page=20
```

#### Get Session
```
GET /api/chat/sessions/{id}
```

#### Delete Session
```
DELETE /api/chat/sessions/{id}
```

### Document Module

#### Upload Document
```
POST /api/documents
```
**Body**: multipart/form-data `{file, title?}`
**Response**: `{id, title, status, file_size}`

#### List Documents
```
GET /api/documents?page=1&per_page=20&status=completed
```

#### Get Document
```
GET /api/documents/{id}
```

#### Get Document Status
```
GET /api/documents/{id}/status
```

#### Delete Document
```
DELETE /api/documents/{id}
```

## Error Codes
| Code | Meaning |
|------|---------|
| 400 | Bad request |
| 401 | Unauthenticated |
| 404 | Resource not found |
| 409 | Duplicate/conflict |
| 413 | File too large |
| 422 | Validation error |
| 429 | Rate limited |
| 500 | Server error |

## Seed Data

Run `php artisan db:seed` to populate the database with sample data:
- 2 users (admin@lumina.test, test@example.com) with API tokens
- 2 chat sessions with sample messages
- 1 document with 3 chunks
- Vector embeddings for document chunks (requires pgvector)

## Rate Limits
- Chat: 60 requests per minute per user
- Document upload: 10 requests per minute per user
- General: 120 requests per minute per IP
