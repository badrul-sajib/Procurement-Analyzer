# Requisition Analyzer — System Architecture

Status: **Approved baseline v1** (2026-11-05). Changes to business rules are recorded in the Decisions log at the end.

## 1. Existing project analysis

The repository was empty at the start (no code, no git history). This was a greenfield build on the following local toolchain:

| Component | Version | Notes |
|---|---|---|
| PHP | 8.5.5 | bcmath, intl, gd, zip, pdo_mysql and pcntl are present; there is no phpredis extension, so the app uses `predis` |
| Composer | 2.9.5 | |
| MySQL | 9.6.0 (Homebrew service) | Satisfies "MySQL 8.0+". Generated columns, CHECK constraints and window functions are used |
| Redis | Homebrew service | Cache, queue and sessions |
| Node / npm | 25.6 / 11.9 | |
| Tesseract + Poppler | Homebrew | OCR for scanned PDFs (`pdftoppm` → `tesseract`) |

Packages pinned at build time: Laravel 13.x, Sanctum 4.x, spatie/laravel-permission 8.x, maatwebsite/excel 4.x, smalot/pdfparser 2.x, barryvdh/laravel-dompdf 3.x, Pest 5.x.

## 2. System architecture

```
┌────────────────────────┐        HTTPS (same origin, /api/v1)         ┌──────────────────────────────┐
│  React SPA             │ ──────────────────────────────────────────▶ │  Laravel 13 (API + SPA host) │
│  Vite · TS · shadcn/ui │   Sanctum SPA cookie auth + CSRF            │  Controllers (thin)          │
│  TanStack Query/Table  │                                             │  Form Requests · Policies    │
└────────────────────────┘                                             │  Actions (business ops)      │
┌────────────────────────┐   Bearer token (Sanctum PAT)                │  Queries (analyzer/reports)  │
│  Future mobile apps    │ ──────────────────────────────────────────▶ │  API Resources               │
└────────────────────────┘                                             └──────┬──────────┬────────────┘
                                                                              │          │
                                              ┌───────────────────────────────┘          │
                                              ▼                                          ▼
                                    ┌──────────────────┐   jobs: documents, exports   ┌──────────────┐
                                    │  MySQL 8+ (truth)│ ◀──────────────────────────  │ Queue worker │
                                    └──────────────────┘                              │ (Redis)      │
                                    ┌──────────────────┐                              └──────┬───────┘
                                    │ Redis: cache,    │                                     │ OCR engine
                                    │ queues, sessions │                                     ▼ (replaceable)
                                    └──────────────────┘                              tesseract / null
```

- **API-first.** Every business operation is a REST endpoint under `/api/v1`, and the SPA has no server-rendered pages. Mobile clients use the same endpoints with Bearer tokens (`POST /api/v1/auth/tokens`).
- **Web auth is a Sanctum SPA session cookie, not a token in localStorage.** The httpOnly session cookie plus a CSRF token removes the XSS-token-theft risk. Both kinds of client hit the same `auth:sanctum` guard.
- **One project, two layers.** Laravel hosts the API and serves the React SPA (`resources/js`, built by Vite through laravel-vite-plugin) from the same origin: a catch-all web route returns `resources/views/app.blade.php`, and every other URL under `/api`, `/sanctum`, `/docs` and `/up` stays a server route. The API stays independent of the UI: the SPA only calls `/api/v1`, the same contract mobile clients use.

### Backend layering (one direction only: HTTP → Actions/Queries → Models)

