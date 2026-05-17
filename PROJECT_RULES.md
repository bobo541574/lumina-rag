# Project Rules & Standards

## Core Philosophy
This project follows strict module isolation, explicit dependencies, and comprehensive documentation. Every module is self-contained with its own business rules, API contracts, and tests.

## Module Design Principles

### Single Responsibility
Each module handles ONE business domain:
- **ChatModule**: Conversation management and RAG orchestration
- **DocumentModule**: File ingestion and text processing
- **EmbeddingModule**: Text-to-vector conversion
- **LLMModule**: Language model interactions
- **UserModule**: User registration and API token authentication
- **VectorStoreModule**: Vector storage and similarity search
- **SettingsModule**: AI model configuration and term alias registry

### Module Isolation
- Modules communicate ONLY through defined service contracts (interfaces)
- No module can directly access another module's database tables
- Cross-module data access is via service method calls only
- Each module reads its `enabled` flag from `config/modules.php` in its `ServiceProvider::boot()`

### Dependency Flow
```
ChatModule → EmbeddingModule + VectorStoreModule + LLMModule
DocumentModule → EmbeddingModule + VectorStoreModule
UserModule → (standalone, no internal dependencies)
```

### What Belongs in a Module
```
modules/ModuleName/
├── AGENTS.md                ← Module documentation (required)
├── Controllers/             ← Thin HTTP layer
├── Services/                ← Business logic (thick layer)
├── Models/                  ← Module-specific Eloquent models
├── Requests/                ← Form validation rules
├── Routes/                  ← API route definitions
├── Contracts/               ← Interfaces for module services
├── Database/
│   ├── Migrations/          ← Module-specific migrations
│   └── Seeders/             ← Module-specific seeders
└── Tests/                   ← Feature & unit tests
```
Frontend components live in `resources/js/`, not per-module Vue directories.

## PHP Coding Standards

### Type Safety
- `declare(strict_types=1)` on every PHP file
- All methods have explicit return types
- All class properties are typed
- No mixed types without justification
- **Code Documentation**: All classes and methods must include comprehensive PHPDoc (PHP) or JSDoc (TS/JS) blocks with:
    - **Title & Detailed Description**: Clear explanation of purpose.
    - **Parameters**: `@param {type} $name Description. Example: {example}`.
    - **Return Type**: `@return {type} Description. Example: {example}`.
    - **Exceptions**: `@throws {ExceptionClass} Description of when it's thrown. Example: {example}`.

### Dependency Injection
- Constructor injection preferred
- Facades (`Storage::`, `Log::`) are used in practice alongside constructor-injected config — don't refactor one style to the other without a reason
- Service container binding for interface-to-implementation resolution
- Config values injected via constructor where practical

### Error Handling
- Domain-specific exceptions for business logic errors
- External API calls wrapped in try-catch with specific exception types
- Log all errors with structured context (JSON format)
- User-facing messages are generic, detailed errors only in logs

### Response Envelope
- All API responses follow `{ success, message, data, errors }` format
- List endpoints return `{ data, meta }` with pagination metadata (current_page, per_page, total, last_page)

### Performance Rules
- Embedding API calls are cached (MD5 hash of text as cache key)
- Batch processing for embeddings (100 texts per API call)
- Queue all document processing (never in request lifecycle)
- Database indexes on all foreign keys and search columns
- pgvector IVFFlat index for vector similarity search
- Term alias mappings are Redis-cached with 24h TTL (MD5 hash key)

## Vue.js Standards

### Component Organization
```
Vue/
├── components/          ← Reusable UI pieces
├── composables/         ← Reusable logic hooks
├── stores/              ← Pinia state management
├── services/            ← API communication layer
└── types/               ← TypeScript interfaces/enums
```

### Component Rules
- Every component uses `<script setup lang="ts">`
- Props are explicitly typed with `defineProps<T>()`
- No business logic in components (delegate to composables)
- API calls go through service layer, never direct axios calls
- All async operations show loading/error states

### State Management
- Each module has its own Pinia store
- Stores use Setup Function syntax only
- Stores don't access other module stores directly
- Cross-module state sharing via events or props

### API Communication
- Centralized API service per module
- All requests have timeout handling (30s default)
- Automatic retry on network errors (max 3)
- Request/response interceptors for auth tokens

## Database Standards

### PostgreSQL Specific
- Primary key: ULID (not auto-increment integer)
- Timestamps: TIMESTAMPTZ (not timestamp without timezone)
- Soft deletes: deleted_at TIMESTAMPTZ
- Vector columns: vector(1536) with pgvector extension
- Full-text search: tsvector with GIN index where needed
- `term_aliases` table: unique constraint on `(alias, canonical)`

## Foreign Key Constraints

**Note:** Foreign key constraints are not enforced at the database level. All relationships (e.g., between chat_sessions, chat_messages, documents, document_chunks, vector_embeddings) are managed at the application code level for flexibility and scalability.

### Naming Conventions
- Table names: snake_case, plural (chat_sessions)
- Column names: snake_case, descriptive
- Foreign keys: {singular_table}_id
- Indexes: idx_{table}_{column}
- Unique constraints: uq_{table}_{column}

### Migration Rules
- Each module owns its own migrations
- Foreign key constraints NOT enforced at DB level — app-level only
- Down migrations reverse the up migration completely
- Seed data only in seeders, never in migrations

## Testing Standards

### Coverage Requirements
- Services: Minimum 90% coverage
- Controllers: Minimum 80% coverage
- Overall project: Minimum 80% coverage

### Test Organization
```
tests/
├── Feature/          ← HTTP tests (endpoint testing)
└── Unit/             ← Service & model testing
```

### Testing Rules
- Mock all external services (API calls, file system)
- Database tests use transactions for isolation
- Each test method tests ONE behavior
- Test naming: `test_{what}_{expectedOutcome}`
- Arrange-Act-Assert pattern in all tests

## Git Workflow

### Branch Strategy
- `main` - Production-ready code
- `staging` - Pre-production testing
- `feature/*` - New features
- `fix/*` - Bug fixes
- `refactor/*` - Code improvements

### Commit Convention
```
type(module): description

feat(chat): add streaming response support
fix(document): handle corrupted PDF gracefully  
refactor(embedding): optimize batch processing
docs(vector-store): document pgvector index strategy
test(llm): add unit tests for prompt building
```

### Pull Request Requirements
- Linked issue/ticket
- Passing tests
- Code review by at least one team member
- Updated module documentation if API changed