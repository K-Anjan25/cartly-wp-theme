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
- One-click **demo logins** keep the product instantly viewable.

## Stack

- **Backend:** PHP 8.3, PDO (SQLite for the zero-config demo; points at MySQL via
  `PAYKARO_DB_DSN`).
- **Frontend:** server-rendered HTML with the self-contained **Northstar** design
  system (`public/assets/app.css`) — responsive + dark mode.
- **Bridge (sandbox):** `bridge/serve.mjs` runs the PHP app inside
  `@php-wasm/node`, translating HTTP ↔ PHP superglobals.

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
public/index.php   web router + views (Northstar)
public/assets/app.css
bridge/serve.mjs   Node ↔ PHP bridge (sandbox)
bridge/boot.php    superglobal bootstrap for the bridge
```
