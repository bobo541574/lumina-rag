# Security

Security controls implemented in lumina-rag, mapped to ISO/IEC 27001, 27002, 27034, and 27701.

## Authentication & Access Control (27002:5.15, 5.17, 8.2, 8.3, 8.5)

- **Bearer tokens are stored one-way hashed.** Raw 80-char hex tokens are never
  persisted; the database holds only a SHA-256 digest (`app/Support/ApiToken.php`,
  `AuthService`). A database leak does not expose usable credentials.
- **Token expiry and sliding renewal.** Tokens issued via `register`/`login` carry
  an `api_token_expires_at` (default 30 days, `RAG_API_TOKEN_TTL_DAYS`). The
  `AuthenticateWithToken` middleware rejects expired tokens and renews them on a
  sliding window. Seeded/legacy tokens without an expiry remain valid for
  back-compat.
- **Admin role gate.** Settings endpoints (`/api/settings/*`) require
  `auth.token` + `auth.admin` (`EnsureAdmin`). Non-admin users receive 403.
- **Provider credentials masked.** `ai_models.api_key` is excluded from API
  responses (only a `has_api_key` boolean is exposed) and encrypted at rest
  (27002:8.24).

## Tenant Isolation (27001 A.5.10, 27701 PII + 27002:8.3)

- Retrieval is scoped to the authenticated user's own documents by default
  (`RAG_TENANT_ISOLATION=true`).
- Enforcement is layered: `ChatController` whitelists the client `document_filter`
  (no `user_ids`, document_ids pruned to owned docs), and `RAGPipelineService`
  re-scopes `user_ids`/`document_ids` as a backstop at search time.

## Rate Limiting (27002:8.6)

- Chat: `throttle` uses `RAG_CHAT_MAX_REQUESTS_PER_MINUTE` (default 20/min).
- Auth: register/login throttled to 5/min (anti-brute-force / anti-enumeration).

## Error Handling & Information Dieclosure (27002:8.28)

- Public endpoints return generic messages; exception details go to logs only.
- Register duplicate-email returns a generic message (no account enumeration).
- `.env.example` defaults to `APP_DEBUG=false` and `LOG_LEVEL=error`.

## Audit Logging (27002:5.15, 8.15, 8.16)

- Sensitive events (register, login, logout, failed login, ai-model create/update/
  delete, account deletion) are written to the dedicated `security` channel
  (daily rotation, 90-day retention).

## Privacy & Erasure (27701:7.2.5, 8.2.4 / 27002:8.10)

- `DELETE /api/auth/me` (erasure) removes documents, chunks, vectors, chat
  messages/sessions, and semantic-cache entries before deleting the account
  (`AccountDeletionService`).

## Injection & Stored XSS (27034:7.4, 8.1)

- Vector metadata filter keys are allow-listed
  (`PgvectorDriver::ALLOWED_META_KEYS`) so client keys are never interpolated
  into raw SQL.
- Stored `description` is rendered as text (`{{ }}`), removing `v-html` usage.

## Secret Handling & Cryptography (27002:8.24)

- Gemini API key sent via `x-goog-api-key` header, never in the URL.
- `OPENAI_API_KEY`, `GEMINI_API_KEY`, etc. are env-only and gitignored.