| Layer | Location | Responsibility |
|---|---|---|
| Routes | `routes/api.php` | `/api/v1` prefix, rate limiters, auth middleware |
| Controllers | `app/Http/Controllers/Api/V1` | Authorize, call one Action or Query, return a Resource. No business logic |
| Form Requests | `app/Http/Requests` | Input shape and type validation. Business-rule validation that needs locks lives in Actions |
| Policies | `app/Policies` | Permission checks per model. Never check role names |
| Actions | `app/Actions/<Module>` | One class per business operation (`CreatePurchaseOrder`, `ConfirmDelivery`…). Each owns its DB transaction, row locks, audit log entry and cache-version bump |
| Queries | `app/Queries` | Read models: analyzer, fulfilment, vendor balances, dashboard, reports. Single SQL definitions reused by API, exports and tests |
| Models | `app/Models` | Relationships, casts, scopes. No workflow logic |
| Support | `app/Support` | `Money`/`Qty` decimal helpers (bcmath), `DateRange` (Asia/Dhaka presets), `AuditLogger`, `ReferenceNumber` |
| Document processing | `app/DocumentProcessing` | Extractors (txt, Excel, PDF text, OCR), line parser, item matcher, `OcrEngine` interface |

We deliberately skip a repository layer, because Eloquent and the Query classes already isolate persistence and another layer would only add indirection. We also avoid model observers: side effects (audit, cache bump) are explicit in Actions, so nothing happens invisibly.

### Frontend structure

```
resources/js/
  components/ui/        shadcn/ui generated components (never hand-rolled when shadcn has one)
  components/           app-level composites: DataTable, DateRangeFilter, PageHeader, EmptyState, ConfirmDialog
  features/<module>/    api.ts (TanStack Query hooks + types), pages/, components/, schemas.ts (zod)
  lib/                  api client (fetch + CSRF), auth context, permissions (`can()`), formatters (BDT, qty, dates)
  routes.tsx            React Router, lazy-loaded pages, permission-guarded routes
```

Additional frontend dependencies, all of which shadcn patterns require: React Router (routing), @tanstack/react-table (shadcn data table), react-day-picker + date-fns (shadcn date picker), sonner (shadcn toast). Tests use Vitest and Testing Library.

## 3. Module boundaries

| Module | Owns tables | Depends on |
|---|---|---|
| Auth & Users | users, personal_access_tokens, sessions | — |
| Roles & Permissions | spatie tables | Users |
| Master data | units, unit_conversions, item_categories, departments | — |
| Items | items, item_aliases | Master data |
| Requisitions + Document Processing | requisitions, requisition_files, requisition_items, requisition_processing_logs | Items, Master data |
| Analyzer | (read-only) | Requisitions, Purchases, Deliveries |
| Vendors | vendors | — |
| Purchases | purchase_orders, purchase_order_items, purchase_order_item_requisitions | Requisitions (approved lines), Vendors, Items |
| Deliveries | deliveries, delivery_items | Purchases |
| Payments | payments, payment_allocations | Vendors, Purchases |
| Dashboard & Reports | report_exports | all (read-only) |
| Activity Logs | activity_logs | all (write-only through `AuditLogger`) |
| Settings | system_settings | — |

A module writes only its own tables. Cross-module effects go through the owning module's Action. For example, purchasing never updates requisition rows; requisition fulfilment is always derived from purchase rows.

## 4. Core business rules (authoritative)

### 4.1 Requisition lifecycle

```
           upload                job ok              verify                approve
 (file) ──────────▶ uploaded ─▶ processing ─▶ extracted ─────────▶ verified ─────────▶ approved
                                    │  ▲          ▲  │                 │
                               job  │  │ reprocess│  │ edit lines      │ reopen (if not approved)
                              fails ▼  │          │  ▼                 ▼
                                   failed     (stays extracted)     extracted
 manual create ─▶ draft ─(verify)─▶ verified
 any state before purchases exist ──cancel──▶ cancelled
```

- Extraction is never auto-approved. Lines are editable only in `draft`, `extracted` and `failed`, and verifying requires that **every line**:
  - is matched to a catalog item,
  - has a quantity greater than 0,
  - has a unit that converts to the item's default unit.
