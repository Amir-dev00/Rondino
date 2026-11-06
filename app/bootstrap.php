<?php

declare(strict_types=1);

require_once __DIR__ . '/Digits.php';
require_once __DIR__ . '/Jalali.php';
require_once __DIR__ . '/Helpers.php';
require_once __DIR__ . '/Price.php';
require_once __DIR__ . '/Condition.php';
require_once __DIR__ . '/Dom.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Pagination.php';
require_once __DIR__ . '/Inventory.php';
require_once __DIR__ . '/CrawlMetrics.php';
require_once __DIR__ . '/OfferStore.php';
require_once __DIR__ . '/Estimator.php';
require_once __DIR__ . '/adapters/AdapterInterface.php';
require_once __DIR__ . '/adapters/BaseAdapter.php';
require_once __DIR__ . '/adapters/Registry.php';
require_once __DIR__ . '/SourceSync.php';
require_once __DIR__ . '/CrawlEngine.php';
require_once __DIR__ . '/CrawlJob.php';
require_once __DIR__ . '/Crawler.php';
require_once __DIR__ . '/RondContacts.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

date_default_timezone_set('UTC');

