# Deployment (production)

## Target topology

```
                 HTTPS (443)
 browser ─────────────▶ nginx ──┬── /build/*     → public/build (static, fingerprinted SPA assets)
                                └── everything else → PHP-FPM (public/index.php): SPA shell, /api, /sanctum, /docs, /up
 PHP-FPM ─── MySQL 8 (data) · Redis (cache, queues, sessions) · local disk (private documents, exports)
 Supervisor ─ queue workers (documents, exports, default)
 cron ─────── php artisan schedule:run (every minute)
```

Laravel serves the React app and the API from **one origin**. The session cookie is first-party (SameSite=Lax) and no CORS is needed.

## Server requirements

- PHP 8.4+ FPM with bcmath, intl, gd, zip, mbstring, pdo_mysql, fileinfo, opcache (pcntl is needed only to run the test suite)
- MySQL 8.0+, InnoDB, `utf8mb4`
- Redis 6+ (enable a password)
- nginx and Supervisor
- `poppler-utils` (recommended: reads PDF tables column by column), and `tesseract-ocr` if OCR is wanted. For Bengali OCR, also install the `ben` language data and set `OCR_LANGUAGES=eng+ben`

## Environment (`.env`)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://procurement.example.com
BUSINESS_TIMEZONE=Asia/Dhaka
LOG_STACK=daily
LOG_LEVEL=warning

DB_HOST=… DB_DATABASE=requisition_analyzer DB_USERNAME=req_analyzer DB_PASSWORD=…   # from your secret store

SESSION_DRIVER=redis
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SANCTUM_STATEFUL_DOMAINS=                          # empty: the app's own domain is always trusted for SPA login
SANCTUM_EXPIRATION=43200
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_PASSWORD=…

OCR_DRIVER=tesseract   # or null
SUPER_ADMIN_EMAIL=…    # first deploy only; remove afterwards
SUPER_ADMIN_PASSWORD=… # first deploy only; remove afterwards
```

Secrets never go in the repository. `.env` is git-ignored.

## Build and release

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build                  # type-checks, then writes fingerprinted assets to public/build
php artisan migrate --force
php artisan db:seed --force              # idempotent: roles/permissions/units; Super Admin only if SUPER_ADMIN_* set
php artisan config:cache && php artisan route:cache && php artisan event:cache && php artisan view:cache
php artisan queue:restart                # workers pick up the new code
```

`node_modules` is only needed at build time; it can be removed from the server afterwards.

`db:seed` never runs the demo seeder outside `APP_ENV=local`. It never strips permissions from roles you have customised; it only adds new ones.

## nginx

```nginx
server {
    listen 443 ssl http2;
    server_name procurement.example.com;
    # ssl_certificate …; ssl_certificate_key …;
    root /srv/requisition-analyzer/public;
    index index.php;

    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self' data:; frame-ancestors 'none'" always;

    client_max_body_size 25m;           # ≥ the "max upload MB" setting

    location /build/ { expires 1y; add_header Cache-Control "public, immutable"; try_files $uri =404; }
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_read_timeout 60s;
    }
    location ~ /\.(?!well-known) { deny all; }
}
server { listen 80; server_name procurement.example.com; return 301 https://$host$request_uri; }
```

Uploaded documents and exports live in `storage/app/private`. That directory is never web-served; files are streamed only by authorized controllers.

## Queue workers (Supervisor)

```ini
[program:req-documents]
command=php /srv/requisition-analyzer/artisan queue:work redis --queue=documents --tries=3 --timeout=300 --memory=512 --max-jobs=200
numprocs=2
autostart=true
autorestart=true
stopwaitsecs=330
user=www-data

[program:req-exports]
command=php /srv/requisition-analyzer/artisan queue:work redis --queue=exports,default --tries=2 --timeout=600 --memory=512 --max-jobs=100
numprocs=1
autostart=true
autorestart=true
stopwaitsecs=630
user=www-data
```

Every worker `--timeout` must stay below the queue's `retry_after`, which `config/queue.php` sets to 660 s (`REDIS_QUEUE_RETRY_AFTER`). Otherwise a long-running job would be handed to a second worker while it is still running.

## Scheduler (cron)

```
* * * * * cd /srv/requisition-analyzer && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler runs three jobs:

- `integrity:check` daily at 02:00 Dhaka time. On failure it writes a `critical` log entry; route that to your alerting.
- `exports:prune` hourly.
- `queue:prune-failed` weekly.

## Database hardening

The audit trail must not be editable, even by the application. Grant the runtime user everything except UPDATE and DELETE on `activity_logs`:

```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON requisition_analyzer.* TO 'req_analyzer'@'%';
REVOKE UPDATE, DELETE ON requisition_analyzer.activity_logs FROM 'req_analyzer'@'%';
-- run migrations with a separate, more privileged deploy user
```

MySQL can't revoke a table privilege from a database-level grant. In that case, grant per table, or run migrations as a deploy user and give the runtime user table-level grants.

## Operations

| Concern | Where to look |
|---|---|
| Errors | `storage/logs/laravel-*.log`. API 5xx responses carry a `reference` that matches the log entry |
| Slow queries | `storage/logs/slow-queries-*.log` (threshold `SLOW_QUERY_MS`, default 500 ms) |
| Failed jobs | `php artisan queue:failed`. Failed extractions also show on the requisition's processing log |
| Data integrity | `php artisan integrity:check` (also scheduled) |
| Backups | Daily MySQL dump plus `storage/app/private/requisitions` (original documents). Test restores |
| Health | `GET /up` |
