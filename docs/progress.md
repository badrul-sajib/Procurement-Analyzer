# Progress Tracker

Legend: ✅ done and tested · 🟡 partial · ⬜ not started

## Roadmap

| Phase | Scope | Status |
|---|---|---|
| 0 | Repository inspection, architecture, ERD, schema, API design, permission matrix, threat assessment | ✅ |
| 1 | Foundation: Laravel 13 + React scaffolds, MySQL/Redis, Sanctum (SPA cookie + mobile tokens), RBAC seed, audit logger, idempotency, settings, app shell | ✅ |
| 2 | Master data: units, conversions, categories, departments, items + aliases, vendors | ✅ |
| 3 | Requisitions & document processing: upload, SHA-256 dedupe, queued extraction (TXT, Excel, PDF text, Tesseract OCR), review & matching UI, verify/approve/reopen/cancel/reprocess | ✅ |
| 4 | Analyzer: fulfilment query (FIFO delivery attribution), consolidated table, source drill-down, summary | ✅ |
| 5 | Purchase orders: creation from pending, FIFO allocation, row locks + READ COMMITTED, approval setting, mark purchased (actuals, shortfall release), complete, cancel | ✅ |
| 6 | Deliveries: record, confirm (re-checked under lock), reverse | ✅ |
| 7 | Payments: record, allocate across POs, reverse, vendor balances, price history, vendor detail tabs | ✅ |
| 8 | Dashboard (cards, 8 charts, recent activity), 10 date-wise reports, queued XLSX/CSV/PDF exports | ✅ |
| 9 | Users, roles & permissions matrix, activity log, integrity check + scheduler, OpenAPI export, installation & deployment docs | ✅ |

## Test results (2026-11-05)

| Suite | Result |
|---|---|
| Backend (Pest): Unit + Feature + Concurrency | **155 tests, 1,066 assertions, all passing** (MySQL 9.6, Redis) |
| Concurrency (forked processes) | PO over-purchase and payment over-allocation both blocked. Mutation check: disabling the READ COMMITTED fix makes the PO test fail |
| OCR integration | Real Tesseract + Poppler read a genuinely image-only PDF |
| Frontend (Vitest + Testing Library) | **10 tests passing**; `tsc` clean; production build OK |
| End-to-end through the real stack (single origin, session + CSRF, Redis queue worker; re-run after merging into one folder) | 20/20 checks. Lifecycle: upload, extract, verify, approve (reduced qty), PO, over-purchase refused, approve, mark purchased, deliver, pay, balance 0, export, owner-only download |
| `php artisan integrity:check` on seeded + E2E data | All 6 invariants OK |

Coverage against the spec's test list:

| Area | Covered by |
|---|---|
| Requisition | Valid, invalid and duplicate upload; extraction (TXT, Excel incl. formulas, PDF, scanned/OCR); failed extraction; manual correction; verify/approve; consolidation; unit conversion |
| Purchase | Create, partial, full, over-purchase, multiple vendors, concurrent, cancel, idempotency |
| Delivery | Partial, full, over-delivery (including open receipts), reversal, completion rules |
| Payment | Full, partial, multiple allocations, over-allocation (3 ways), reversal, outstanding (spec example table), credit, idempotency, concurrent |
| Security | Unauthenticated access, role matrix, route-permission guard over every route, IDOR (exports, foreign line ids, 403-before-404), invalid files (PHP, HTML, zip disguised), mass assignment, permission separation for financial actions |
| Reporting | Date-wise filtering on each event date, aggregation, purchase/delivery/payment totals, as-of outstanding, export output incl. formula-injection escaping |

## Known limitations

| Limitation | Notes / upgrade path |
|---|---|
| UI not visually reviewed in a browser | No browser tooling was available in this session. Pages are type-checked, component-tested and exercised through the API end-to-end, but layout and responsiveness still need a manual pass |
| Purchases and deliveries use each item's default unit | Users buying "boxes" enter pieces. Upgrade: add `unit_id` + `conversion_factor` to PO lines (same pattern as requisition lines) |
| Excel formulas | Only the value cached in the file is read; formulas are never evaluated. Files saved without cached values give empty quantities for the reviewer to fill |
| OCR language | Only English data is installed. Add `tesseract-lang`/`ben` and `OCR_LANGUAGES=eng+ben` for Bengali documents |
| Payment fixes | A single allocation can't be reversed on its own: reverse the payment and re-record it |
| Completed POs | Deliveries on a completed PO can't be reversed (the PO is closed) |
| Reversals are status changes | A report for a past period changes after a reversal. Reversal date and actor are kept |
| No invoice entity | Payments are allocated to purchase orders directly |
| Segregation of duties | A user with both permissions can create and approve the same PO. Not in the spec; could be a setting |
| Item suggestions | LIKE prefilter + similarity ranking. Fine for ~10k items; use a FULLTEXT/ngram index beyond that (`shortcut:` comment in `ItemMatcher`) |
| Line validation | One `exists` query per line on save (`shortcut:` comment). Fine below ~500 lines per requisition |
| PDF exports | Capped at 5,000 rows; Excel/CSV have no cap |
| Bundle size | Main JS chunk 569 KB (173 KB gzip); pages and charts are lazy-loaded |
| Localization | UI is English only; dates and money use Bangladesh conventions (৳, lakh grouping, day-first dates) |
| Assumptions to confirm | Week starts Saturday (setting); single currency BDT; payable = purchased amount (D8, D10) |