- `requisition_date` is required to verify.
- **Verified** requisitions count toward *Requested* quantities in the analyzer. They cannot be purchased yet.
- **Approve** (a separate permission) sets `approved_quantity` on each line. It defaults to the requested quantity, and the approver may lower it (0 rejects the line). **Only approved quantity can be purchased.**
- Approved requisitions are locked. Cancelling one is allowed only when none of its lines are on a non-cancelled purchase order.
- **Duplicate import prevention.** The SHA-256 of the uploaded file is unique, so the same file can never create two requisitions. A matching `external_reference` shows a duplicate warning on the review screen. It is not a hard block, because different departments may reuse numbers.
- **One requisition per uploaded file.** A multi-file upload creates N independent requisitions.

### 4.2 Units

- Every item has one **default unit**, which is its consolidation unit. When a requisition is verified, each line freezes its `conversion_factor` to that unit; `base_quantity` is a stored generated column (`quantity × factor`). Later edits to conversion rules therefore never rewrite history.
- A conversion is resolved as item-specific `from → default` first, then global `from → default`. There is no chaining and no implicit inverse.
- **5 Boxes + 10 Pieces are never added without a defined conversion:** a line without one cannot be verified.
- An item's default unit cannot change once verified lines reference it.
- Purchasing and delivery quantities are expressed in the item's default unit (v1 limitation, see §8).

### 4.3 Item matching

- Names are normalized: lowercase, non-alphanumerics → space, whitespace collapsed.
- An exact normalized match on an item name or alias **auto-links** the line (`match_type = exact|alias`). It is still shown for review, and the user verifies the whole requisition.
- Anything else stays **unmatched** and is shown with up to 5 similarity suggestions. The user picks an item (optionally saving the text as an alias) or creates a new item. Uncertain matches are never merged automatically.
- The original extracted name and unit text are preserved on every line.

### 4.4 Purchase orders

```
draft ──submit──▶ pending_approval ──approve──▶ approved ──mark purchased──▶ purchased | partially_purchased ──complete──▶ completed
  │  (if approval disabled, submit ─────────────▶ approved)                          │
  └──────────── cancel (draft / pending_approval / approved) ────────────▶ cancelled ◀┘ cancel only if no confirmed
                                                                                        deliveries and no active payment allocations
```

- A PO line belongs to one item and is backed by one or more **requisition-line allocations** (`purchase_order_item_requisitions`). One line can serve many requisitions, and one requisition line can be served by many POs and vendors.
- When the user doesn't pick specific requisitions, allocation is FIFO by requisition date and then line id.
- Quantity partition for every approved requisition line, maintained without any stored counter:
  - `approved = purchased + on_order + pending`
  - `on_order` = Σ allocation `ordered_quantity` on POs in draft/pending_approval/approved
  - `purchased` = Σ allocation `purchased_quantity` on POs in purchased/partially_purchased/completed
  - `pending` = approved − purchased − on_order. This is exactly the amount available to order.
- Creating or editing a PO **locks the affected requisition lines (`SELECT … FOR UPDATE`, in id order)**, recomputes `pending` under the lock, and rejects anything that exceeds it. This prevents concurrent over-purchasing.
- **Mark Purchased.** The user records the actual purchase date and the actual quantity per line (≤ ordered), and may correct price, discount and tax to match the vendor bill.
  - If every line is bought in full, the status becomes `purchased`. Any shortfall makes it `partially_purchased`.
  - A shortfall is released from the **most recently requested allocations first**, so the oldest requests stay fulfilled. Released quantity returns to `pending`.
- After that the PO is financially locked. Its payable is `Σ line_total + additional_charges`, where `line_total` is a stored generated column: round(qty × unit_price, 2) − discount + tax.
- **Complete** closes the PO, and is allowed only when every purchased quantity has been delivered.
- Cancelled POs never count toward purchased quantities or payables.

### 4.5 Deliveries

