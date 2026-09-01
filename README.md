# PayKaro — MSME invoice & receivables tracker

**PayKaro** is a workflow tool that turns an invoice into a finance-ready asset.
It gives India's MSMEs one pipeline to track what's owed, what's overdue, and
what they could finance today — with the evidence and interest numbers that make
a delayed-payment or TReDS claim actually stand.

This repo is now a **PayKaro-only project**. The former WooCommerce theme and the
Cartly design-system tooling have been removed; only the application and its docs
remain.

## What it does

- **One pipeline** for every invoice: `raised → accepted → financed → settled`
  (or `disputed`), each with a live balance and accruing statutory interest.
- **Evidence checklist** per invoice (purchase order, delivery ack, goods receipt
  note, GST-valid copy) so the finance / dispute packet is never missing a document.
- **TReDS / financing queue** that separates what can be financed today from the
  gaps holding it back.
- **Interest & ageing** computed from the invoice (not a spreadsheet), so a claim
  stands on the correct number.
- **Guided evidence packet** for an MSEFC / mediation / arbitration claim.

## Real auth + multi-tenant isolation

- Users, password hashing (`password_hash`), and session tokens.
- Each user belongs to exactly one **business (tenant)**.
- Every query is scoped to the user's business (a `tid()` guard), so a user never
  sees another business's invoices, buyers, payments or disputes.
- **Email + password sign-up** at `/signup` and optional **Google Sign-In**
  (OAuth 2.0, config-driven).

## Stack

- **Backend:** PHP 8.3, PDO (SQLite for the zero-config demo; points at MySQL via
  `PAYKARO_DB_DSN`).
- **Frontend:** server-rendered HTML with a self-contained design system
  (`public/assets/app.css`) — an editorial "ink & paper" theme (DM Sans / Fraunces,
  moss + lime) matching `design.html`, responsive + light/dark modes.
- **Bridge (sandbox):** `bridge/serve.mjs` runs the PHP app inside
  `@php-wasm/node`, translating HTTP ↔ PHP superglobals. `bridge/seed.mjs` seeds
  the SQLite demo DB when no native PHP is available.

## Run

See [`RUN.md`](RUN.md). Demo logins use password `demo1234`.

## Project layout

```
config.php         business rules, PDO DSN
db.php             PDO singleton
schema.sql         businesses, users, sessions, buyers, invoices,
                   invoice_evidences, payments, financing, disputes, alerts
PayKaro.php        domain service: auth + tenant scoping + rules
bin/seed.php       install + idempotent seed (two tenants)
public/index.php   web router + views (landing, login, pricing, app pages)
public/assets/app.css
bridge/serve.mjs   Node ↔ PHP bridge (sandbox)
bridge/seed.mjs    seed runner for the sandbox (no native PHP needed)
bridge/boot.php    superglobal bootstrap for the bridge
```
