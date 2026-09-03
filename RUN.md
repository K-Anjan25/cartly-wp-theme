# Running PayKaro

PayKaro is a PHP 8.3 app. It runs natively against PDO (SQLite here, MySQL-compatible),
or in a sandbox via a Node bridge that runs PHP inside `@php-wasm/node`.

## Native PHP (MySQL/SQLite)

From the repo root:

```bash
# Create the DB, seed two demo businesses + users + invoices
php bin/seed.php

# Serve. `public/router.php` is required: it serves assets off disk and sends
# every other path to the front controller (so /invoices, /login, ... resolve).
php -S 0.0.0.0:8080 -t public public/router.php
```

Point at MySQL instead of SQLite:

```bash
PAYKARO_DB_DSN='mysql:host=127.0.0.1;dbname=paykaro;charset=utf8mb4' \
PAYKARO_DB_USER=paykaro PAYKARO_DB_PASS='...' php bin/seed.php
```

## Sandbox bridge (this repo's demo)

From `bridge`:

```bash
npm install          # @php-wasm/node + @php-wasm/universal
VITEST=1 node serve.mjs
```

The bridge listens on `0.0.0.0:8080`. It:

- translates HTTP → PHP superglobals for `public/index.php`,
- runs each request in a **fresh** PHP runtime (no cross-request state leaks),
- serves `public/assets/*` straight off disk,
- echoes the app's JSON response back as real HTTP (status, Location, Set-Cookie, body).

## First run

`seed.php` is idempotent: it ensures the schema and, if no users exist, seeds
**two** tenants:

- Shree Precision Components — 15 invoices, 1 user (Sunita).
- MetRow Ceramics — 3 invoices, 1 user (Farhan).

## Sign in

| User | Email | Password | Business |
|------|-------|----------|----------|
| Sunita Rao | `sunita@shreeprecision.in` | `demo1234` | Shree Precision (tenant 1) |
| Farhan Ali | `farhan@metrowceramics.in` | `demo1234` | MetRow Ceramics (tenant 2) |

Sign in at `/login` with one of these test accounts to explore the app.

### Sign up (email + password)

Anyone can create an account at `/signup`. It creates a new business (tenant)
and an owner user, then signs them straight in. The email must be unique; a
duplicate shows `?err=taken`.

### Google Sign-In (OAuth 2.0)

A "Continue with Google" button appears on `/login` and `/signup` when OAuth is
configured. Config is driven by `config.php` → `google_oauth`:

```bash
GOOGLE_CLIENT_ID='<your-client-id>.apps.googleusercontent.com' \
GOOGLE_CLIENT_SECRET='<your-client-secret>' \
GOOGLE_REDIRECT_URI='https://your-domain/auth/google/callback' \
VITEST=1 node serve.mjs
```

- `redirect_uri` **must be registered exactly** in the Google Cloud Console
  under *Authorized redirect URIs*. If it's left blank, it is derived from the
  incoming Host header at runtime (`https://{host}/auth/google/callback`), which
  is what makes the live preview work without config.
- One-click flow: `/auth/google` → Google consent → `/auth/google/callback`
  validates a one-time CSRF `state` (20-min TTL), exchanges the code for a token,
  fetches the profile, then **finds or creates** the user. If the Google email
  already has an account it is linked; otherwise a new business + owner is made.
- OAuth-only users (no password) can still sign in via Google but **not** via the
  password form.

## Routes

- `/` overview/dashboard (public visitors see the landing page), `/invoices`, `/invoices/new`, `/invoice?id=N`, `/invoice?edit=N`, `/claim?id=N`
- `/buyers`, `/buyers/new`
- `/treds` finance queue, `/reports`, `/settings`
- `/pricing` public pricing page (also reachable from the sidebar "Upgrade to Pro" / "See pricing" card)
- `/login`, `/signup`, `/auth/google`, `/auth/google/callback`, `/logout` (POST), `/alerts/read` (POST — dismisses the dashboard "Needs attention" list)

## Sandbox seed helper

If PHP isn't installed on the host, seed the demo database through the bridge
instead of `php bin/seed.php`:

```bash
cd bridge
npm install
VITEST=1 node seed.mjs   # idempotent; writes data/paykaro.sqlite
```

## Sandbox lint helper

The CI lints with native `php -l`. Without a native PHP binary, lint every
PHP file through the same php-wasm runtime the bridge uses:

```bash
cd bridge
VITEST=1 node lint.mjs ../PayKaro.php ../public/index.php   # any repo .php file(s)
```

## Layout

```
config.php         business rules, DSN
db.php             PDO singleton
schema.sql         tables (businesses, users, sessions, oauth_states, buyers,
                   invoices, invoice_evidences, payments, financing, disputes, alerts)
PayKaro.php        domain service: auth + tenant scoping + rules
bin/seed.php       install + seed
public/index.php   web router + views (Northstar)
public/assets/app.css
public/router.php  built-in-server router (native mode)
bridge/serve.mjs   Node ↔ PHP bridge (sandbox)
bridge/boot.php    superglobal bootstrap for the bridge
```
