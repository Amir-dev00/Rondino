-- Rondino install schema + seed sources (phpMyAdmin)
-- Import into an EMPTY database already selected in phpMyAdmin.
-- Charset: utf8mb4
-- No CREATE/DROP DATABASE, no USE, no accounts, no listings, no admin credentials.

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int(11) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `crawl_checkpoints` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint(20) unsigned NOT NULL,
  `cursor` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cursor`)),
  `locked_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `crawl_checkpoints_source_id_foreign` (`source_id`),
  CONSTRAINT `crawl_checkpoints_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `crawl_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint(20) unsigned NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `trigger` varchar(32) NOT NULL DEFAULT 'cron',
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `pages_fetched` int(10) unsigned NOT NULL DEFAULT 0,
  `requests_made` int(10) unsigned NOT NULL DEFAULT 0,
  `listings_upserted` int(10) unsigned NOT NULL DEFAULT 0,
  `listings_parsed` int(10) unsigned NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `progress` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`progress`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `crawl_runs_source_id_status_index` (`source_id`,`status`),
  CONSTRAINT `crawl_runs_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `offer_contacts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `offer_id` bigint(20) unsigned NOT NULL,
  `phone` varchar(20) NOT NULL,
  `phone_type` varchar(16) NOT NULL DEFAULT 'mobile',
  `scope` varchar(16) NOT NULL DEFAULT 'store',
  `label` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offer_contacts_offer_id_phone_scope_unique` (`offer_id`,`phone`,`scope`),
  CONSTRAINT `offer_contacts_offer_id_foreign` FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `offers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint(20) unsigned NOT NULL,
  `sim_number_id` bigint(20) unsigned NOT NULL,
  `external_id` varchar(191) NOT NULL,
  `canonical_url` varchar(500) NOT NULL,
  `price_amount` bigint(20) unsigned DEFAULT NULL,
  `price_raw` varchar(255) DEFAULT NULL,
  `price_type` varchar(32) NOT NULL DEFAULT 'unknown',
  `condition_raw` varchar(255) DEFAULT NULL,
  `condition_normalized` varchar(32) NOT NULL DEFAULT 'unknown',
  `city` varchar(100) DEFAULT NULL,
  `city_raw` varchar(255) DEFAULT NULL,
  `seller_key` varchar(191) DEFAULT NULL,
  `availability` varchar(32) NOT NULL DEFAULT 'available',
  `first_seen_at` datetime NOT NULL,
  `last_seen_at` datetime NOT NULL,
  `last_checked_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `offers_source_id_external_id_unique` (`source_id`,`external_id`),
  KEY `offers_sim_number_id_availability_index` (`sim_number_id`,`availability`),
  KEY `offers_price_amount_index` (`price_amount`),
  KEY `offers_last_seen_at_index` (`last_seen_at`),
  KEY `offers_condition_normalized_index` (`condition_normalized`),
  CONSTRAINT `offers_sim_number_id_foreign` FOREIGN KEY (`sim_number_id`) REFERENCES `sim_numbers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `offers_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `price_observations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `offer_id` bigint(20) unsigned NOT NULL,
  `price_amount` bigint(20) unsigned DEFAULT NULL,
  `price_raw` varchar(255) DEFAULT NULL,
  `price_type` varchar(32) NOT NULL DEFAULT 'unknown',
  `observed_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `price_observations_offer_id_observed_at_index` (`offer_id`,`observed_at`),
  CONSTRAINT `price_observations_offer_id_foreign` FOREIGN KEY (`offer_id`) REFERENCES `offers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `sim_numbers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(11) NOT NULL,
  `code_digit` char(1) NOT NULL,
  `pattern_flags` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`pattern_flags`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sim_numbers_number_unique` (`number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `source_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `domain` varchar(255) NOT NULL,
  `audit_date` date NOT NULL,
  `status` varchar(64) NOT NULL,
  `evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evidence`)),
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `source_audits_source_id_foreign` (`source_id`),
  CONSTRAINT `source_audits_source_id_foreign` FOREIGN KEY (`source_id`) REFERENCES `sources` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `base_url` varchar(255) NOT NULL,
  `listing_urls` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`listing_urls`)),
  `adapter_class` varchar(255) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `refresh_interval_minutes` int(10) unsigned NOT NULL DEFAULT 180,
  `max_pages_per_run` int(10) unsigned NOT NULL DEFAULT 3,
  `max_requests_per_run` int(10) unsigned NOT NULL DEFAULT 30,
  `rate_limit_ms` int(10) unsigned NOT NULL DEFAULT 1500,
  `fields_available` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fields_available`)),
  `capability_notes` text DEFAULT NULL,
  `shared_hosting_ok` tinyint(1) NOT NULL DEFAULT 1,
  `health_status` varchar(32) NOT NULL DEFAULT 'unknown',
  `last_attempt_at` timestamp NULL DEFAULT NULL,
  `last_success_at` timestamp NULL DEFAULT NULL,
  `last_parse_success_at` timestamp NULL DEFAULT NULL,
  `next_retry_at` timestamp NULL DEFAULT NULL,
  `listings_found_last_run` int(10) unsigned NOT NULL DEFAULT 0,
  `last_error` text DEFAULT NULL,
  `last_error_type` varchar(32) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sources_slug_unique` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `team_deals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `sim_number_id` bigint(20) unsigned NOT NULL,
  `deal_type` varchar(16) NOT NULL,
  `amount_toman` bigint(20) unsigned NOT NULL,
  `deal_date` date NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_deals_user_id_foreign` (`user_id`),
  KEY `team_deals_sim_number_id_deal_date_index` (`sim_number_id`,`deal_date`),
  CONSTRAINT `team_deals_sim_number_id_foreign` FOREIGN KEY (`sim_number_id`) REFERENCES `sim_numbers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `team_deals_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1),(2,'0001_01_01_000001_create_cache_table',1),(3,'0001_01_01_000002_create_jobs_table',1),(4,'2026_11_03_000001_create_rondino_core_tables',1);
