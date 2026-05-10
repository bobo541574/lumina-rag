# Deployment Guide

## Production Server Requirements
- PHP 8.2+ with required extensions
- PostgreSQL 16 with pgvector
- Nginx/Apache web server
- Supervisor for queue workers
- SSL certificate
- 2GB RAM minimum, 4GB recommended

## Environment Configuration
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=rag_system
DB_USERNAME=app_user
DB_PASSWORD=secure_password

QUEUE_CONNECTION=redis
CACHE_DRIVER=redis
SESSION_DRIVER=redis

OPENAI_API_KEY=sk-...
```

## Deployment Steps
1. Clone repository to server
2. Run `composer install --no-dev`
3. Run `npm install && npm run build`
4. Configure `.env` for production
5. Run `php artisan migrate --force`
6. Run `php artisan storage:link`
7. Configure queue worker in Supervisor
8. Set up SSL with Let's Encrypt
9. Configure Nginx to serve the application

## Supervisor Configuration
```ini
[program:rag-queue]
command=php /path/to/artisan queue:work --sleep=3 --tries=3
user=www-data
numprocs=2
autostart=true
autorestart=true
```

## Backup Strategy
- PostgreSQL: Daily pg_dump
- File storage: Sync to S3 or backup server
- Configuration: Git version controlled