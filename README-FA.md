# روندینو (نسخه ساده)

برنامهٔ خصوصی وب برای تیم تحقیق و معاملهٔ سیم‌کارت‌های همراه اول ۰۹۱۲.

پشته: PHP 8.2+، MySQL (PDO)، HTML سمت سرور، یک فایل CSS، جاوااسکریپت کمینه. بدون Laravel، Composer، Node، Docker.

## مسیرهای مهم

- `start.bat` — اجرای روزانه روی ویندوز
- `database/rondino-install.sql` — نصب پایگاه در phpMyAdmin (نصب تازه)
- `database/rondino-upgrade-crawl-progress.sql` — ارتقای غیرتخریبی برای پایگاه موجود (جدول `crawl_passes`)
- `database/rondino-upgrade-crawl-speed.sql` — ارتقای سرعت (ستون `branch_state` + جدول `crawl_jobs`)
- `release/rondino-simple-cpanel.zip` — بستهٔ cPanel

---

## راهنمای ویندوز

`.\start.bat`

1. یک‌بار PHP 8.2+ و MySQL Server را نصب کنید و `php` را در PATH بگذارید.
2. افزونه‌های لازم: `pdo_mysql`, `curl`, `dom`, `mbstring`.
3. پوشهٔ پروژه را در PowerShell باز کنید و دستور بالا را اجرا کنید.
4. در اولین اجرا اتصال MySQL و ساخت مدیر تیم پرسیده می‌شود؛ تنظیمات در `config/local.php` ذخیره می‌شود (خارج از `public/` و خارج از Git).
5. اجراهای بعدی همان داده را نگه می‌دارند، سرور روی `127.0.0.1` بالا می‌آید و مرورگر باز می‌شود.
6. توقف: `Ctrl+C` در همان پنجره.

---

## راهنمای cPanel (فقط File Manager و phpMyAdmin)

1. در cPanel یک پایگاه MySQL و کاربر بسازید و کاربر را به پایگاه وصل کنید.
2. در phpMyAdmin: اگر پایگاه خالی است `database/rondino-install.sql` را Import کنید. اگر از قبل داده دارید، به‌ترتیب `database/rondino-upgrade-crawl-progress.sql` و سپس `database/rondino-upgrade-crawl-speed.sql` را Import کنید — داده‌های فعلی حفظ می‌شوند. روی cPanel در `config/local.php` مقدارهای `crawl_page_budget=1`، `crawl_use_worker=0` پیشنهاد می‌شود.
3. فایل `release/rondino-simple-cpanel.zip` را آپلود و Extract کنید.
4. محتوای پوشهٔ `public/` را در Document Root سایت/ساب‌دامین بگذارید (معمولاً `public_html`).
5. پوشه‌های `app/`، `views/`، `config/`، `database/` را یک سطح بالاتر از Document Root نگه دارید (خصوصی بمانند). اگر ساختار ZIP را کنار هم استخراج کرده‌اید، Document Root را روی زیرپوشهٔ `public` تنظیم کنید.
6. `config/local.example.php` را به `config/local.php` کپی کنید و اطلاعات پایگاه و یک `setup_token` تصادفی طولانی بگذارید.
7. یک‌بار به آدرس `/?r=setup&token=TOKEN` بروید، مدیر بسازید. پس از ساخت مدیر، توکن خالی می‌شود و راه‌اندازی غیرفعال است.
8. مجوز پیشنهادی: پوشه‌ها `0755`، فایل‌های PHP معمولی `0644`.

---

## بخش‌های برنامه

- بازار خطوط
- تخمین قیمت
- معاملات تیم
- وضعیت منابع (به‌روزرسانی تکی / همه، دسته‌ای)

رمزها با `password_hash`، پرس‌وجوها با prepared statement، خروجی escape‌شده، و فرم‌های تغییردهنده با CSRF محافظت می‌شوند.
