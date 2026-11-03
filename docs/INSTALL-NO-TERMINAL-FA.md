# نصب روندینو روی cPanel بدون Terminal / SSH

این راهنما فقط با **File Manager**، **MySQL Databases**، **phpMyAdmin** و **Cron Jobs** انجام می‌شود.

فایل‌های لازم:

- `release/rondino-cpanel.zip` — بستهٔ اپ (شامل `vendor`)
- `release/rondino-install.sql` — اسکیما + ۱۳ منبع (جدا یا داخل ZIP)

## ۱) ساخت پایگاه‌داده

در cPanel → **MySQL® Databases**:

1. یک دیتابیس خالی بسازید (مثلاً `user_rondino`).
2. یک کاربر MySQL بسازید و رمز قوی بگذارید.
3. کاربر را با **ALL PRIVILEGES** به همان دیتابیس وصل کنید.
4. نام کامل دیتابیس، نام کاربر و رمز را یادداشت کنید.

## ۲) وارد کردن SQL در phpMyAdmin

1. phpMyAdmin را باز کنید و **همان دیتابیس خالی** را از سمت چپ انتخاب کنید.
2. زبانهٔ **Import** → فایل `rondino-install.sql` را انتخاب کنید.
3. Character set: `utf8mb4` (در صورت وجود گزینه).
4. Go / Import.

پس از موفقیت باید جدول‌ها (از جمله `users`, `sources`, `offers`, `migrations`) و **۱۳ ردیف در `sources`** دیده شوند. جدول `users` خالی است — عمداً.

## ۳) آپلود اپلیکیشن

1. `rondino-cpanel.zip` را آپلود و Extract کنید، ترجیحاً **خارج از** `public_html`، مثلاً:
   - `/home/ACCOUNT/rondino/`
2. محتویات پوشهٔ `public/` را به document root کپی کنید:
   - یا کل `public_html` را با فایل‌های داخل `public` جایگزین کنید،
   - یا ساب‌دامین بسازید و document root را روی `.../rondino/public` بگذارید.
3. در `public/index.php` (داخل web root) مسیر bootstrap را اگر لازم است اصلاح کنید تا به پوشهٔ اصلی اپ (`/home/ACCOUNT/rondino`) برسد. در بستهٔ استاندارد معمولاً `__DIR__.'/../'` کافی است وقتی `public` همان زیرفولدر اپ است.

پوشه‌های قابل‌نوشتن (مجوز خواندن/نوشتن برای PHP):

- `storage/`
- `bootstrap/cache/`

## ۴) ساخت `.env` و کلید APP_KEY

1. در File Manager از `.env.example` یک کپی به نام `.env` بسازید (کنار `artisan`).
2. حداقل این‌ها را پر کنید:

```env
APP_NAME=Rondino
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-DOMAIN

APP_LOCALE=fa
APP_FALLBACK_LOCALE=fa

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=user_rondino
DB_USERNAME=user_rondino
DB_PASSWORD=YOUR_DB_PASSWORD

SESSION_DRIVER=file
QUEUE_CONNECTION=sync
CACHE_STORE=file
RONDINO_HTTP_VERIFY_SSL=true
```

3. **APP_KEY را در بستهٔ انتشار قرار ندهید.** روی رایانهٔ خودتان (با PHP نصب‌شده) یک کلید یکتا بسازید:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)), PHP_EOL;"
```

خروجی شبیه `base64:...` را در `.env` مقابل `APP_KEY=` بگذارید. هر نصب باید کلید جدا داشته باشد.

## ۵) ساخت اولین مدیر — بدون ترمینال و بدون رمز در کرون

فرمان موجود (غیرتعاملی با فایل رمز):

```text
php artisan rondino:create-admin {email} --name=مدیر --password-file=/absolute/path/to/secret
```

### مراحل امن

1. در File Manager، **خارج از web root** فایلی بسازید مثلاً:
   - `/home/ACCOUNT/rondino-admin.secret`
2. فقط **یک خط** داخل آن بنویسید: رمز عبور مدیر (حداقل ۱۰ کاراکتر). هیچ چیز دیگری نباشد.
3. در cPanel → **Cron Jobs** یک کرون **یک‌باره** بسازید (مثلاً برای دقیقه‌ای نزدیک):

```text
/usr/local/bin/php /home/ACCOUNT/rondino/artisan rondino:create-admin you@example.com --name="مدیر" --password-file=/home/ACCOUNT/rondino-admin.secret >> /home/ACCOUNT/rondino/storage/logs/admin-bootstrap.log 2>&1
```

جایگزین کنید:

- `/usr/local/bin/php` → باینری PHP CLI هاست (MultiPHP / Select PHP Version)
- `/home/ACCOUNT/rondino` → مسیر واقعی اپ
- ایمیل مدیر

4. پس از اجرای کرون، لاگ را بخوانید؛ باید شبیه این باشد: `Admin ready: you@example.com`
5. با همان ایمیل/رمز وارد سایت شوید.
6. **فوراً** فایل `rondino-admin.secret` را حذف کنید و کرون یک‌باره را پاک کنید.
7. لاگ `admin-bootstrap.log` را هم پاک یا خالی کنید (رمز داخل فرمان نبوده، ولی لاگ را نگه ندارید).

رمز را در متن کرون، در ZIP، یا در `.env` نگذارید.

## ۶) کرون دائمی خزش

هر ۵ دقیقه:

```text
*/5 * * * * /usr/local/bin/php /home/ACCOUNT/rondino/artisan crawl:scheduled >> /home/ACCOUNT/rondino/storage/logs/cron.log 2>&1
```

اولین اجرا پس از چند دقیقه آگهی‌های زنده را از منابع قابل‌دسترس پر می‌کند. دادهٔ نمایشی در SQL نیست.

برای یک خزش فوری (اختیاری، یک‌باره):

```text
/usr/local/bin/php /home/ACCOUNT/rondino/artisan crawl:due --force >> /home/ACCOUNT/rondino/storage/logs/cron.log 2>&1
```

## ۷) چک‌لیست سریع

- [ ] دیتابیس انتخاب‌شده در phpMyAdmin همان دیتابیس `.env` است
- [ ] ۱۳ منبع در جدول `sources`
- [ ] `users` قبل از کرون ادمین خالی است
- [ ] ورود با حساب مدیر کار می‌کند
- [ ] کرون `crawl:scheduled` فعال است
- [ ] فایل رمز ادمین و کرون یک‌باره حذف شده‌اند
