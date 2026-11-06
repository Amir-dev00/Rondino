# Rond inventory crawl (local Windows)

Use PHP, MySQL and the existing optional Playwright installation. This browser route has not been verified on cPanel.

From the project directory, in Windows cmd:

```bat
start-rond-crawl.bat
```

Or:

```bat
php scripts/crawl-rond.php --incremental
php scripts/crawl-rond.php --full
php scripts/crawl-rond.php --restart
php scripts/crawl-rond.php --retry-failed
php scripts/crawl-rond.php --contacts
```

### Full vs incremental

| Mode | CLI | Behavior |
|------|-----|----------|
| **Incremental** (default after a verified full inventory) | `--incremental` or no flags when idle | Streams each `advertSpecialType` separately (`rondino_incremental_special_types`, default VIP + FREE) with `advertSpecialTypeIn` in the list POST body. Within each stream, stops after `rondino_incremental_overlap_pages` consecutive pages whose `external_id` values were **all already in the DB**. Caps at `rondino_incremental_max_pages` **per stream**. |
| **Full reconcile** | `--full` or `--restart` | Traverses the unfiltered inventory (all special types together). Required periodically (`rondino_full_reconcile_every_hours`, default 168). |

**Why per-type streams:** Cached list pages show VIP-only early pages and FREE-only late pages under the default multi-field sort (`advertSpecialType` DESC first). A single global overlap of three known VIP pages can stop before any FREE page and miss brand-new FREE listings. Overlap is therefore tracked **per branch/stream**, not globally.

**Verified API limitation:** list JSON rows do **not** include `lastUpdateDateMillis` / `createDateMillis` fields (only the sort clause references them). There is no confirmed `updatedSince` body filter. Therefore incremental early-stop uses **known-`external_id` overlap**, not a server-side date watermark. Price/status changes are applied for every listing that appears in the scanned prefix of each stream. Bursts larger than `max_pages × page_size` per type, or types outside the configured list, still require `--full`. Incremental mode must **not** be described as complete inventory coverage.

Sync metadata lives in `source_sync_state` (see `database/rondino-upgrade-source-sync.sql`). Deduplication key remains `(source_id, external_id)`. Price history uses `price_observations` only when price amount/type changes.

### Production DB: `source_sync_state` (cPanel / phpMyAdmin)

Do this **before** enabling `--incremental` on production. Deploying PHP files alone does **not** create the table.

#### Exact phpMyAdmin steps

1. **Backup**
   - phpMyAdmin → select the production database → **Export**
   - Method: **Custom**
   - Format: **SQL**
   - Tables: select at least `offers`, `price_observations`, `sources`, `crawl_passes`, `sim_numbers`, `offer_contacts` (or **Select all**)
   - Output: Save file locally; confirm the `.sql` download exists before continuing

2. **Apply migration**
   - phpMyAdmin → select the same database → **Import**
   - Choose file: `database/rondino-upgrade-source-sync.sql` from this repo
   - Click **Go**
   - Expected: `source_sync_state` created (or “already exists” if re-run)
   - If you see `#1061 Duplicate key name 'idx_offers_source_last_seen'`, the offers index is already present — that is OK; ignore that error only

3. **Verification queries** (SQL tab — all must succeed)

   ```sql
   -- 1) Table exists
   SHOW TABLES LIKE 'source_sync_state';

   -- 2) Required columns
   SHOW COLUMNS FROM source_sync_state
     WHERE Field IN (
       'source_id','mode_last','last_full_completed_at','last_incremental_completed_at',
       'overlap_pages','incremental_max_pages','full_reconcile_every_hours','updated_at'
     );

   -- 3) Offers lookup index used by overlap detection
   SHOW INDEX FROM offers WHERE Key_name = 'idx_offers_source_last_seen';

   -- 4) Optional: ensure a row can be created for rond-ir after first crawl
   SELECT s.slug, sync.*
   FROM sources s
   LEFT JOIN source_sync_state sync ON sync.source_id = s.id
   WHERE s.slug = 'rond-ir';
   ```

   Expect: one table name returned; eight column rows from `SHOW COLUMNS`; at least one index row; `rond-ir` source present (sync columns may be NULL until the first completed full crawl).

4. **Production baseline (mandatory before incremental)**
   - After code + migration are live, run a **full** inventory crawl on production (`php scripts/crawl-rond.php --full` on the host that has Playwright, or your approved production worker path)
   - Confirm:
     ```sql
     SELECT mode_last, last_full_completed_at, last_total_pages, last_total_elements
     FROM source_sync_state
     JOIN sources ON sources.id = source_sync_state.source_id
     WHERE sources.slug = 'rond-ir';
     ```
     `last_full_completed_at` must be non-NULL and `mode_last` should be `full`
   - Only then enable routine `--incremental` / auto-incremental

5. **Do not** switch production to incremental-only until steps 1–4 are verified.

Set `rondino_browser_proxy` in `config/local.php` (example: `socks5://127.0.0.1:10808`) or export `RONDINO_BROWSER_PROXY`. By default an in-progress pass is resumed; `--retry-failed` reopens failed branches without wiping successful pages. Ctrl+C stops the CLI; the MySQL checkpoint survives.

The existing start.bat worker also drains paused inventory passes. An optional `rond_enrich_contacts => 1` in config/local.php lets that worker drain contacts when inventory is idle. The dedicated command processes contacts when inventory traversal completes. No worker is installed as a system service.

API prefetch is limited to three pages per browser session (up to four with RONDINO_API_BATCH_SIZE). Prefetched data is held briefly in memory; database progress advances only after each page is saved. If the process dies, unsaved pages are fetched again. Five failed waves stop with partial/failed status instead of falsely reporting complete. A MySQL named lock prevents overlapping fetches for a source.

Counts distinguish source records, excluded rows, repeated identities, unique advertisements, and distinct SIM numbers. API totalElements is a source-reported result count, not independently established as distinct SIMs. Offset pagination can change while crawling: completing traversal does not prove a consistent point-in-time snapshot. Historical offers are not deleted or marked sold from absence.

## Contact verification limitation

Contact enrichment is intentionally conservative. It accepts seller telephone data when (1) a JSON-LD Product identifies both the advertisement SKU/productID and SIM with `seller.telephone`, or (2) the detail page contains the listing SIM and `tel:` links inside the public seller card (`اطلاعات تماس فروشنده` → `app-seller-shop-info-card` / `contact-button-advert`, before `مشخصات آگهی`). Script/style bundles are ignored so Angular shell strings cannot fake a match. Detail fetches wait for seller `tel:` hydration. Site footer/social links and unassociated phones remain `unresolved`. Public `/customer` probes returned null `mobileNumber`. This is still not complete coverage for every layout. Unresolved items get a one-day cooldown; request failures use bounded exponential cooldown, with at most five attempts. Controlled sampling: `php scripts/sample-contact-enrich.php 30`. Live filter checks: `node scripts/verify-special-type-filter.mjs` and `php scripts/verify-adapter-special-streams.php`.

## Validation

`node scripts/test-browser-fetch-unit.mjs`, `php scripts/test-rond-ir.php`, `php scripts/test-jalali.php`, `scripts/test-crawl-resume.php` and `scripts/test-incremental-crawl.php` cover parsing, resume/identity, and incremental overlap/price/resume behavior. The MySQL tests refuse a nonempty database and require an explicitly supplied *_test database. GitHub Actions runs these checks without reaching source sites or using production secrets.

No full live crawl or connection to the user's local proxy has been performed by the implementation environment. Existing report totals (49,065 results) are not hardcoded and are not acceptance thresholds.
