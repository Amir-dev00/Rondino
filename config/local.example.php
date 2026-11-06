<?php
/**
 * Copy to local.php and fill in values.
 * local.php must stay outside the public web root and out of Git.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'rondino_simple',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    // Used only for one-time web setup on cPanel. Leave empty on local Windows after start.bat setup.
    'setup_token' => '',
    'app_name' => 'روندینو',
    'auto_refresh_minutes' => 30,
    'http_timeout' => 25,
    'http_user_agent' => 'RondinoSimple/1.0 (+private research team)',
    // Local: higher budgets + worker. cPanel: set crawl_page_budget=1, crawl_use_worker=0.
    // Fastest stable local settings measured: concurrency=12, per_host=4, budget=6.
    // cPanel: crawl_page_budget=1, crawl_concurrency=2, crawl_per_host=1, crawl_use_worker=0.
    'crawl_page_budget' => 6,
    'crawl_concurrency' => 12,
    'crawl_per_host' => 4,
    'crawl_rotate_wave' => 4,
    'crawl_use_worker' => 1,
    // Optional local Playwright proxy for rond.ir (socks5/http). Leave empty on cPanel.
    'rondino_browser_proxy' => '',
    'rondino_browser_deadline_ms' => 180000,
    'rondino_api_batch_size' => 3,
    // Set 1 to let the local worker drain seller-contact enrichment when inventory is idle.
    'rond_enrich_contacts' => 0,
    // Incremental crawl (list API omits update millis; overlap of known IDs + periodic --full).
    // Incremental mode streams each advertSpecialType separately (default VIP then FREE).
    'rondino_incremental_overlap_pages' => 3,
    'rondino_incremental_max_pages' => 50, // per special-type stream
    'rondino_incremental_special_types' => ['VIP', 'FREE'],
    'rondino_full_reconcile_every_hours' => 168,
    // 0 = never re-fetch verified contacts; >0 = days before optional recheck
    'rondino_contact_recheck_days' => 0,
];
