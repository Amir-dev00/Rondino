# روندینو (Rondino)

سامانهٔ خصوصی اطلاعات بازار برای تیم خرید و فروش سیم‌کارت همراه اول **۰۹۱۲**.

پشته: **PHP / Laravel 12 / Blade / MySQL|MariaDB** — مناسب هاست اشتراکی cPanel با Apache. بدون Node، Docker، Redis، صف پایدار، یا مرورگر هدلس.

## نیازمندی‌ها

| مورد | مقدار |
|------|--------|
| PHP | **۸.۲ تا ۸.۴** (تست توسعه با ۸.۵ هم انجام شد) |
| افزونه‌ها | `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `json`, `mbstring`, `openssl`, `pdo`, `pdo_mysql`, `tokenizer`, `xml` |
| پایگاه‌داده | MySQL ۵.۷+ یا MariaDB ۱۰.۳+ (utf8mb4) |
| وب‌سرور | Apache + `mod_rewrite` |
| Cron | PHP CLI همان نسخهٔ وب |

## چیدمان امن روی cPanel

خارج از document root نگه دارید:

- کل اپلیکیشن Laravel (مثلاً `~/rondino/`)
- `.env`، `storage/`، لاگ‌ها، اسنپ‌شات منابع

فقط این‌ها داخل `public_html` (یا ساب‌دامین):

- محتوای پوشهٔ `public/` (ورود `index.php` و `css/` و …)

نمونهٔ `public/index.php` مسیر `__DIR__.'/../'` را به محل واقعی اپ تغییر دهید اگر لازم است، یا از symlink/`Alias` استفاده کنید.

## مسیر ۱ — cPanel با SSH و Composer

```bash
cd ~
git clone <repo> rondino && cd rondino
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# ویرایش .env: DB_* و APP_URL و APP_DEBUG=false
php artisan migrate --force
php artisan sources:sync
php artisan rondino:create-admin you@example.com --name="مدیر" --password='...'
chmod -R ug+rwx storage bootstrap/cache
```

Document root را به `rondino/public` یا کپی/لینک محتوای `public` تنظیم کنید.

### کرون (هر ۵ دقیقه)

```bash
*/5 * * * * /usr/local/bin/php /home/USER/rondino/artisan crawl:scheduled >> /home/USER/rondino/storage/logs/cron.log 2>&1
```

نسخهٔ CLI را چک کنید: `php -v` و در MultiPHP Manager همان نسخهٔ ۸.۲+ را برای Cron انتخاب کنید.

### اولین خزش

```bash
php artisan sources:audit
php artisan crawl:due --force
```

## مسیر ۲ — cPanel بدون Terminal / SSH / Composer

راهنمای کامل فارسی: [`docs/INSTALL-NO-TERMINAL-FA.md`](docs/INSTALL-NO-TERMINAL-FA.md)

خلاصه:

1. در cPanel دیتابیس + کاربر بسازید و `release/rondino-install.sql` را با phpMyAdmin داخل همان دیتابیس خالی Import کنید (utf8mb4؛ بدون migrate روی سرور).
2. `release/rondino-cpanel.zip` را خارج از web root باز کنید؛ محتویات `public/` را به document root بدهید.
3. از `.env.example` فایل `.env` بسازید و `APP_KEY` یکتا را **روی رایانهٔ خود** با `php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"` تولید کنید (کلید مشترک در بسته نیست).
4. اولین مدیر را با کرون یک‌باره و `--password-file` بسازید (رمز داخل کرون/ZIP نباشد) — جزئیات در راهنمای بالا.
5. کرون دائمی:

```text
*/5 * * * * /usr/local/bin/php /home/ACCOUNT/rondino/artisan crawl:scheduled >> /home/ACCOUNT/rondino/storage/logs/cron.log 2>&1
```

نصب‌کنندهٔ وب یا مسیر migration عمومی وجود ندارد — عمداً. Writable: `storage/` و `bootstrap/cache/`.

## فرمان‌های مهم

| فرمان | کار |
|--------|-----|
| `php artisan sources:sync` | ثبت ۱۳ منبع |
| `php artisan sources:audit` | ممیزی دسترسی/parse روی هاست |
| `php artisan crawl:due` | خزش منابع موعددار |
| `php artisan crawl:scheduled` | ورودی کرون (دستی‌های pending + due) |
| `php artisan rondino:create-admin` | ساخت مدیر |

به‌روزرسانی دستی از داشبورد فقط یک job محدود را زمان‌بندی می‌کند و خزش کامل را داخل درخواست وب اجرا نمی‌کند.

## امنیت رابط

- بدون ثبت‌نام عمومی
- احراز هویت نشست Laravel + CSRF + throttle ورود
- همهٔ صفحات داده پشت `auth`
- مدیریت کاربران فقط برای ادمین

## پشتیبان

روزانه از دیتابیس و پوشهٔ `storage/` نسخه بگیرید. اسنپ‌شات خام HTML ممیزی در `storage/app/source-audit/` است و دادهٔ فروشنده را در بستهٔ انتشار قرار ندهید.

## عیب‌یابی

| مشکل | اقدام |
|------|--------|
| ۵۰۰ بعد از آپلود | `APP_KEY`، دسترسی `storage`، نسخه PHP |
| خزش SSL خطا می‌دهد | CA هاست را بررسی کنید؛ فقط در توسعه `RONDINO_HTTP_VERIFY_SSL=false` |
| منبع خالی | `sources:audit --source=SLUG --save-html` |
| صفحه سفید | `storage/logs/laravel.log` |

## تست

```bash
php vendor/bin/phpunit
```

تست‌های adapter مبتنی بر fixture هستند و با تأیید زنده فرق دارند.
