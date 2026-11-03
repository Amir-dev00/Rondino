# Deploy Rondino to cPanel over explicit FTPS (GitHub Actions)

## Host layout

| Item | Path |
|------|------|
| FTP jail (account root) | `/home/USERNAME/rondino` → remote `/` |
| Subdomain document root | `/home/USERNAME/rondino/public` |
| App code (outside public only via parent of `public`) | same jail root |

## Secrets (GitHub → Settings → Secrets and variables → Actions)

| Secret | Meaning |
|--------|---------|
| `FTP_SERVER` | Hostname, e.g. `ftp.example.com` or IP |
| `FTP_USERNAME` | Dedicated FTP user jailed to `rondino` |
| `FTP_PASSWORD` | FTP password |

## FTPS port

This workflow uses **explicit FTPS on port `21`** (`protocol: ftps`, `port: 21` in `.github/workflows/deploy.yml`).

That is the usual cPanel “FTP with TLS/SSL (explicit)” setting. Only change the port if your host’s documentation says otherwise (some panels expose FTPS on `990` for *implicit* FTPS — this workflow is **explicit**, not implicit).

## What the workflow does

On every push to `main` (serialized; no overlapping production deploys):

1. `composer install` (with dev) + PHPUnit
2. `npm ci` + `npm run build` (Vite). Runtime CSS today is still `public/css/app.css` via `asset()`; Vite output is included when `public/build` exists.
3. Stages a clean tree with **production** `vendor/` via `scripts/stage-cpanel-deploy.sh`
4. Verifies `artisan`, `vendor/autoload.php`, `public/index.php`, `public/.htaccess`, `public/css/app.css`
5. Uploads the staging directory with [SamKirkland/FTP-Deploy-Action](https://github.com/SamKirkland/FTP-Deploy-Action) over explicit FTPS

It does **not**:

- Import `release/rondino-install.sql`
- Run migrations / create admin / start crawlers
- Enable `dangerous-clean-slate`

## First run = dry-run

The workflow ships with:

```yaml
dry-run: true
```

Review the Actions log (file add/update list). When satisfied, change **exactly this line** in `.github/workflows/deploy.yml`:

```yaml
dry-run: true
```

to:

```yaml
dry-run: false
```

Commit and push that one-line change to `main`.

## Server files preserved by design

Exclusions + `dangerous-clean-slate: false` keep the host’s runtime data. Do **not** put production `.env` in git. Upload exclusions cover:

- `.env` / env variants (except `.env.example`)
- `storage/logs`, framework cache/sessions/views contents
- SQLite / `*.sql` / `tests/` / `release/` / `node_modules`

## One-time writable directories (create in File Manager if missing)

After the first real upload, ensure these exist and are writable by PHP (often `755` or `775`):

- `storage/app`
- `storage/app/public`
- `storage/framework`
- `storage/framework/cache`
- `storage/framework/cache/data`
- `storage/framework/sessions`
- `storage/framework/views`
- `storage/logs`
- `bootstrap/cache`

Keep the production `.env` in the jail root (`/home/USERNAME/rondino/.env`) — never overwrite it via FTP from CI.

## Separate from file deploy (manual / cron)

Still follow `docs/INSTALL-NO-TERMINAL-FA.md` for:

- MySQL + `rondino-install.sql` (once)
- First admin via one-time cron + `--password-file`
- Recurring `crawl:scheduled` cron

## Schema-change warning

If a release adds or changes migrations, **file deploy alone is not enough**. Import/migrate on the host separately (phpMyAdmin or a controlled one-time process). CI will never auto-import SQL on push.

## Local packaging note

`release/rondino-cpanel.zip` remains for manual no-SSH installs. FTPS CI deploys **files directly**, not a ZIP that must be extracted after each push.
