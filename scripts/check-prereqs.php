<?php

declare(strict_types=1);

$errors = [];

if (PHP_VERSION_ID < 80200) {
    $errors[] = 'نسخه PHP باید ۸٫۲ یا بالاتر باشد. نسخه فعلی: ' . PHP_VERSION;
}

$required = ['pdo', 'pdo_mysql', 'curl', 'dom', 'mbstring', 'json', 'session'];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $errors[] = "افزونه PHP لازم نیست: {$ext}. در php.ini آن را فعال کنید.";
    }
}

if ($errors) {
    fwrite(STDERR, "پیش‌نیازها کامل نیستند:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP " . PHP_VERSION . " و افزونه‌های لازم آماده‌اند.\n");
exit(0);
