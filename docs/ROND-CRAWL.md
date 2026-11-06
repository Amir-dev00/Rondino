# Rond inventory crawl (local Windows)

Use PHP, MySQL and the existing optional Playwright installation. This browser route has not been verified on cPanel.

From the project directory, in Windows cmd:

```bat
set RONDINO_BROWSER_PROXY=socks5://127.0.0.1:10808
php scripts/crawl-rond.php --contacts
```

The proxy is an example from the user's working machine, not a hardcoded requirement. Configure the actual available proxy. By default the command resumes the stored cursor; `--restart` explicitly starts a new pass. `--retry-failed` reopens exhausted failed branches without resetting completed pages. Ctrl+C stops the CLI; the database checkpoint survives. After an abrupt process stop, the stored lease may take up to three minutes to expire.

The existing start.bat worker also drains paused inventory passes. An optional `rond_enrich_contacts => 1` in config/local.php lets that worker drain contacts when inventory is idle. The dedicated command processes contacts when inventory traversal completes. No worker is installed as a system service.

API prefetch is limited to three pages per browser session (up to four with RONDINO_API_BATCH_SIZE). Prefetched data is held briefly in memory; database progress advances only after each page is saved. If the process dies, unsaved pages are fetched again. Five failed waves stop with partial/failed status instead of falsely reporting complete. A MySQL named lock prevents overlapping fetches for a source.

Counts distinguish source records, excluded rows, repeated identities, unique advertisements, and distinct SIM numbers. API totalElements is a source-reported result count, not independently established as distinct SIMs. Offset pagination can change while crawling: completing traversal does not prove a consistent point-in-time snapshot. Historical offers are not deleted or marked sold from absence.

## Contact verification limitation

Contact enrichment is intentionally conservative. It accepts seller telephone data only when a structured Product identifies both the advertisement and SIM. Global tel links, unassociated seller data and ambiguous layouts remain `unresolved` in rond_contact_checks. The live HTML selector/association for every Rond detail layout has NOT been verified in this environment. This implementation must not be described as complete contact coverage. Unresolved items get a one-day cooldown; request failures use bounded exponential cooldown, with at most five attempts. The table is created by the worker; it may also be created by importing database/rondino-upgrade-rond-contacts.sql in phpMyAdmin.

## Validation

`node scripts/test-browser-fetch-unit.mjs`, `php scripts/test-rond-ir.php`, `php scripts/test-jalali.php` and `scripts/test-crawl-resume.php` cover parsing and MySQL resume/identity behavior. The MySQL test refuses a nonempty database and requires an explicitly supplied *_test database. GitHub Actions runs these checks without reaching source sites or using production secrets.

No full live crawl or connection to the user's local proxy has been performed by the implementation environment. Existing report totals (49,065 results) are not hardcoded and are not acceptance thresholds.
