# Installation (development)

## Prerequisites

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.4+ | Extensions: bcmath, intl, gd, zip, mbstring, pdo_mysql, fileinfo, pcntl (concurrency tests) |
| Composer | 2.x | |
| MySQL | 8.0+ | Tests also run on MySQL, because generated columns, CHECK constraints, row locks and window functions are part of the design |
| Redis | 6+ | Cache, queues and sessions |
| Node.js | 20+ | With npm |
| Poppler | any recent | Recommended. `pdftotext -layout` keeps PDF table columns, so names and quantities stay in their own columns and wrapped names are joined. Without it a simpler PDF reader is used (`brew install poppler` / `apt install poppler-utils`) |
| Tesseract | any recent | Optional. Only needed for OCR of scanned PDFs (`brew install tesseract` / `apt install tesseract-ocr`) |

## 1. Databases

```sql
CREATE DATABASE requisition_analyzer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE requisition_analyzer_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'req_analyzer'@'localhost' IDENTIFIED BY 'change-me';
GRANT ALL PRIVILEGES ON requisition_analyzer.* TO 'req_analyzer'@'localhost';
GRANT ALL PRIVILEGES ON requisition_analyzer_test.* TO 'req_analyzer'@'localhost';
```

## 2. Install

Everything lives in one project folder.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

In `.env`, set:

- `DB_USERNAME` and `DB_PASSWORD`.
- `APP_URL`, if you change the port. Login works on whatever host and port the app is opened on.
- `OCR_DRIVER=null`, if Tesseract is not installed.

Then run:

```bash
php artisan migrate --seed       # roles, permissions and units; also demo data in APP_ENV=local
```

## 3. Run

Run each of these in its own terminal:

```bash
php artisan serve --port=8010                              # app + API at http://localhost:8010
npm run dev                                                # Vite dev server: hot reload for resources/js
php artisan queue:work --queue=documents,exports,default   # document extraction and report exports
php artisan schedule:work                                  # optional: daily integrity check, export cleanup
```

Open <http://localhost:8010>. Laravel serves the React app, and `@vite` in `resources/views/app.blade.php` loads it from the Vite dev server while `npm run dev` is running. The SPA and the API share one origin, so session cookies and CSRF work without any CORS or proxy setup. Without `npm run dev`, run `npm run build` once, and the built assets in `public/build` are used instead.

The API docs UI is served at <http://localhost:8010/docs/api>.

## 4. Tests

```bash
./vendor/bin/pest                         # all backend suites: Unit, Feature, Concurrency
./vendor/bin/pest --testsuite=Concurrency # forks a second process; needs pcntl
npm test                                  # frontend: Vitest + Testing Library (resources/js/**/*.test.tsx)
npm run typecheck
```

The backend suites use the database configured in `phpunit.xml` (`requisition_analyzer_test`). Feature tests run inside transactions. The concurrency tests truncate tables, because a second process must be able to see committed data.

## Useful commands

| Command | Purpose |
|---|---|
| `php artisan integrity:check` | Re-verify every quantity and money invariant from raw rows. Exits 1 on any violation |
| `php artisan exports:prune` | Delete expired export files |
| `php artisan scramble:export --path=docs/openapi.json` | Regenerate the OpenAPI document |
| `./vendor/bin/pint` | Format PHP code |
