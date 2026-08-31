# PayKaro — MSME invoice & receivables tracker

**Tagline:** Turn invoice mess into an evidence-complete, finance-ready pipeline.

## Why this product

India's MSMEs live on receivables, but the money owed against them is a tangle of
spreadsheets, WhatsApp messages and missing purchase orders. When the due date
passes, most small suppliers cannot prove *when* the invoice was raised, *what*
was delivered, or *which* document proves acceptance — so they write the invoice
off or wait months. The 2026 MSME Development (Amendment) Bill tightens TReDS
mandates for CPSE buyers and strengthens the MSEFC delayed-payment forum, which
means the businesses that keep clean, complete, **dated** records now have real
leverage: statutory interest (3× bank rate), a time-bound dispute forum and
discounted invoice financing.

PayKaro is the workflow tool that turns an invoice into a finance-ready asset:

- **One pipeline** for every invoice: raised → accepted → financed → settled
  (or disputed), each with a live balance and accruing interest.
- **Evidence checklist** per invoice (PO, delivery ack, GRN, GST-valid copy) so
  the finance/dispute packet is never missing a document.
- **TReDS / financing queue** that separates what can be financed today from
  the gaps holding it back.
- **Interest & ageing** computed from the invoice (not from a spreadsheet), so a
  claim always stands on the correct number.
- **Guided evidence packet** for an MSEFC/mediation/arbitration claim.

## Scope (v1)

A single-tenant-database, web app with **real auth + per-business isolation**:

- Users, password hashing (`password_hash`), session tokens.
- Each user belongs to exactly one **business (tenant)**.
- Every query is scoped to the user's business — a user can never see another
  business's invoices, buyers, payments or disputes (enforced in the data layer).
- One-click **demo logins** so the product stays instantly viewable.

## Stack

- **Backend:** PHP 8.3, PDO (driver-agnostic; SQLite for the zero-config demo,
  points at MySQL via `PAYKARO_DB_DSN`).
- **Frontend:** server-rendered HTML with a self-contained Northstar design
  system (`public/assets/app.css`) — responsive + dark mode.
- **Bridge (sandbox):** `bridge/serve.mjs` runs the PHP app inside
  `@php-wasm/node`, translating HTTP ↔ PHP superglobals. Static assets served off
  disk. Every request gets a fresh PHP runtime (no state leaks).

## Data model (`schema.sql`)

- `businesses` — the tenant root (name, GSTIN, PAN, Udyam, bank details, TReDS flag).
- `users` — `business_id` FK, email UNIQUE, password hash, role (`owner|accountant|viewer`).
- `sessions` — `token` PK, `user_id`, `expires_at`.
- `buyers` — `business_id` FK; type + TReDS onboarding status.
- `invoices` — `business_id` FK, `buyer_id` FK; dates, amounts, status, TReDS status.
- `invoice_evidences` — per-invoice doc checklist (`po`, `delivery_ack`, `grn`, `contract`, `invoice_copy`).
- `payments`, `financing`, `disputes` — money movement, financing, claim forums.
- `alerts` — per-business attention items.

## Domain rules (`config.php`)

- MSME due window: **45 days**.
- Default GST: **18%**.
- Statutory interest: **bank_rate (6.5%) × 3** per annum, applied daily on overdue.
- Required inbound evidence: `po`, `delivery_ack`, `grn`, `invoice_copy`.
- Session TTL: **7 days**.

## Multi-tenant isolation

`PayKaro` holds a `tenantId`. Every read/write path goes through a `tid()` guard
(`WHERE business_id = :tenant`) and stamps `business_id` on insert. `userByToken()`
sets the tenant from the user's `business_id`, so the UI and the data layer can
never diverge. A user opening a URL for another business's invoice gets
"Invoice not found" — not a 403, not data.

## Demo logins (seeded)

| User | Email | Business | Tenant |
|------|-------|----------|--------|
| Sunita Rao | `sunita@shreeprecision.in` | Shree Precision Components | 1 |
| Farhan Ali | `farhan@metrowceramics.in` | MetRow Ceramics | 2 |

Both use password `demo1234`. One-click links: `/login?demo=1` and `/login?demo=2`.

## Run

See `RUN.md`.