- A delivery belongs to one PO in purchased, partially_purchased or completed state, and its lines reference PO lines. Status flow: `pending → confirmed → reversed`.
- Only **confirmed** deliveries count. At confirm, the PO lines are locked, and Σ confirmed delivered + this delivery must be ≤ purchased_quantity.
- Reversal is a status change carrying `reversed_by`, `reversed_at`, `reversal_reason` and an audit entry. Nothing is deleted.
- Delivered quantity is attributed to requisition lines **FIFO within each PO line**, computed with SQL window functions at read time. No stored attribution exists.
- Delivery status is derived: Not Delivered / Partially Delivered / Fully Delivered.

### 4.6 Payments

- A payment belongs to one vendor and can be allocated across that vendor's payable POs, either at creation or later.
- Constraints, checked under row locks on the payment and the POs:
  - Σ active allocations of a payment ≤ payment amount.
  - Σ allocations to a PO (from active payments) ≤ PO payable.
  - An allocation's PO must belong to the same vendor and be payable.
- Formulas:
  - Vendor **outstanding** = Σ payable PO totals − Σ allocations from active payments.
  - Vendor **unallocated credit** = Σ active payments − their allocations.
  - PO payment status (derived): Unpaid / Partially Paid / Paid.
- Reversing a payment sets `status = reversed`, keeping who, when and why plus an audit entry. Its allocations stop counting automatically.
- Money is `DECIMAL(15,2)` (unit price `DECIMAL(15,4)`) and is computed in SQL or with bcmath strings. Never floats.
- Sensitive POST operations accept an `Idempotency-Key` header: payments, allocations, reversals, PO creation and deliveries.

### 4.7 Dates and time zone

- Timestamps are stored in UTC. **Business dates are `DATE` columns holding the Asia/Dhaka calendar date:** requisition_date, order_date, purchase_date, delivery_date, payment_date.
- Presets (Today … This Year) are resolved in the business time zone. Week start is configurable and defaults to **Saturday**.
- Each report uses its event date:

  | Report | Date used |
  |---|---|
  | Requisition | requisition_date, or upload time (selectable) |
  | Purchase | purchase_date |
  | Delivery | delivery_date |
  | Payment | payment_date |

- The upload-time filter converts Dhaka day boundaries to a UTC range.

## 5. Roles & permission matrix

Business logic checks **permissions only**. Roles are seed defaults and can be edited in the UI. Super Admin bypasses through `Gate::before`, and only a Super Admin can assign the Super Admin role.

| Permission | Admin | Req. Officer | Purchase Mgr | Inventory Mgr | Receiving Officer | Finance Mgr | Viewer |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| dashboard.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| analyzer.view | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ |
| requisitions.view | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ |
| requisitions.upload | ✓ | ✓ | | | | | |
| requisitions.update | ✓ | ✓ | | | | | |
| requisitions.verify | ✓ | ✓ | | | | | |
| requisitions.approve | ✓ | | ✓ | | | | |
| requisitions.cancel | ✓ | ✓ | ✓ | | | | |
| items.view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| items.manage | ✓ | ✓ | | ✓ | | | |
| master_data.manage (units, categories, departments, conversions) | ✓ | | | ✓ | | | |
| vendors.view | ✓ | | ✓ | ✓ | ✓ | ✓ | ✓ |
| vendors.manage | ✓ | | ✓ | | | ✓ | |
| purchases.view | ✓ | | ✓ | ✓ | ✓ | ✓ | ✓ |
| purchases.create | ✓ | | ✓ | | | | |
| purchases.update (edit draft, mark purchased, complete) | ✓ | | ✓ | | | | |
| purchases.approve | ✓ | | ✓ | | | | |
| purchases.cancel | ✓ | | ✓ | | | | |
| deliveries.view | ✓ | | ✓ | ✓ | ✓ | ✓ | ✓ |
| deliveries.create | ✓ | | | ✓ | ✓ | | |
| deliveries.confirm | ✓ | | | ✓ | ✓ | | |
| deliveries.reverse | ✓ | | | ✓ | | | |
| finance.view (vendor balances, PO amounts paid/due) | ✓ | | ✓ | | | ✓ | |
| payments.view | ✓ | | | | | ✓ | |
| payments.create | ✓ | | | | | ✓ | |
| payments.allocate | ✓ | | | | | ✓ | |
| payments.reverse | ✓ | | | | | ✓ | |
| reports.view | ✓ | ✓ | ✓ | ✓ | | ✓ | ✓ |
| reports.financial | ✓ | | ✓ | | | ✓ | |
| reports.export | ✓ | ✓ | ✓ | ✓ | | ✓ | |
| users.manage | ✓ | | | | | | |
| roles.manage | | | | | | | |
| activity_logs.view | ✓ | | | | | | |
| settings.manage | ✓ | | | | | | |