INSERT INTO `sources` (`id`, `slug`, `name`, `base_url`, `listing_urls`, `adapter_class`, `enabled`, `refresh_interval_minutes`, `max_pages_per_run`, `max_requests_per_run`, `rate_limit_ms`, `fields_available`, `capability_notes`, `shared_hosting_ok`, `health_status`, `last_attempt_at`, `last_success_at`, `last_parse_success_at`, `next_retry_at`, `listings_found_last_run`, `last_error`, `last_error_type`, `created_at`, `updated_at`) VALUES (1,'rond-ir','rond.ir','https://rond.ir','[\"https:\\/\\/rond.ir\"]','App\\Scraping\\Adapters\\RondIrAdapter',1,360,2,10,2000,'[]','مسدود: اتصال از محیط توسعه در ۲۰۲۶-۱۱-۰۳ تایم‌اوت شد (https/http/www). سلکتوری ثبت نشده. فرمان تست مجدد: php artisan sources:audit --source=rond-ir',0,'blocked',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(2,'tehransim','tehransim.com','https://tehransim.com','[\"https:\\/\\/tehransim.com\\/\",\"https:\\/\\/tehransim.com\\/home\\/simkart912\"]','App\\Scraping\\Adapters\\TehransimAdapter',1,180,3,30,1500,'[\"sim_number\",\"asking_price\",\"line_condition\",\"advertiser_contacts\"]','صفحهٔ اول از HTML قابل استخراج است. صفحات بعدی از POST /Home/GetHomeSimkart استفاده می‌کنند.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(3,'iran912','iran912.com','https://iran912.com','[\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-0\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-1\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-2\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-3\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-4\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-5\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-6\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-7\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-8\\/\",\"https:\\/\\/iran912.com\\/simcart-912\\/price-list-code-9\\/\"]','App\\Scraping\\Adapters\\Iran912Adapter',1,180,2,40,1500,'[\"sim_number\",\"asking_price\",\"line_condition\",\"advertiser_contacts\"]','لیست واقعی روی price-list-code-{0-9} است نه صفحهٔ راهنما. رزرو نیاز به ورود دارد.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(4,'simcartiha','simcartiha.ir','https://simcartiha.ir','[\"https:\\/\\/simcartiha.ir\\/\",\"https:\\/\\/simcartiha.ir\\/code0\\/\",\"https:\\/\\/simcartiha.ir\\/code1\\/\",\"https:\\/\\/simcartiha.ir\\/code2\\/\",\"https:\\/\\/simcartiha.ir\\/code3\\/\",\"https:\\/\\/simcartiha.ir\\/code4\\/\",\"https:\\/\\/simcartiha.ir\\/code5\\/\",\"https:\\/\\/simcartiha.ir\\/code6\\/\",\"https:\\/\\/simcartiha.ir\\/code7\\/\",\"https:\\/\\/simcartiha.ir\\/code8\\/\",\"https:\\/\\/simcartiha.ir\\/code9\\/\"]','App\\Scraping\\Adapters\\SimcartihaAdapter',1,180,2,30,1500,'[\"sim_number\",\"asking_price\",\"advertiser_contacts\"]','کاروسل صفحهٔ اول محدود است؛ برای پوشش بیشتر /codeN/ لازم است. وضعیت خط روی کارت‌ها ضعیف است.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(5,'endline912','endline912.com','https://endline912.com','[\"https:\\/\\/endline912.com\\/\"]','App\\Scraping\\Adapters\\Endline912Adapter',1,180,3,25,1500,'[\"sim_number\",\"asking_price\",\"advertiser_contacts\"]','حلقهٔ محصول Elementor روی صفحهٔ اول. جزئیات محصول ممکن است noindex باشد.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(6,'donyayekhat','donyayekhat0912.com','https://donyayekhat0912.com','[\"https:\\/\\/donyayekhat0912.com\\/\"]','App\\Scraping\\Adapters\\DonyayekhatAdapter',1,360,1,5,2000,'[]','مسدود/ناکامل: در HTML صفحهٔ اصلی و دسته، کارت آگهی شماره یافت نشد. WAF BitNinja. تماس فروشگاه در هدر هست اما به آگهی‌ها قابل انتساب نیست.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(7,'cafesimcart','cafesimcart.com','https://cafesimcart.com','[\"https:\\/\\/cafesimcart.com\\/\"]','App\\Scraping\\Adapters\\CafesimcartAdapter',1,180,2,20,1500,'[\"sim_number\",\"asking_price\",\"line_condition\",\"advertiser_contacts\"]','کارت‌های صفحهٔ اول با .simdetail قابل استخراج‌اند. BitNinja روی میزبان مبدأ فعال است.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(8,'rond0912','rond0912.com','https://rond0912.com','[\"https:\\/\\/rond0912.com\\/\"]','App\\Scraping\\Adapters\\Rond0912Adapter',1,180,1,15,2000,'[\"sim_number\",\"asking_price\",\"line_condition\",\"advertiser_contacts\"]','صفحهٔ خانگی بسیار حجیم (~1MB). کارت‌ها با .simdetail قابل استخراج‌اند.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(9,'simkhan','simkhan.ir','https://simkhan.ir','[\"https:\\/\\/simkhan.ir\\/simcard912\"]','App\\Scraping\\Adapters\\SimkhanAdapter',1,360,1,5,2000,'[]','SPA/Next.js: صفحهٔ تبلیغ فروشگاه‌ها است نه لیست سیم. API موجودی سیم تأیید نشد (مسیرهای حدسی 404). نیاز به کشف endpoint مجاز عمومی.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(10,'setaresim','setaresim.com','https://setaresim.com','[\"https:\\/\\/setaresim.com\\/\"]','App\\Scraping\\Adapters\\SetaresimAdapter',1,360,1,5,2000,'[]','مسدود: اتصال در ۲۰۲۶-۱۱-۰۳ تایم‌اوت شد. سلکتوری ثبت نشده. فرمان تست مجدد: php artisan sources:audit --source=setaresim',0,'blocked',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(11,'jamsimkart','jamsimkart.com','https://www.jamsimkart.com','[\"https:\\/\\/www.jamsimkart.com\\/\",\"https:\\/\\/www.jamsimkart.com\\/mci\\/daemi\\/simcard-pricing\"]','App\\Scraping\\Adapters\\JamsimkartAdapter',1,180,3,30,1500,'[\"sim_number\",\"asking_price\",\"line_condition\",\"advertiser_contacts\"]','شماره تماس فروشنده روی کارت نیست؛ از طریق مودال showSimInfo و تلفن‌های فروشگاه در فوتر.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(12,'rondbaz','rondbaz.com','https://www.rondbaz.com','[\"https:\\/\\/www.rondbaz.com\\/\",\"https:\\/\\/www.rondbaz.com\\/mci\\/?cell_box_1_4=0912\"]','App\\Scraping\\Adapters\\RondbazAdapter',1,180,2,25,1500,'[\"sim_number\",\"asking_price\",\"line_condition\",\"advertiser_contacts\"]','بازار چندفروشنده‌ای؛ فروشنده از slug فروشگاه در URL استخراج می‌شود. فقط خطوط 0912 نگه داشته می‌شوند.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53'),(13,'sim0912','0912sim.com','https://0912sim.com','[\"https:\\/\\/0912sim.com\\/\",\"https:\\/\\/0912sim.com\\/shop\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code0\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code1\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code2\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code3\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code4\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code5\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code6\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code7\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code8\\/\",\"https:\\/\\/0912sim.com\\/product-category\\/code9\\/\"]','App\\Scraping\\Adapters\\Sim0912Adapter',1,180,2,30,1500,'[\"sim_number\",\"asking_price\",\"advertiser_contacts\"]','در زمان ممیزی برخی آرشیوهای codeN خالی بودند. محصولات ظاهرشده از گرید Woodmart قابل استخراج‌اند.',1,'unknown',NULL,NULL,NULL,NULL,0,NULL,NULL,'2026-11-03 04:34:53','2026-11-03 04:34:53');
SET FOREIGN_KEY_CHECKS = 1;
