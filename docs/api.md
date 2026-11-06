# REST API — v1

The base URL is `/api/v1`, and every endpoint accepts and returns JSON only. The machine-readable OpenAPI 3.1 spec is generated from the code (routes, Form Requests and Resources). It is served at `/docs/api`, which requires login outside `local`, and is exported to `docs/openapi.json`.

## Conventions

| Concern | Rule |
|---|---|
| Auth (web SPA) | `GET /sanctum/csrf-cookie`, then `POST /auth/login` (session cookie). Send `X-XSRF-TOKEN` on writes |
| Auth (mobile) | `POST /auth/tokens` with `{email, password, device_name}` returns `{token}`. Send `Authorization: Bearer <token>` |
| Success, single | `200/201 { "data": {...} }` |
| Success, list | `200 { "data": [...], "meta": {current_page, per_page, total, last_page}, "links": {...} }` |
| Validation error | `422 { "message": "...", "errors": { "field": ["..."] } }` |
| Business-rule error | `422 { "message": "...", "code": "over_purchase", "errors": {...} }`, with a stable `code` the client can switch on |
| Other errors | `401` unauthenticated, `403` forbidden, `404`, `409` idempotency conflict, `429` throttled, `500 { "message": "Server error", "reference": "<uuid>" }` |
| Pagination | `?page=1&per_page=25` (max 100) |
| Sorting | `?sort=-requisition_date`. Only whitelisted fields; `-` means descending |
| Search / filters | `?search=…&status=…&vendor_id=…` (validated, whitelisted) |
| Dates | `?period=today\|yesterday\|this_week\|last_week\|this_month\|last_month\|this_year\|custom&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`, resolved in the business time zone (Asia/Dhaka) |
| Money & quantities | Serialized as **decimal strings** (`"1250.50"`) to avoid float rounding on clients |
| Idempotency | `Idempotency-Key: <uuid>` header (optional, recommended) on financial POSTs. A replay returns the original response; a reused key with a different body returns 409 |
| Rate limits | login 5/min (email+IP); API 120/min per user; uploads 20/min; exports 10/min |

## Endpoints

Permission names refer to the matrix in `architecture.md` §5.

### Auth
| Method | Path | Notes |
|---|---|---|
| POST | /auth/login | SPA session login |
| POST | /auth/logout | |
| GET | /auth/me | user, roles, permissions[] |
| POST | /auth/tokens | mobile token issue |
| DELETE | /auth/tokens/current | revoke the calling token |

### Dashboard (`dashboard.view`; financial cards need `finance.view`)
| Method | Path |
|---|---|
| GET | /dashboard/summary?period… |
| GET | /dashboard/charts?period… |
| GET | /dashboard/recent-activities |

### Requisitions
| Method | Path | Permission |
|---|---|---|
| GET | /requisitions | requisitions.view |
| POST | /requisitions | requisitions.upload (manual entry, status draft) |
| POST | /requisitions/uploads | requisitions.upload (multipart `files[]`, 1–20 files; creates one requisition per file) |
| GET | /requisitions/{id} | requisitions.view (lines, fulfilment, processing logs, duplicate warnings) |
| PUT | /requisitions/{id} | requisitions.update (header and lines; only in draft/extracted/failed) |
| POST | /requisitions/{id}/process | requisitions.update (re-run extraction; failed/extracted only) |
| POST | /requisitions/{id}/verify | requisitions.verify |
| POST | /requisitions/{id}/reopen | requisitions.update (verified → extracted) |
| POST | /requisitions/{id}/approve | requisitions.approve (`lines[{id, approved_quantity}]`) |
| POST | /requisitions/{id}/cancel | requisitions.cancel (`reason`) |
| GET | /requisitions/{id}/document | requisitions.view (original file download) |
| GET | /requisition-items/{id}/match-suggestions | requisitions.update |

### Analyzer (`analyzer.view`)
| Method | Path |
|---|---|
| GET | /analyzer/summary |
| GET | /analyzer/items (paginated consolidated rows) |
| GET | /analyzer/items/{itemId}/requisitions (source lines) |
| GET | /analyzer/status-summary |

Shared filters: `period/date_from/date_to, requisition_id, item_id, category_id, department_id, status (verified|approved), search`.

### Items & master data
| Method | Path | Permission |
|---|---|---|
| GET, POST | /items | items.view / items.manage |
| GET, PUT | /items/{id} | items.view / items.manage (includes aliases and conversions) |
| GET | /items/{id}/purchase-history | purchases.view (vendor prices, latest first) |
| POST | /items/{id}/aliases | items.manage |
| DELETE | /items/{id}/aliases/{aliasId} | items.manage |
| GET, POST, PUT | /units, /units/{id} | items.view / master_data.manage |
| GET, POST, PUT, DELETE | /unit-conversions, /unit-conversions/{id} | items.view / master_data.manage |
| GET, POST, PUT | /item-categories, /item-categories/{id} | items.view / master_data.manage |
| GET, POST, PUT | /departments, /departments/{id} | requisitions.view / master_data.manage |

