# FlareWeber

Open-source, self-hostable website builder and ecommerce platform based on
[Microweber](https://microweber.com) that publishes each site into the owner's
own Cloudflare account: one Worker (static assets + API), one D1 database, one
R2 bucket. No Cloudflare Pages, no wrangler, no DNS knowledge required by the
user.

```
Microweber (authoring) -> Site Compiler -> Cloudflare Worker + D1 + R2 (production)
```

Desktop-first: the primary distribution target is a Windows/Linux desktop app
that bundles a local PHP runtime and runs the Microweber authoring environment
locally; publishing ships compiled output to the customer's Cloudflare
account.

## Compile pipeline

`SiteCompiler` (in `module/flareweber/src/Compiler/`) captures the published
site from the bundled Microweber app itself — `PageExtractor` renders pages
in-process through the HTTP kernel (no external URL or loopback crawl),
`HtmlCompiler` strips live-edit chrome and rewrites links/assets, assets are
copied from disk (`public/userfiles`), `ProductExtractor` reads shop items
from Microweber's `content`/`content_data` tables, and `SeedGenerator` emits
D1 seed SQL that `D1Seeder` applies through the Cloudflare API on deploy.

## Layout

```
app/                      Microweber core (created by composer on first boot, gitignored)
module/flareweber/        FlareWeber Microweber module (OAuth, provisioning, compile, deploy)
worker/                   Per-site Cloudflare Worker template (Hono + D1 + R2 + static assets)
desktop/                  Electron desktop app (bundles PHP + Microweber + module, SQLite)
docker/                   PHP 8.3 apache image + first-boot entrypoint
docker-compose.yml        Web + queue worker + MariaDB
```

## Quickstart

```bash
cp .env.example .env          # add Cloudflare OAuth + Stripe Connect keys
docker compose up -d --build  # first boot installs Microweber into app/ and links the module
```

Open http://localhost:8080, finish the Microweber installer, then use the
module API under `/flareweber/*` (see `module/flareweber/routes/web.php`).

Worker template development:

```bash
cd worker
npm install
npm run dev                   # wrangler dev with local D1/R2 mocks
npm run typecheck
```

## Desktop app (Windows / Linux)

The desktop app is an Electron shell that bundles a self-contained PHP
runtime and the full Microweber app (with the FlareWeber module) inside the
installer. On first launch it copies the app to the user's data directory,
runs `microweber:install` with a bundled SQLite database, serves it on a
random loopback port, and opens it in the window. Admin credentials land in
`admin-credentials.json` in the data folder.

```bash
cd desktop
npm install
npm run prepare:app           # composer create-project + copies module/flareweber in
npm run fetch:runtime         # downloads PHP runtime (Windows: php.net; Linux: --url, see CI)
npm start                     # run unpacked for development
npm run build:linux           # AppImage + deb (Linux)
npm run build:win             # NSIS installer (run on Windows)
```

`.github/workflows/desktop.yml` builds both installers: static PHP via
static-php-cli on Linux, php.net thread-safe zip on Windows.

## Module API (module/flareweber)

| Area | Endpoints |
| --- | --- |
| Cloudflare | `GET /flareweber/cloudflare/connect`, `GET .../callback`, `GET .../accounts`, `POST .../accounts/select`, `GET .../status`, `POST .../{connection}/disconnect` |
| Sites | `GET/POST /flareweber/sites`, `GET/PUT /flareweber/sites/{id}` |
| Publish | `POST /flareweber/sites/{id}/publish`, `POST .../preview`, `GET .../deployments`, `POST .../deployments/{v}/rollback` |
| Domain | `POST /flareweber/sites/{id}/domain/check`, `POST .../domain/connect` |
| Stripe | `GET /flareweber/sites/{id}/stripe/connect`, `GET .../callback`, `POST /flareweber/webhooks/stripe` (Connect lifecycle, CSRF-exempt, signature-verified) |

Key classes: `Cloudflare/OAuthService` (PKCE + state), `Cloudflare/ResourceProvisioner`
(Worker/D1/R2 created only when the site needs them), `Compiler/SiteCompiler`
(pages + products + D1 schema into a build dir), `Deploy/PublishPipeline`
(validate, provision, compile, deploy, health check, rollback),
`Domain/DomainConnector` (zone check, Workers custom domain, HTTPS verify).

## Publish flow

1. Validate (Cloudflare connected, Stripe connected when ecommerce is on)
2. Provision missing resources (Worker, D1 when forms/shop, R2 when media)
3. Compile site to `storage/app/flareweber/builds/{site}/{uuid}`
4. `wrangler versions upload` the worker template + compiled assets
5. Health check the worker URL; every attempt is recorded in `flare_deployments`
   and rollback re-points to a previous worker version

## Worker runtime (worker/)

- `GET /*` static compiled pages via the Assets binding; the Worker answers
  404s and everything under `/api/*`
- `/api/products`, `/api/cart`, `/api/checkout` (Stripe Checkout Sessions),
  `/api/orders`, `/api/customers`, `/api/forms`, `/api/webhooks/stripe`
  (signature-verified, marks orders paid, decrements inventory)
- `schema.sql` seeds the D1 runtime schema (products, variants, inventory,
  carts, orders, customers, discounts, form entries, settings)

## Security notes

- Cloudflare OAuth tokens stored encrypted at rest (`encrypted` cast), PKCE +
  state on the OAuth flow, minimum scopes in `.env`
- Stripe uses Connect (per-site accounts); webhooks verified in both PHP and
  the Worker; secrets only exist as Worker secrets, never in generated assets
- Disconnecting Cloudflare only marks the connection disconnected; site data
  is never destroyed

## Known gaps (next milestones)

- Live Cloudflare validation of the REST deploy path (contract verified against
  docs + wrangler source and unit-tested; not yet exercised end to end)
- R2 media upload/sync from the Microweber media manager
- Admin UI (mobile-first screen set exists as a design mockup), export/migration
  commands
