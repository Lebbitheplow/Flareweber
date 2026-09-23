# FlareWeber

Open-source, self-hostable website builder and ecommerce platform based on
[Microweber](https://microweber.com) that publishes each site into the owner's
own Cloudflare account: one Worker (static assets + API), one D1 database and
one R2 bucket, created only when the site needs them. No Cloudflare Pages, no
wrangler and no DNS knowledge required from the user.

```
Microweber (authoring) -> Site Compiler -> Cloudflare Worker + D1 + R2 (production)
```

The intended experience is: install, connect Cloudflare, create a site,
connect a domain, connect Stripe, build, publish. Cloud infrastructure stays
an implementation detail.

## Layout

```
app/                      Microweber 2.0.20 (created by composer on first boot, gitignored)
module/flareweber/        FlareWeber Microweber module (connect, compile, deploy, admin SPA, JSON API)
worker/                   Per-site Cloudflare Worker template (Hono + D1 + R2 + static assets)
desktop/                  Electron desktop app (bundles PHP + Microweber + module, SQLite)
docker/                   PHP 8.3 Apache image + first-boot entrypoint
docker-compose.yml        Web + MariaDB
```

## Quickstart (Docker)

```bash
cp .env.example .env          # optional: Cloudflare OAuth + Stripe Connect platform keys
docker compose up -d --build  # first boot installs Microweber v2.0.20 into app/ and links the module
```

Open http://localhost:8080, finish the Microweber installer, then open
http://localhost:8080/flareweber/admin.

## Desktop app (Windows / Linux / macOS)

The desktop app is an Electron shell that bundles a self-contained PHP
runtime (intl, sodium, pdo_sqlite and friends) and the full Microweber app
with the FlareWeber module. On first launch it copies the app into the user
data directory, installs Microweber on SQLite while showing progress, serves
it on loopback port 8471 (persisted in `port.json`, `FLAREWEBER_PORT`
overrides), logs the admin in automatically through a per-launch token and
opens the FlareWeber admin. The generated admin credentials are also kept in
`admin-credentials.json` (App menu: Show Admin Credentials).

```bash
cd desktop
npm install
npm run prepare:app           # composer create-project microweber v2.0.20 + module + prebuilt Worker bundle
npm run fetch:runtime         # downloads the PHP runtime (Windows: php.net zip + generated php.ini; Linux/macOS: static-php-cli in CI)
npm start                     # run unpacked for development
npm test                      # unit tests (deep links, copy rules, navigation policy)
npm run build:linux           # AppImage + deb
npm run build:win             # NSIS installer (run on Windows)
npm run build:mac             # dmg (run on macOS)
```

`.github/workflows/desktop.yml` builds all three installers and refuses to
package when the PHP runtime, Microweber app or Worker bundle is missing.

## Connecting Cloudflare and Stripe

Both services can be connected with OAuth or with a key the user pastes. The
pasted path always works; the OAuth buttons appear only when the platform
credentials are configured in the environment.

| Service | OAuth | Pasted key |
| --- | --- | --- |
| Cloudflare | `CLOUDFLARE_OAUTH_CLIENT_ID` (+ optional secret, PKCE otherwise). Desktop registers the `flareweber://cloudflare/callback` scheme; web uses `${APP_URL}/flareweber/cloudflare/callback`. | API token with Workers Scripts, D1, R2, Account Settings Read, Zone Read, DNS Edit, Workers Routes and SSL Edit. The admin shows this list and verifies the token before saving it. |
| Stripe | `STRIPE_CLIENT_ID` + `STRIPE_SECRET_KEY` (Stripe Connect). Redirect URI is `http://127.0.0.1:8471/flareweber/sites/{site}/stripe/callback` on desktop. | A secret or restricted key from the owner's own Stripe dashboard, verified against the Stripe account before saving. |

Tokens and keys are stored encrypted at rest and never leave the authoring
app except as Worker secrets. Disconnecting revokes and clears the tokens but
never touches site data.

## Admin SPA

`GET /flareweber/admin` is a mobile-first admin (works at 360px and on
desktop) with Home, Pages, Shop, Media and More tabs:

- Home: deploy state, changes since the last publish, quick stats, site switcher
- Pages and Posts: tree, add, hide, delete, open Microweber live edit in place
- Shop: products with a stock stepper, orders read from the site's D1 with fulfilment
- Media: grid, upload, delete
- Publish: review changes, run with a live step list and log, deploy history and rollback
- Domain: zone check, Cloudflare nameservers to set, connect, HTTPS status
- More: Cloudflare and Stripe connections, site settings, export notes, classic admin

The SPA talks to the JSON API under `/flareweber/*` and `/flareweber/api/*`
(see `module/flareweber/routes/web.php` and `routes/admin.php`).

## Publish flow

`POST /flareweber/sites/{id}/publish` answers 202 and runs the deployment in
a detached `php artisan flareweber:run-deployment` process; the SPA polls
`GET .../deployments/{id}` for step status and the log. Steps:

1. Validate: Cloudflare connected, Stripe connected when the shop is on, pages present, no missing assets or broken navigation links, products priced
2. Provision: Worker, D1 (forms or shop) and R2 (media) created only when missing; workers.dev subdomain enabled
3. Media: incremental sync of `userfiles/media` to R2 (sha256 manifest, prunes deletions)
4. Compile: pages rendered through a loopback request as an anonymous visitor, cleaned with a DOM parser, links and assets rewritten root-relative, plus `404.html`, `thank-you/`, `sitemap.xml`, `robots.txt`
5. Upload: Worker bundle + assets through the Cloudflare REST API with `keep_bindings`, then secrets
6. Seed: D1 schema, migrations and an upsert-only product seed (orders, carts and inventory are never deleted)
7. Health: home page marker, `/api/health`, then the custom domain over HTTPS
8. Domain: custom hostname attached when configured

A failed production publish rolls back to the previous successful Worker
version. Preview deploys use a separate `--preview` Worker and never touch
the production database.

## Worker runtime (worker/)

- `GET /*` static compiled pages via the Assets binding (`404.html` handling)
- `/media/*` streams the site's R2 bucket (Range, ETag, content types)
- `/api/products`, `/api/cart`, `/api/checkout` (Stripe Checkout Sessions), `/api/orders` (session id or signed token), `/api/customers` (register, login, me), `/api/forms` (honeypot, rate limit), `/api/webhooks/stripe` (signature verified, idempotent, guarded inventory decrement), `/api/health`
- `schema.sql` plus `migrations/*.sql` define the D1 runtime schema

```bash
cd worker
npm install
npm run dev          # wrangler dev with local D1/R2
npm run typecheck
npm test
npm run build        # dist/worker.mjs (shipped inside Docker and the desktop app)
```

## Export and import

```bash
php artisan flareweber:export --out=site.zip     # sites, deployments, Microweber content, media
php artisan flareweber:import site.zip           # recreates content; connections come back disconnected
```

Bundles contain no secrets and no account-bound resource ids.

## Tests

```bash
cd worker && npm test
cd desktop && npm test
php vendor/bin/phpunit -c module/flareweber/phpunit.xml   # from inside the Microweber app
```

## Security notes

- Admin routes require a Microweber admin session; OAuth callbacks are guarded by state and PKCE, the desktop handoff by a random token
- Cloudflare and Stripe credentials use encrypted casts and are hidden from every API response
- Worker secrets (`CART_SECRET`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`) only exist as Worker secrets; compiled assets never contain credentials
- Only `userfiles/media` is synced to R2; template and module sources are bundled as static assets, never PHP
- The Worker fails closed when its cart secret is missing and validates every input

## Known gaps

- Not yet exercised against a real Cloudflare or Stripe account (the API contracts were verified against the docs and a local fake API)
- Product pages are compiled statically; there is no Worker-side HTML rendering for products added after a publish
- Customer accounts exist in the Worker API but the compiled storefront has no account UI yet
- The in-process render fallback (used only when the loopback request fails) inherits Microweber's per-process page constants
