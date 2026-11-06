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
| **Incremental** (default after a verified full inventory) | `--incremental` or no flags when idle | Fetches newest API pages (sort: `advertSpecialType`, `lastUpdateDateMillis`, `createDateMillis`). Upserts new/changed rows. Stops after `rondino_incremental_overlap_pages` consecutive pages whose `external_id` values were **all already in the DB**. Caps at `rondino_incremental_max_pages`. |
| **Full reconcile** | `--full` or `--restart` | Traverses all inventory pages. Required periodically (`rondino_full_reconcile_every_hours`, default 168). |

**Verified API limitation:** list JSON rows do **not** include `lastUpdateDateMillis` / `createDateMillis` fields (only the sort clause references them). There is no confirmed `updatedSince` body filter. Therefore incremental early-stop uses **known-`external_id` overlap**, not a server-side date watermark. Price/status changes are applied for every listing that appears in the scanned prefix (recent updates surface toward the front of the sort). Deep catalog changes that never re-sort into the scanned window require `--full`.

Sync metadata lives in `source_sync_state` (see `database/rondino-upgrade-source-sync.sql`). Deduplication key remains `(source_id, external_id)`. Price history uses `price_observations` only when price amount/type changes.

Set `rondino_browser_proxy` in `config/local.php` (example: `socks5://127.0.0.1:10808`) or export `RONDINO_BROWSER_PROXY`. By default an in-progress pass is resumed; `--retry-failed` reopens failed branches without wiping successful pages. Ctrl+C stops the CLI; the MySQL checkpoint survives.

The existing start.bat worker also drains paused inventory passes. An optional `rond_enrich_contacts => 1` in config/local.php lets that worker drain contacts when inventory is idle. The dedicated command processes contacts when inventory traversal completes. No worker is installed as a system service.

API prefetch is limited to three pages per browser session (up to four with RONDINO_API_BATCH_SIZE). Prefetched data is held briefly in memory; database progress advances only after each page is saved. If the process dies, unsaved pages are fetched again. Five failed waves stop with partial/failed status instead of falsely reporting complete. A MySQL named lock prevents overlapping fetches for a source.

Counts distinguish source records, excluded rows, repeated identities, unique advertisements, and distinct SIM numbers. API totalElements is a source-reported result count, not independently established as distinct SIMs. Offset pagination can change while crawling: completing traversal does not prove a consistent point-in-time snapshot. Historical offers are not deleted or marked sold from absence.

## Contact verification limitation

Contact enrichment is intentionally conservative. It accepts seller telephone data only when a structured Product identifies both the advertisement and SIM. Global tel links, unassociated seller data and ambiguous layouts remain `unresolved` in rond_contact_checks. The live HTML selector/association for every Rond detail layout has NOT been verified in this environment. This implementation must not be described as complete contact coverage. Unresolved items get a one-day cooldown; request failures use bounded exponential cooldown, with at most five attempts. The table is created by the worker; it may also be created by importing database/rondino-upgrade-rond-contacts.sql in phpMyAdmin.

## Validation

`node scripts/test-browser-fetch-unit.mjs`, `php scripts/test-rond-ir.php`, `php scripts/test-jalali.php`, `scripts/test-crawl-resume.php` and `scripts/test-incremental-crawl.php` cover parsing, resume/identity, and incremental overlap/price/resume behavior. The MySQL tests refuse a nonempty database and require an explicitly supplied *_test database. GitHub Actions runs these checks without reaching source sites or using production secrets.

No full live crawl or connection to the user's local proxy has been performed by the implementation environment. Existing report totals (49,065 results) are not hardcoded and are not acceptance thresholds.
