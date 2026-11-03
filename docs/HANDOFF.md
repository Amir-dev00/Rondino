# Handoff — روندینو (2026-11-03)

## What works

- Laravel 12 + Blade RTL Persian UI (listings, SIM detail, price estimate, team deals, source health, admin users)
- Session auth, no public registration, admin-only account create/deactivate, login throttle
- 13 source adapters registered exactly once; shared HTTP/normalize/upsert
- Bounded cron crawl with locks, checkpoints, backoff; failed crawls do not mark SIMs sold
- Price estimate with min 5 independent SIMs, 30-day window, P25–P75, «داده کافی نیست» fallback
- Fixture PHPUnit suite: **25 tests, 84 assertions — OK**
- Live crawl (this environment, after CA bundle fix): **1964 offers** persisted

## Commands actually run

```
composer create-project laravel/laravel (v12)
php artisan migrate --force
php artisan sources:sync
php artisan rondino:create-admin admin@rondino.local ...
php vendor/bin/phpunit   → OK (25)
php artisan crawl:due --source=... --force  (all 13)
```

## Source status matrix (live smoke 2026-11-03)

| # | Source | Live HTTP | Listings upserted | Live-verified fields | Status |
|---|--------|-----------|-------------------|----------------------|--------|
| 1 | rond.ir | timeout / blocked adapter | 0 | none | **unverified / blocked** |
| 2 | tehransim.com | 200 | 12+ | number, price toman, condition(used URL), store contacts | **live-verified** |
| 3 | iran912.com | 200 | 578 | number, price, condition(data-status), store contacts | **live-verified** |
| 4 | simcartiha.ir | partial HTTP on some code pages | 4 | number, price (sparse condition) | **partial live** |
| 5 | endline912.com | 200 | 299 | number, price, store contacts | **live-verified** |
| 6 | donyayekhat0912.com | 200 | 0 | none (no listing cards in SSR) | **reachable, not parseable** |
| 7 | cafesimcart.com | 200 | 184 | number, price; condition often unknown (encoding) | **live-verified (price/number)** |
| 8 | rond0912.com | 200 | 789 | number, price | **live-verified** |
| 9 | simkhan.ir | 200 | 0 | store ads only; SIM API unknown | **SPA / not SIM-list** |
| 10 | setaresim.com | timeout | 0 | none | **unverified / blocked** |
| 11 | jamsimkart.com | 200 | 18 | number, price, condition, store contact | **live-verified** |
| 12 | rondbaz.com | 200 | 56 | number, price, condition, seller slug | **live-verified** |
| 13 | 0912sim.com | 200 | 68 | number, price, store contacts | **live-verified** |

## Still unverified / limitations

- **rond.ir** and **setaresim.com**: connection timeout from this network — retest on cPanel: `php artisan sources:audit --source=rond-ir` / `setaresim`
- **simkhan.ir**: needs discovered public SIM inventory API (guessed paths 404)
- **donyayekhat0912.com**: SSR without listing cards; BitNinja
- City field rarely present on cards — correctly stored as null / shown «اعلام نشده»
- Tehransim pages beyond SSR may need POST `/Home/GetHomeSimkart` (noted in capability)
- Future-price forecast: **not enabled** (insufficient time-based evaluation by design)
- Production package must use MySQL; local smoke used SQLite
- Do not ship `.env` or real seller DB dumps

## Retest on cPanel

```bash
php artisan sources:audit --save-html
php artisan crawl:due --force
```
