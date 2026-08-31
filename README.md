# Cartly DS — design system, in pure PHP

**Cartly DS** turns the Cartly design language into a small, self-contained
**PHP command-line tool** plus an **interactive, responsive styleguide** — no
Node, no Tailwind, no Composer, no shell, no Python. One PHP binary owns the
three jobs that used to be scattered across a fragile toolchain:

1. **Compile** the design tokens + components into a single distributable CSS
   file.
2. **Render** an interactive, responsive styleguide (live dark mode, searchable
   token browser, WCAG contrast checker, component library) as one self-contained
   HTML file.
3. **Guard** against token drift, so the WordPress theme and the React storefront
   cannot silently diverge.

> This repo was a WooCommerce theme. The commerce layer is gone. What remains is
> the durable asset — the design system — made into a tool anyone can run.

---

## Why

The Cartly design language lives in *one* place — `assets/src/tokens.css` — but
is consumed by *two* front ends (this repo's WordPress theme and the React
storefront in
[`ecommerce-microservices`](https://github.com/K-Anjan25/ecommerce-microservices)).
The old way of keeping them in step needed **Node + Tailwind + bash + Python + a
CI job**, and it was brittle (the previous commit was literally a Git-Bash fix).

**Cartly DS** replaces all of that with one command you run with `php`.

---

## Requirements

| | |
|---|---|
| PHP | 7.4+ (CLI) |
| Node / npm / Tailwind | none |
| Composer / extensions | none |

---

## The tool

```bash
php bin/cartly --version      # Cartly DS 1.0.0
php bin/cartly help           # usage

php bin/cartly tokens         # dump the design tokens as a table
php bin/cartly tokens --json  # ... or as JSON (light, dark, raw)

php bin/cartly css            # compile tokens + components -> dist/cartly.css
php bin/cartly css --minify   # minified
php bin/cartly css --out path/to/file.css

php bin/cartly styleguide     # render an interactive styleguide -> dist/styleguide.html

php bin/cartly check          # token-drift guard; non-zero exit on drift
php bin/cartly check --baseline design/tokens.json

php bin/cartly build --out dist   # css + styleguide together
```

Every command reads the canonical `assets/src/tokens.css` and compiles it with
`src/CartlyDS/`. Because the tool is pure PHP, you can drop `bin/cartly` and
`src/` into *any* project (the WordPress theme or the React storefront) and run
the same build with the same output.

### The commands in detail

- **`tokens`** — parses `tokens.css`, splits light/dark, computes hex, groups
  tokens by role (brand, accent, neutral, contrast, state), and can export JSON.
- **`css`** — re-emits a clean `:root` + `.dark` block from the parsed tokens,
  then appends the component layer (`assets/src/components.css`) to produce one
  distributable stylesheet. This is a drop-in for the old Tailwind build.
- **`styleguide`** — renders a single, self-contained HTML page:
  - a **dark-mode toggle** (persisted, no flash),
  - a **searchable token browser** grouped by role, with copy-to-clipboard and
    light/dark values,
  - a **verified contrast-pairs table** using WCAG 2.x,
  - an **interactive contrast checker** (pick foreground/background),
  - the **type scale** and a **component library** (buttons, chips, badges,
    surfaces, the product card anatomy).
- **`check`** — compares the local tokens against a baseline (a JSON dump or
  another `tokens.css`) and returns a non-zero exit code on drift, so it drops
  straight into CI.

---

## Run them for real

```bash
php bin/cartly build --out dist

open dist/styleguide.html   # the interactive styleguide
```

`dist/styleguide.html` is fully self-contained (no network, no build step) — you
can email it or drop it on any static host.

---

## Source layout

```
bin/cartly                     the CLI + tiny autoloader
src/CartlyDS/Tokens.php        token model: parse, hex, WCAG contrast, grouping
src/CartlyDS/Compiler.php      tokens -> stylesheet (:root + .dark + components)
src/CartlyDS/Styleguide.php    tokens + css -> self-contained HTML styleguide
src/CartlyDS/Drift.php         compare two token sets, render a diff table
assets/src/tokens.css          THE canonical design tokens (light + dark)
assets/src/components.css      pure-CSS component layer (no @apply, no Tailwind)
design/tokens.json             the committed token baseline for `check`
dist/                          generated: cartly.css + styleguide.html
```

---

## Theme (still here, now WooCommerce-free)

The WordPress theme remains, but it is **no longer an e-commerce theme**. The
WooCommerce integration, product templates, cart/checkout styling and shop
tooling have all been removed. It now renders a clean blog/site theme using the
same design tokens, and it keeps a deliberately dark *contrast* hero, an inverse
footer, a mobile bottom tab bar and first-class dark mode.

The theme loads its compiled stylesheet from `assets/css/cartly.css`. If you
want to change the design language, edit `assets/src/tokens.css` and regenerate
the standalone stylesheet:

```bash
php bin/cartly css --out assets/css/cartly.css
```

---

## Checks / CI

The bundled CI (`.github/workflows/ci.yml`) needs only PHP:

```bash
find . -name '*.php' -not -path './node_modules/*' -print0 | xargs -0 -n1 php -l
php bin/cartly build --out dist
php bin/cartly check --baseline design/tokens.json
```

It lint-checks every PHP file, builds the CSS + styleguide, runs the drift
guard, and fails if the committed `dist/` artefacts are stale.

---

## Notes

- `assets/src/tokens.css` is the **single source of truth** for the palette; do
  not hand-edit `dist/` output.
- `color-scheme` is set per scheme so the browser paints the correct scrollbars
  and form controls automatically.
- The tool has **no external dependencies** — it uses only core PHP functions.