`roles.manage` is held only by Super Admin, through the bypass.

## 6. Security threat assessment

| Threat | Mitigation |
|---|---|
| Credential stuffing / brute force | Login throttled per email+IP (5/min). Bcrypt hashing (Laravel default cost). Inactive users rejected. Generic error messages |
| Session/token theft | SPA uses an httpOnly, SameSite=Lax, Secure (prod) session cookie with CSRF. Mobile tokens are hashed at rest (Sanctum), revocable and expire per `SANCTUM_EXPIRATION` |
| Privilege escalation / IDOR | Every route resolves a Policy or permission middleware. IDs are never trusted for ownership: exports and document downloads check the record. Only Super Admin can grant Super Admin |
| Mass assignment | Explicit `$fillable`. Controllers pass `$request->validated()` only. `Model::shouldBeStrict()` outside production |
| SQL injection | Eloquent/Query Builder bindings only. Sort and filter columns are whitelisted |
| XSS | React escapes all output and no `dangerouslySetInnerHTML` is used. Extracted text is stored as plain text and rendered as text. API is JSON-only (`nosniff`) |
| Malicious uploads | Extension and **finfo MIME** whitelist (pdf, xlsx, xls, txt). Size limit (setting, default 10 MB). Stored on a private disk under random UUID names, never web-served, downloaded only through an authorized controller with `Content-Disposition: attachment`. Excel formulas are not calculated. Parsing runs in a queue worker with a timeout and memory limit. OCR runs via `Process` with argument arrays (no shell interpolation) |
| CSV/Excel formula injection in exports | Cells starting with `= + - @` (and tab/CR) are prefixed with `'` |
| Financial double-submission | `Idempotency-Key` middleware with a Redis lock and response replay for 24h. Row locks inside transactions |
| Race conditions (over-purchase, over-delivery, over-allocation) | `SELECT … FOR UPDATE` on the constrained rows, with the cap recomputed under the lock. Deterministic lock order |
| Audit tampering | `activity_logs` has no update/delete endpoints, and the model throws on update/delete. The production DB user should be denied UPDATE/DELETE on that table (deployment doc) |
| Information leakage | `APP_DEBUG=false` in production. Exceptions are rendered as generic JSON with a correlation id. Passwords and tokens are masked in audit payloads |
| Transport | HTTPS enforced (`URL::forceScheme`), HSTS via nginx, secure cookies |

## 7. Performance & scalability

- Every list endpoint uses **server-side pagination, sorting and filtering**. Resources eager-load (`with`) and `shouldBeStrict()` makes N+1 queries fail in dev/tests.
- Aggregations run in SQL (GROUP BY and window functions), never by loading rows into PHP. Exports use `FromQuery` with chunking; Excel imports use chunk reading.
- Composite indexes back each common filter (see database.md).
- **Caching:**
  - Dashboard and analyzer summaries are cached in Redis under a key that includes a global **data version**. Every write Action bumps the version after commit, so a cached figure can never outlive the data it was computed from. TTL is 10 minutes.
  - Vendor balances and PO amounts are **not cached**. They are computed from transactions on every request.
- Document processing and exports run on dedicated queues (`documents`, `exports`) so large files never block requests.
- Queries slower than 500 ms are logged to a `slow-queries` channel.

## 8. Risks & recommended solutions