### Vendors
| Method | Path | Permission |
|---|---|---|
| GET, POST | /vendors | vendors.view / vendors.manage |
| GET, PUT | /vendors/{id} | vendors.view / vendors.manage |
| GET | /vendors/{id}/items | vendors.view (items + price history, derived from PO lines) |
| GET | /vendors/{id}/balance | finance.view (purchase amount, paid, outstanding, credit) |
| GET | /vendors/{id}/payables | payments.allocate (purchased POs with amount still due) |
| GET | /vendors/{id}/activities | vendors.view (payment entries only with payments.view) |

A vendor's purchases and payments are the filtered collections `GET /purchase-orders?vendor_id={id}` and `GET /payments?vendor_id={id}`. There are no duplicate nested endpoints.

### Purchase orders
| Method | Path | Permission |
|---|---|---|
| GET | /purchase-orders | purchases.view |
| GET | /purchase-orders/available-items | purchases.create (items with approved quantity still pending) |
| GET | /purchase-orders/available-items/{itemId} | purchases.create (that item's pending requisition lines, oldest first) |
| GET | /purchase-orders/{id}/deliverable | deliveries.create (remaining deliverable quantity per line) |
| POST | /purchase-orders | purchases.create |
| GET | /purchase-orders/{id} | purchases.view |
| PUT | /purchase-orders/{id} | purchases.update (draft only) |
| POST | /purchase-orders/{id}/submit | purchases.update |
| POST | /purchase-orders/{id}/approve | purchases.approve |
| POST | /purchase-orders/{id}/mark-purchased | purchases.update |
| POST | /purchase-orders/{id}/complete | purchases.update |
| POST | /purchase-orders/{id}/cancel | purchases.cancel |

### Deliveries
| Method | Path | Permission |
|---|---|---|
| GET | /deliveries | deliveries.view |
| POST | /deliveries | deliveries.create |
| GET | /deliveries/{id} | deliveries.view |
| POST | /deliveries/{id}/confirm | deliveries.confirm |
| POST | /deliveries/{id}/reverse | deliveries.reverse |

### Payments
| Method | Path | Permission |
|---|---|---|
| GET | /payments | payments.view |
| POST | /payments | payments.create (optional `allocations[]`) |
| GET | /payments/{id} | payments.view |
| POST | /payments/{id}/allocations | payments.allocate |
| POST | /payments/{id}/reverse | payments.reverse |

### Reports (`reports.view`; financial reports also need `reports.financial`)

`GET /reports` lists the reports the caller may open. Every report returns `{data, meta, totals, report: {title, date_basis, range, columns}}`. Totals cover the whole filtered set, not just the current page.

| Report key | Date used for filtering | Financial |
|---|---|:-:|
| `requisitions` (`date_basis=requisition_date\|uploaded_at`) | requisition date or upload time | |
| `items`: item-wise consolidated | requisition date | |
| `requested-quantity` (`group_by=day\|month`) | requisition date | |
| `pending-purchases` | requisition date | |
| `purchased-quantity` | purchase date | |
| `deliveries`: delivered quantity | delivery date | |
| `purchase-amount` (`group_by=day\|month`) | purchase date | ✓ |
| `vendor-purchases` | purchase date | ✓ |
| `vendor-payments` | payment date | ✓ |
| `outstanding` | as of the end date | ✓ |

| Method | Path |
|---|---|
| GET | /reports/{key} |
| POST | /reports/{key}/exports `{format: xlsx\|csv\|pdf, filters}` → 202 `{data: export}` (`reports.export`); runs on the `exports` queue |
| GET | /exports/{id} (status; owner only, otherwise 404) |
| GET | /exports/{id}/download (owner only; files expire after 24h) |

### Administration
| Method | Path | Permission |
|---|---|---|
| GET, POST | /users | users.manage |
| GET, PUT | /users/{id} | users.manage |
| GET | /roles, /permissions | users.manage (role list with user counts; permission catalog grouped by module) |
| POST, PUT, DELETE | /roles, /roles/{id} | roles.manage |
| GET | /activity-logs | activity_logs.view |
| GET | /settings | authenticated (non-secret settings) |
| PUT | /settings | settings.manage |

`PUT /requisitions/{id}` replaces the "PUT + separate line endpoints" approach. The review screen saves the header and all lines in one transactional request, which avoids half-saved reviews.
