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

## مسیر ۲ — cPanel بدون SSH / بدون Composer

1. روی ماشین توسعه: `composer install --no-dev` و `php artisan key:generate` (کلید را بعداً روی سرور عوض کنید).
2. بستهٔ آمادهٔ `release/rondino-cpanel.zip` را آپلود و از حالت فشرده خارج کنید (مثلاً در `~/rondino`).
3. فایل `.env` را از `.env.example` بسازید؛ `APP_KEY` را با یک کلید امن پر کنید (از محیط توسعه کپی نکنید اگر بسته عمومی است).
4. دیتابیس MySQL را در cPanel بسازید و مشخصات را در `.env` بگذارید.
5. محتویات `public/` را در document root قرار دهید و در `index.php` مسیر bootstrap را به پوشهٔ اپ اصلاح کنید.
6. از **Terminal/SSH محدود** یا اسکریپت یک‌بارهٔ محلی با PHP CLI همان هاست اجرا کنید:
   - `php artisan migrate --force`
   - `php artisan sources:sync`
   - `php artisan rondino:create-admin ...`
7. نصب‌کننده یا مسیر migration عمومی وجود ندارد — عمداً.

Writable: `storage/` و `bootstrap/cache/`.

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