| Risk | Impact | Mitigation / plan |
|---|---|---|
| Extraction accuracy varies widely between document layouts | Wrong quantities if trusted blindly | Mandatory human review before verify. Parser heuristics tested against fixtures. Processing log shows which extractor ran and skipped lines |
| OCR quality on low-resolution scans | Missing lines | 300 DPI rasterization. OCR text goes through the same review flow. `OcrEngine` interface lets a cloud OCR provider be dropped in |
| Requisition units differ from item default unit with no conversion | Cannot verify | Inline "add conversion" from the review screen (master_data.manage) |
| v1 purchases and deliveries only in the item's default unit | Users buying "boxes" must enter pieces | Documented limitation. Upgrade path: add `unit_id` + `conversion_factor` to PO lines as already done for requisition lines |
| FIFO delivery attribution computed at read time | Analyzer query cost grows with history | Indexed window-function query scoped to the filtered requisition lines. If needed, a materialized attribution table populated on confirm/reverse |
| Reversals are status changes (not negative entries) | A report for a past period changes after a reversal | Matches "cancelled transactions must not contribute to active totals". Reversal date and actor are kept and shown in reports |
| Payment can be allocated only to POs, there is no separate invoice entity | — | Spec allows "Purchase Order or allocated purchase invoices". Invoice entity deferred |
| Segregation of duties (PO creator approving own PO) | Control weakness | Not enforced (not in spec). Recommended as a setting; ask before adding |
| Unallocated payment fixes require reversing the whole payment | Extra steps for finance | Single-allocation reversal deferred; ask if needed |

## 9. Decisions log

| # | Date | Decision | Source |
|---|---|---|---|
| D1 | 2026-11-05 | Verify → Approve two-step; approver may lower per-line approved quantity; only approved quantity is purchasable | User choice |
| D2 | 2026-11-05 | Quantities count as purchased (and payable) at "Mark Purchased" with actual quantities; shortfall → partially purchased, released to pending | User choice |
| D3 | 2026-11-05 | One requisition per uploaded file | User choice |
| D4 | 2026-11-05 | Redis, Tesseract and Poppler installed locally via Homebrew | User choice |
| D5 | 2026-11-05 | SPA cookie auth for web, PAT bearer tokens for mobile | Architecture |
| D6 | 2026-11-05 | No `vendor_items` table: vendor item list and price history are derived from PO lines (avoids a second source of truth) | Architecture |
| D7 | 2026-11-05 | Reversals = status change + reversal metadata + audit entry | Architecture |
| D8 | 2026-11-05 | Week starts Saturday (configurable); single currency BDT (৳) | Assumption — confirm |
| D9 | 2026-11-05 | Analyzer shows Approved and On-order columns in addition to spec columns, so that approved = purchased + on order + pending is visible | Architecture |
| D10 | 2026-11-05 | Vendor payable = purchased amount of the PO (not delivered value) | Assumption, matches spec example |
| D11 | 2026-11-05 | Capacity-checking transactions (PO save, mark purchased, delivery confirm, payment allocation) run at READ COMMITTED after taking row locks. Under REPEATABLE READ a waiting transaction kept a stale snapshot and over-purchased; proven by `tests/Concurrency` | Implementation finding |
| D12 | 2026-11-05 | Permission middleware runs before route-model binding, so unauthorized callers get 403 for every id (no existence probing) | Security |
| D13 | 2026-11-05 | Vendor purchases and payments are exposed as filtered collections (`?vendor_id=`), not nested duplicates | API design |
| D14 | 2026-11-05 | Excel extraction reads each formula's cached value and never evaluates formulas (DoS and safety risk). A file saved without cached values yields empty quantities for review | Security |
| D16 | 2026-11-05 | Single project: React lives in `resources/js` of the Laravel app instead of separate `backend/` and `frontend/` folders. One install, one origin, one deployment. API-first is unchanged | User choice |
| D15 | 2026-11-05 | Dashboard and analyzer summaries are cached per data version (bumped after commit by every write); vendor balances and PO amounts are never cached | Performance/consistency |
