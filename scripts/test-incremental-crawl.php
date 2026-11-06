<?php
declare(strict_types=1);
/**
 * Incremental crawl regressions (disposable *_test MySQL DB only).
 *
 * Covers: new insert, skip duplicate, price update + observation, unchanged no obs,
 * newest-page detection, resume mid-pass, overlap early-stop, full reconcile, repeat sync.
 */
$name = getenv('RONDINO_TEST_DB');
if (!$name || !preg_match('/^[a-zA-Z0-9_]+_test$/', $name)) {
    fwrite(STDERR, "Set RONDINO_TEST_DB to an empty *_test database\n");
    exit(2);
}
require dirname(__DIR__) . '/app/bootstrap.php';
$pdo = new PDO(
    'mysql:host=127.0.0.1;dbname=' . $name,
    'root',
    (string) getenv('RONDINO_TEST_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
if ($pdo->query('SHOW TABLES')->fetch()) {
    throw new RuntimeException('Test database must be empty');
}
$property = new ReflectionProperty(Database::class, 'pdo');
$property->setValue(null, $pdo);
Database::importSqlFile($pdo, dirname(__DIR__) . '/database/rondino-install.sql');
SourceSync::ensureSchema($pdo);

function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "OK $label\n";
}

/** Run waves until terminal status. */
function runPass(CrawlEngine $engine, string $mode, bool $restart, int $budget = 1): array
{
    $result = $engine->advanceSource('rond-ir', $restart, $budget, $mode);
    $restart = false;
    $guard = 0;
    while (!in_array((string) ($result['pass_status'] ?? ''), ['completed', 'partial', 'blocked', 'disabled'], true)) {
        if (++$guard > 100) {
            throw new RuntimeException('pass did not finish');
        }
        if (!empty($result['busy'])) {
            usleep(50000);
        }
        $result = $engine->advanceSource('rond-ir', false, $budget, $mode);
    }
    return $result;
}

/** @var list<list<array<string,mixed>>> $pages */
$pages = [];
class IncrementalFixture extends BaseAdapter
{
    /** @var list<list<array<string,mixed>>> */
    public array $pages = [];
    public function slug(): string { return 'rond-ir'; }
    public function name(): string { return 'IncrementalFixture'; }
    public function baseUrl(): string { return 'https://fixture.invalid'; }
    public function seedUrls(): array { return ['https://fixture.invalid/api?page=0']; }
    public function fetchPage(string $url, int $timeout = 25): ?array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $page = (int) ($q['page'] ?? 0);
        $content = $this->pages[$page] ?? [];
        $body = json_encode([
            'content' => $content,
            'number' => $page,
            'size' => 40,
            'totalPages' => count($this->pages),
            'totalElements' => array_sum(array_map('count', $this->pages)),
            'last' => $page >= count($this->pages) - 1,
            'pageable' => ['pageNumber' => $page],
        ], JSON_UNESCAPED_UNICODE);
        return ['ok' => true, 'status' => 200, 'body' => $body, 'final_url' => $url, 'error' => null];
    }
    public function parseListPayload(string $html, string $pageUrl): array
    {
        $json = json_decode($html, true);
        $listings = [];
        $ids = [];
        foreach ($json['content'] as $row) {
            $ids[] = (string) $row['code'];
            $listings[] = $this->listing([
                'external_id' => (string) $row['code'],
                'sim_number' => (string) $row['sim'],
                'canonical_url' => 'https://fixture.invalid/Sims/' . $row['sim'],
                'price_amount' => (int) $row['price'],
                'price_type' => 'fixed',
                'price_raw' => (string) $row['price'],
                'condition_normalized' => 'unknown',
                'seller_key' => 'seller-' . $row['code'],
            ]);
        }
        return [
            'listings' => $listings,
            'source_record_ids' => $ids,
            'source_record_count' => count($ids),
            'excluded' => 0,
            'coverage' => [
                'scope' => 'listing_api_0912',
                'is_complete_inventory' => true,
                'total_pages' => (int) $json['totalPages'],
                'total_elements' => (int) $json['totalElements'],
                'page_number' => (int) $json['number'],
                'page_size' => 40,
            ],
        ];
    }
    public function parseListPage(string $html, string $url): array
    {
        return $this->parseListPayload($html, $url)['listings'];
    }
    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        $json = json_decode($html, true);
        if (($json['last'] ?? false) === true) {
            return null;
        }
        $next = (int) $json['number'] + 1;
        return 'https://fixture.invalid/api?page=' . $next;
    }
}

$adapter = new IncrementalFixture();
Registry::all();
$map = new ReflectionProperty(Registry::class, 'map');
$all = $map->getValue();
$all['rond-ir'] = $adapter;
$map->setValue(null, $all);

$mk = static fn (string $code, string $sim, int $price): array => [
    'code' => $code,
    'sim' => $sim,
    'price' => $price,
];

