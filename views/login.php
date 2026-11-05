<?php
ob_start();
?>
<div class="panel login-card">
  <div class="page-head" style="margin-bottom:1rem">
    <div>
      <h1>ورود تیم</h1>
      <p class="page-meta">این برنامه خصوصی است. فقط اعضای تیم وارد شوند.</p>
    </div>
  </div>
  <form method="post" action="/?r=login" class="form-grid">
    <?= Csrf::field() ?>
    <label>ایمیل
      <input type="email" name="email" required autocomplete="username">
    </label>
    <label>رمز عبور
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button type="submit">ورود</button>
  </form>
</div>
<?php
$content = ob_get_clean();
$title = 'ورود — روندینو';
$active = '';
require __DIR__ . '/layout.php';
