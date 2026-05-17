# User Module

## Overview
Handles user registration, authentication, and profile management. Provides API token-based authentication for the RAG system.

## API Contract

### POST /api/auth/register
Register a new user account.

**Request Body**:
```json
{
  "name": "string (required, max:255)",
  "email": "string (required, email, unique)",
  "password": "string (required, min:8)"
}
```

**Success Response**:
```json
{
  "success": true,
  "message": "User registered successfully.",
  "data": {
    "user": { "id": "01JAR9XK4ZK3XK4ZK3XK4ZK3XK", "name": "...", "email": "..." },
    "token": "..."
  }
}
```

### POST /api/auth/login
Authenticate and receive API token.

**Request Body**:
```json
{
  "email": "string (required, email)",
  "password": "string (required)"
}
```

**Success Response**:
```json
{
  "success": true,
  "data": {
    "user": { "id": "01JAR9XK4ZK3XK4ZK3XK4ZK3XK", "name": "...", "email": "..." },
    "token": "..."
  }
}
```

### POST /api/auth/logout
Invalidate current API token.

### GET /api/auth/me
Get authenticated user profile.

## Business Rules
- Passwords hashed with Bcrypt
- Tokens are 80-character random strings
- Login fails after 5 attempts (throttle)
- Email must be unique

## Seeder
`UserModuleSeeder` — creates 2 users (admin@lumina.test, test@example.com) with API tokens. Idempotent (skips if users exist). Called automatically by `DatabaseSeeder`.

---

### API Endpoints

---

## Code Documentation Standards

All classes and methods must include comprehensive PHPDoc blocks.

### Requirements:
1.  **Title & Detailed Description**: Clear explanation of purpose.
2.  **Parameters**: `@param {type} $name Description. Example: {example}`.
3.  **Return Type**: `@return {type} Description. Example: {example}`.
4.  **Exceptions**: `@throws {ExceptionClass} Description of when it's thrown. Example: {example}`.

---

## Security Considerations