// --- Full reconcile seeds inventory ---
$adapter->pages = [
    [$mk('A1', '09121111111', 1000), $mk('A2', '09121111112', 2000)],
    [$mk('A3', '09121111113', 3000), $mk('A4', '09121111114', 4000)],
    [$mk('A5', '09121111115', 5000)],
];
$sid = (int) $pdo->query("SELECT id FROM sources WHERE slug='rond-ir'")->fetchColumn();
$engine = new CrawlEngine();
$r = runPass($engine, 'full', true);
check($r['pass_status'] === 'completed', '1 full reconcile completes');
check((int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() === 5, '1 five offers inserted');
$obsAfterFull = (int) $pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn();
check($obsAfterFull === 5, '1 price observations for inserts');

// --- Incremental: all known pages -> overlap early stop ---
$pdo->exec("UPDATE source_sync_state SET overlap_pages=2, incremental_max_pages=20");
$adapter->pages = [
    [$mk('A1', '09121111111', 1000), $mk('A2', '09121111112', 2000)], // known
    [$mk('A3', '09121111113', 3000), $mk('A4', '09121111114', 4000)], // known -> overlap=2
    [$mk('A5', '09121111115', 5000)], // should NOT be fetched
];
$engine = new CrawlEngine();
$r = runPass($engine, 'incremental', true);
check($r['pass_status'] === 'completed', '2 incremental overlap completes');
$state = json_decode((string) $pdo->query("SELECT branch_state FROM crawl_passes WHERE source_id={$sid}")->fetchColumn(), true);
check(($state['stop_reason'] ?? '') === 'incremental_overlap_reached', '2 stop_reason overlap');
check((int) ($state['pages_fetched'] ?? 0) === 2, '2 only overlap pages fetched');
check((int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() === 5, '2 no duplicate offers');
check((int) $pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn() === $obsAfterFull, '2 unchanged prices no new observations');

// --- New listing at beginning of pagination ---
$adapter->pages = [
    [$mk('NEW1', '09121111116', 9000), $mk('A1', '09121111111', 1000)],
    [$mk('A2', '09121111112', 2000), $mk('A3', '09121111113', 3000)],
    [$mk('A4', '09121111114', 4000), $mk('A5', '09121111115', 5000)],
];
$engine = new CrawlEngine();
$r = runPass($engine, 'incremental', true);
check($r['pass_status'] === 'completed', '3 incremental with new head completes');
check((int) $pdo->query("SELECT COUNT(*) FROM offers WHERE external_id='NEW1'")->fetchColumn() === 1, '3 new listing inserted');
check((int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() === 6, '3 total six offers');

// --- Price change on known listing ---
$adapter->pages = [
    [$mk('A1', '09121111111', 1500), $mk('A2', '09121111112', 2000)], // A1 price changed
    [$mk('A3', '09121111113', 3000), $mk('A4', '09121111114', 4000)],
];
$beforeObs = (int) $pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn();
$engine = new CrawlEngine();
$r = runPass($engine, 'incremental', true);
check($r['pass_status'] === 'completed', '4 price-change incremental completes');
$price = (int) $pdo->query("SELECT price_amount FROM offers WHERE external_id='A1'")->fetchColumn();
check($price === 1500, '4 offer price updated');
check((int) $pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn() === $beforeObs + 1, '4 new price observation recorded');

// --- Resume mid incremental ---
$adapter->pages = [
    [$mk('MID1', '09121111117', 1100)],
    [$mk('A1', '09121111111', 1500)],
    [$mk('A2', '09121111112', 2000)],
];
$pdo->exec("UPDATE source_sync_state SET overlap_pages=2, incremental_max_pages=20");
$engine = new CrawlEngine();
$first = $engine->advanceSource('rond-ir', true, 1, 'incremental');
check($first['pass_status'] === 'paused', '5 mid-pass pauses');
$pass = $pdo->query("SELECT pages_fetched, status FROM crawl_passes WHERE source_id={$sid}")->fetch();
check((int) $pass['pages_fetched'] === 1, '5 one page checkpointed');
$engine2 = new CrawlEngine();
$second = runPass($engine2, 'incremental', false);
check($second['pass_status'] === 'completed', '5 resume reaches completion');
check((int) $pdo->query("SELECT COUNT(*) FROM offers WHERE external_id='MID1'")->fetchColumn() === 1, '5 mid listing persisted');

// --- Full reconcile still works ---
$adapter->pages = [
    [$mk('A1', '09121111111', 1500)],
    [$mk('A2', '09121111112', 2000)],
];
$engine = new CrawlEngine();
$r = runPass($engine, 'full', true);
check($r['pass_status'] === 'completed', '6 full reconcile completes');
$sync = SourceSync::load($pdo, $sid);
check(($sync['mode_last'] ?? '') === 'full', '6 sync mode_last full');
check(!empty($sync['last_full_completed_at']), '6 last_full_completed_at set');

// --- Repeat incremental without corruption ---
$offersBefore = (int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn();
$obsBefore = (int) $pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn();
$adapter->pages = [
    [$mk('A1', '09121111111', 1500)],
    [$mk('A2', '09121111112', 2000)],
];
$pdo->exec("UPDATE source_sync_state SET overlap_pages=2");
$engine = new CrawlEngine();
$r = runPass($engine, 'incremental', true);
check($r['pass_status'] === 'completed', '7 repeat incremental completes');
check((int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() === $offersBefore, '7 offer count stable');
check((int) $pdo->query('SELECT COUNT(*) FROM price_observations')->fetchColumn() === $obsBefore, '7 observations stable');

// --- Per-special-type streams: new FREE must not hide behind known VIP pages ---
class MultiStreamFixture extends IncrementalFixture
{
    /** @var array<string,list<list<array<string,mixed>>>> */
    public array $bySpecial = [];
    public function seedUrls(): array
    {
        return [
            'https://fixture.invalid/api?page=0&rondinoSpecial=VIP',
            'https://fixture.invalid/api?page=0&rondinoSpecial=FREE',
        ];
    }
    public function fetchPage(string $url, int $timeout = 25): ?array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $page = (int) ($q['page'] ?? 0);
        $special = (string) ($q['rondinoSpecial'] ?? 'VIP');
        $stream = $this->bySpecial[$special] ?? [];
        $content = $stream[$page] ?? [];
        $body = json_encode([
            'content' => $content,
            'number' => $page,
            'size' => 40,
            'totalPages' => max(1, count($stream)),
            'totalElements' => array_sum(array_map('count', $stream)),
            'last' => $page >= count($stream) - 1,
            'pageable' => ['pageNumber' => $page],
        ], JSON_UNESCAPED_UNICODE);
        return ['ok' => true, 'status' => 200, 'body' => $body, 'final_url' => $url, 'error' => null];
    }
    public function nextPageUrl(string $html, string $pageUrl, int $pageIndex): ?string
    {
        $json = json_decode($html, true);
        if (($json['last'] ?? false) === true) {
            return null;
        }
        parse_str((string) parse_url($pageUrl, PHP_URL_QUERY), $q);
        $special = (string) ($q['rondinoSpecial'] ?? 'VIP');
        $next = (int) $json['number'] + 1;
        return 'https://fixture.invalid/api?page=' . $next . '&rondinoSpecial=' . rawurlencode($special);
    }
}

$multi = new MultiStreamFixture();
$all['rond-ir'] = $multi;
$map->setValue(null, $all);
// Seed DB as if VIP inventory was already known; FREE has a brand-new head listing.
$pdo->exec('DELETE FROM price_observations');
$pdo->exec('DELETE FROM offers');
$pdo->exec('DELETE FROM crawl_passes');
$multi->bySpecial = [
    'VIP' => [
        [$mk('VIP1', '09121111111', 1000), $mk('VIP2', '09121111112', 2000)],
    ],
    'FREE' => [
        [$mk('FREE1', '09121111113', 3000), $mk('FREE1b', '09121111115', 3100)],
    ],
];
$pdo->exec("UPDATE source_sync_state SET overlap_pages=2, incremental_max_pages=20");
$engine = new CrawlEngine();
$r = runPass($engine, 'full', true);
check($r['pass_status'] === 'completed', '8 multi-stream full seeds inventory');
check((int) $pdo->query('SELECT COUNT(*) FROM offers')->fetchColumn() === 4, '8 four offers after full');

$multi->bySpecial = [
    'VIP' => [
        [$mk('VIP1', '09121111111', 1000)], // known
        [$mk('VIP2', '09121111112', 2000)], // known -> VIP overlap=2
        [$mk('VIP_DEEP', '09121111199', 9999)], // beyond overlap; must not block FREE discovery
    ],
    'FREE' => [
        [$mk('FREE_NEW', '09121111114', 4000)], // new at FREE head
        [$mk('FREE1', '09121111113', 3000)], // known
        [$mk('FREE1b', '09121111115', 3100)], // known -> FREE overlap=2
    ],
];

$engine = new CrawlEngine();
$r = runPass($engine, 'incremental', true);
check($r['pass_status'] === 'completed', '8 incremental multi-stream completes');
check((int) $pdo->query("SELECT COUNT(*) FROM offers WHERE external_id='FREE_NEW'")->fetchColumn() === 1, '8 new FREE listing inserted despite known VIP pages');
check((int) $pdo->query("SELECT COUNT(*) FROM offers WHERE external_id='VIP_DEEP'")->fetchColumn() === 0, '8 deep VIP beyond overlap not fetched');
$state = json_decode((string) $pdo->query("SELECT branch_state FROM crawl_passes WHERE source_id={$sid} ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
$reasons = (string) ($state['stop_reason'] ?? '');
check(str_contains($reasons, 'incremental_overlap_reached'), '8 stop includes overlap');
check(count($state['branches'] ?? []) === 2, '8 two special-type branches');

echo "All incremental crawl regressions passed\n";
