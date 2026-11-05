<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

$configPath = config_path();
$route = get_q('r', 'market') ?: 'market';

// cPanel one-time setup (before config may fully work for admin creation)
if ($route === 'setup') {
    require dirname(__DIR__) . '/views/setup.php';
    exit;
}

if (!is_file($configPath)) {
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Tahoma;padding:2rem">';
    echo '<h1>پیکربندی یافت نشد</h1><p>روی ویندوز <code>start.bat</code> را اجرا کنید. روی cPanel فایل <code>config/local.php</code> را بسازید.</p></body></html>';
    exit;
}

try {
    Database::pdo();
} catch (Throwable $e) {
    http_response_code(503);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Tahoma;padding:2rem">';
    echo '<h1>اتصال به MySQL برقرار نشد</h1><p>' . e($e->getMessage()) . '</p>';
    echo '<p>سرویس MySQL را روشن کنید و تنظیمات <code>config/local.php</code> را بررسی کنید.</p></body></html>';
    exit;
}

if ($route === 'login') {
    if (request_is_post()) {
        Csrf::verify(post('_csrf'));
        $email = (string) post('email', '');
        $password = (string) post('password', '');
        if (Auth::attempt($email, $password)) {
            redirect('/?r=market');
        }
        flash_set('error', 'ایمیل یا رمز عبور نادرست است.');
        redirect('/?r=login');
    }
    require dirname(__DIR__) . '/views/login.php';
    exit;
}

if ($route === 'logout') {
    Auth::logout();
    redirect('/?r=login');
}

$user = Auth::requireLogin();

if ($route === 'refresh' && request_is_post()) {
    Csrf::verify(post('_csrf'));
    // Release session lock so parallel AJAX / status polls are not serialized
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: application/json; charset=utf-8');
    $slug = (string) post('slug', '');
    $mode = (string) post('mode', 'one');
    $crawler = new Crawler();
    $useWorker = (int) config('crawl_use_worker', 0) === 1;

    if ($mode === 'status') {
        $job = CrawlJob::latest();
        echo json_encode([
            'ok' => true,
            'worker' => $useWorker,
            'job' => $job,
            'all_progress' => $crawler->allProgress(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Local worker path: browser only enqueues / attaches; CLI worker crawls
    if ($useWorker && ($mode === 'all' || $mode === 'job')) {
        $startFresh = post('start', '') === '1' || $mode === 'job';
        $job = CrawlJob::activeOrCreate($startFresh ? 'full' : 'resume', $startFresh);
        echo json_encode([
            'ok' => true,
            'worker' => true,
            'done' => in_array((string) ($job['status'] ?? ''), ['completed', 'failed', 'cancelled'], true),
            'job' => $job,
            'message' => 'کار به کارگر محلی سپرده شد؛ پیشرفت از MySQL خوانده می‌شود.',
            'all_progress' => $crawler->allProgress(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($mode === 'all') {
        $startFresh = post('start', '') === '1';
        $result = $crawler->runRotatedBatch($startFresh);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    $restart = post('restart', '1') === '1' && post('continue', '') !== '1';
    if (post('continue', '') === '1') {
        $restart = false;
    }
    // Single-source: still run in-request (works on cPanel and local)
    $result = $crawler->runBatch($slug, $restart);
    echo json_encode(['done' => in_array($result['pass_status'] ?? '', ['completed', 'blocked'], true), 'slug' => $slug, 'result' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($route === 'deal' && request_is_post()) {
    Csrf::verify(post('_csrf'));
    $sim = Digits::extractSim0912((string) post('sim_number', '')) ?? '';
    $type = post('deal_type', '') === 'sell' ? 'sell' : 'buy';
    $amount = (int) preg_replace('/\D+/', '', Digits::normalize((string) post('amount_toman', '0')));
    $dateFa = (string) post('deal_date', '');
    $note = mb_substr((string) post('note', ''), 0, 500);
    $_SESSION['_deal_date_fa'] = $dateFa;
    $_SESSION['_deal_sim'] = (string) post('sim_number', '');
    $parsedDate = Jalali::parseDateInput($dateFa);
    if (!$parsedDate['ok']) {
        flash_set('error', $parsedDate['error'] ?? 'تاریخ نامعتبر است.');
        redirect('/?r=deals');
    }
    if (!Digits::isValid0912($sim) || $amount <= 0) {
        flash_set('error', 'شماره یا مبلغ معتبر نیست.');
        redirect('/?r=deals');
    }
    $stmt = Database::pdo()->prepare(
        'INSERT INTO team_deals (user_id, sim_number, deal_type, amount_toman, deal_date, note, created_at) VALUES (?,?,?,?,?,?,?)'
    );
    $stmt->execute([(int) $user['id'], $sim, $type, $amount, $parsedDate['ymd'], $note !== '' ? $note : null, now_dt()]);
    unset($_SESSION['_deal_date_fa'], $_SESSION['_deal_sim']);
    flash_set('ok', 'معامله ثبت شد.');
    redirect('/?r=deals');
}

$view = match ($route) {
    'estimate' => 'estimate.php',
    'deals' => 'deals.php',
    'sources' => 'sources.php',
    default => 'market.php',
};

require dirname(__DIR__) . '/views/' . $view;
