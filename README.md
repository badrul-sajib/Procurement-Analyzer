# Requisition Analyzer

Requisition Analyzer is a requisition and procurement tracking platform. Departments upload requisition documents (PDF, Excel or text). The system extracts each line item, a person verifies and approves it, and identical items are consolidated across requisitions. Approved quantities are then followed through purchase orders, deliveries and vendor payments, with date-wise reports for each stage.

```
Upload → Extraction → Human review → Verify → Approve → Analyzer (consolidated per item)
       → Purchase orders (partial, multi-vendor) → Deliveries (partial) → Vendor payments → Reports & exports
```

| | |
|---|---|
| Backend | Laravel 13 (PHP 8.4+), MySQL 8+, Redis, Sanctum, spatie/laravel-permission, Laravel Excel, smalot/pdfparser, Tesseract OCR |
| Frontend | React 19 + TypeScript, Vite, Tailwind CSS 4, shadcn/ui, TanStack Query/Table, React Hook Form + Zod, Recharts |
| API | REST under `/api/v1`, OpenAPI 3.1 in [`docs/openapi.json`](docs/openapi.json) (live at `/docs/api`) |

## Repository layout

A single Laravel project. The React SPA lives in `resources/js` and is built with Vite (laravel-vite-plugin); Laravel serves it alongside the API.

```
app/              Laravel backend: Actions (business operations), Queries (read models), Reports (date-wise reports)
resources/js/     React SPA: features/<module>, components/ui (shadcn), lib (API client, auth, formatting)
resources/css/    Tailwind / shadcn theme
resources/views/  app.blade.php (SPA entry) and PDF export templates
routes/           api.php (/api/v1), web.php (SPA catch-all)
tests/            Pest: Unit, Feature, Concurrency (frontend tests sit next to components as *.test.tsx)
docs/             architecture, database (ERD), API, installation, deployment, progress
```

## Quick start (local)

Full instructions, including prerequisites, are in [docs/installation.md](docs/installation.md).

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_* in .env, create the database, then:
php artisan migrate --seed

# three terminals
php artisan serve --port=8010                              # app + API: http://localhost:8010
npm run dev                                                # Vite (hot reload for the React app)
php artisan queue:work --queue=documents,exports,default   # extraction and exports run here
```

Local seed users all use the password `password`. Production never creates demo data.

| User | Role |
|---|---|
| admin@example.com | Super Admin |
| officer@example.com | Requisition Officer |
| purchase@example.com | Purchase Manager |
| store@example.com | Inventory Manager |
| receiving@example.com | Delivery / Receiving Officer |
| finance@example.com | Finance Manager |
| viewer@example.com | Viewer |

## Tests

```bash
./vendor/bin/pest    # backend, requires the MySQL test database (see installation.md)
npm test             # frontend (Vitest)
```

## Documentation

- [Architecture](docs/architecture.md): system design, business rules, permission matrix, threat model, risks, decisions
- [Database](docs/database.md): ERD, tables, indexes, and how every derived quantity is computed
- [API](docs/api.md): conventions and endpoint list; the machine-readable spec is [openapi.json](docs/openapi.json)
- [Installation](docs/installation.md) · [Deployment](docs/deployment.md) · [Progress & known limitations](docs/progress.md)
