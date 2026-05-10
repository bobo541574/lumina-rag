# Setup Guide

## Prerequisites
- PHP 8.2 or higher
- Composer 2.x
- Node.js 20.x and npm 10.x
- PostgreSQL 16 or higher
- pgvector extension
- OpenAI API key

## Step 1: PostgreSQL Setup

### Install PostgreSQL 16
```bash
# Ubuntu/Debian
sudo apt install postgresql-16 postgresql-contrib

# macOS  
brew install postgresql@16
```

### Enable pgvector Extension
```bash
# Install pgvector
git clone https://github.com/pgvector/pgvector.git
cd pgvector
make
sudo make install
```

### Create Database
```sql
-- Connect to PostgreSQL
psql -U postgres

-- Create database
CREATE DATABASE rag_system;

-- Connect to database
\c rag_system;

-- Enable pgvector extension
CREATE EXTENSION vector;

-- Verify
SELECT * FROM pg_extension WHERE extname = 'vector';
```

## Step 2: Project Setup

### Clone and Install
```bash
git clone <repository-url>
cd rag-system

# Backend
composer install
cp .env.example .env
php artisan key:generate

# Frontend
npm install
npm run build
```

### Configure Environment
Edit `.env` file:
```env
APP_NAME=RAGSystem
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rag_system
DB_USERNAME=postgres
DB_PASSWORD=your_password

OPENAI_API_KEY=sk-your-key-here
RAG_EMBEDDING_PROVIDER=openai
RAG_LLM_PROVIDER=openai
RAG_VECTOR_DRIVER=pgsql

QUEUE_CONNECTION=redis
```

### Run Migrations & Seeders
```bash
php artisan migrate
php artisan db:seed
```

### Test Authentication
```bash
# Register a new user
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Test User","email":"test@example.com","password":"password123"}'

# Login
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"test@example.com","password":"password123"}'

# Get authenticated user (use token from login)
curl http://localhost:8000/api/auth/me \
  -H "Authorization: Bearer <your-token>"
```

### Start Development Server
```bash
# Terminal 1 - Laravel backend
php artisan serve

# Terminal 2 - Queue worker
php artisan queue:work

# Terminal 3 - Vite dev server (for frontend hot reload)
npm run dev
```

## Step 3: Verify Installation

### Test Database Connection
```bash
php artisan tinker
> DB::connection()->getPdo();
```

### Test pgvector
```sql
-- In PostgreSQL
SELECT '[1,2,3]'::vector <=> '[1,2,3]'::vector;  -- Should return 0
```

### Test API
```bash
curl http://localhost:8000/api/health
```

## Step 4: Module Activation
All modules are enabled by default in `config/modules.php`. To disable a module:
```php
'modules' => [
    'chat' => ['enabled' => false],
],
```

## Step 5: First Document Upload
1. Visit `http://localhost:8000`
2. Navigate to Documents section
3. Upload a PDF or text document
4. Wait for processing to complete (monitor via `php artisan queue:work` logs)
5. Navigate to Chat section
6. Ask a question about your document

## Production Deployment
See [DEPLOYMENT.md](./docs/DEPLOYMENT.md)

## Troubleshooting

### pgvector not found
```bash
# Verify extension is installed
psql -U postgres -d rag_system -c "SELECT * FROM pg_available_extensions WHERE name = 'vector';"

# If not, reinstall pgvector
```

### Queue jobs not processing
```bash
# Start the queue worker
php artisan queue:work

# Clear stuck jobs
php artisan queue:clear
```

### OpenAI API errors
- Verify API key in .env
- Check API usage limits
- Test with curl: `curl https://api.openai.com/v1/models -H "Authorization: Bearer $OPENAI_API_KEY"`