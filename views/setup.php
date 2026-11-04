<?php
require_once dirname(__DIR__) . '/app/bootstrap.php';

$cfg = load_config();
$tokenExpected = (string) ($cfg['setup_token'] ?? '');
$token = (string) (get_q('token', '') ?? post('token', '') ?? '');
$error = null;
$done = false;

if ($tokenExpected === '' || !hash_equals($tokenExpected, $token)) {
    http_response_code(403);
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="utf-8"><body style="font-family:Tahoma;padding:2rem">';
    echo '<h1>دسترسی راه‌اندازی مجاز نیست</h1><p>توکن راه‌اندازی در <code>config/local.php</code> را بررسی کنید.</p></body></html>';
    exit;
}

try {
    Database::pdo();
    if (Auth::adminExists()) {
        $error = 'مدیر قبلاً ساخته شده است. راه‌اندازی وب غیرفعال است.';
    } elseif (request_is_post()) {
        Csrf::verify(post('_csrf'));
        $name = (string) post('name', '');
        $email = (string) post('email', '');
        $pass = (string) post('password', '');
        $pass2 = (string) post('password2', '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
            $error = 'نام، ایمیل معتبر و رمز حداقل ۸ کاراکتری لازم است.';
        } elseif ($pass !== $pass2) {
            $error = 'تکرار رمز عبور یکسان نیست.';
        } else {
            Auth::createAdmin($name, $email, $pass);
            // Disable setup by clearing token
            $cfg['setup_token'] = '';
            $export = "<?php\nreturn " . var_export($cfg, true) . ";\n";
            file_put_contents(config_path(), $export);
            $done = true;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?><!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>راه‌اندازی اولیه</title>
  <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<main class="wrap">
  <div class="panel login-card">
    <h1>ساخت اولین مدیر تیم</h1>
    <?php if ($done): ?>
      <p class="flash ok">مدیر ساخته شد و راه‌اندازی غیرفعال شد. اکنون وارد شوید.</p>
      <a class="btn" href="/?r=login">رفتن به ورود</a>
    <?php elseif ($error): ?>
      <p class="flash error"><?= e($error) ?></p>
      <?php if (!Auth::adminExists()): ?>
      <p class="muted">در صورت نیاز تنظیمات پایگاه را بررسی کنید.</p>
      <?php endif; ?>
    <?php else: ?>
      <form method="post" class="stack">
        <?= Csrf::field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <label>نام<input name="name" required></label>
        <label>ایمیل<input type="email" name="email" required></label>
        <label>رمز عبور<input type="password" name="password" required minlength="8"></label>
        <label>تکرار رمز<input type="password" name="password2" required minlength="8"></label>
        <button type="submit">ایجاد مدیر</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
