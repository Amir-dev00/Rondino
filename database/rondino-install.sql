-- Rondino Simple — schema + 13 sources
-- Import into an empty database selected in phpMyAdmin.
-- No CREATE DATABASE / USE / DROP / passwords / sample listings.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_admin TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sources (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL,
  base_url VARCHAR(255) NOT NULL,
  list_url VARCHAR(512) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  refresh_interval_minutes INT UNSIGNED NOT NULL DEFAULT 60,
  last_attempt_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_error VARCHAR(1000) NULL,
  last_offers_found INT UNSIGNED NOT NULL DEFAULT 0,
  notes VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sources_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sim_numbers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  number CHAR(11) NOT NULL,
  code_digit CHAR(1) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sim_numbers_number (number),
  KEY idx_sim_code (code_digit)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE offers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id SMALLINT UNSIGNED NOT NULL,
  sim_number_id INT UNSIGNED NOT NULL,
  external_id VARCHAR(120) NOT NULL,
  canonical_url VARCHAR(1000) NOT NULL,
  price_amount BIGINT UNSIGNED NULL,
  price_raw VARCHAR(255) NULL,
  price_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
  condition_raw VARCHAR(255) NULL,
  condition_normalized VARCHAR(32) NOT NULL DEFAULT 'unknown',
  city VARCHAR(120) NULL,
  city_raw VARCHAR(255) NULL,
  seller_key VARCHAR(190) NULL,
  availability VARCHAR(32) NOT NULL DEFAULT 'available',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  last_checked_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_offers_source_external (source_id, external_id),
  KEY idx_offers_sim (sim_number_id),
  KEY idx_offers_price (price_amount),
  KEY idx_offers_condition (condition_normalized),
  KEY idx_offers_availability (availability),
  KEY idx_offers_city (city),
  CONSTRAINT fk_offers_source FOREIGN KEY (source_id) REFERENCES sources (id),
  CONSTRAINT fk_offers_sim FOREIGN KEY (sim_number_id) REFERENCES sim_numbers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE offer_contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id INT UNSIGNED NOT NULL,
  phone VARCHAR(20) NOT NULL,
  phone_type VARCHAR(20) NOT NULL DEFAULT 'mobile',
  scope VARCHAR(20) NOT NULL DEFAULT 'store',
  label VARCHAR(120) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_offer_contact (offer_id, phone, scope),
  KEY idx_offer_contacts_phone (phone),
  CONSTRAINT fk_offer_contacts_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE price_observations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id INT UNSIGNED NOT NULL,
  price_amount BIGINT UNSIGNED NULL,
  price_raw VARCHAR(255) NULL,
  price_type VARCHAR(32) NOT NULL DEFAULT 'unknown',
  observed_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_price_obs_offer (offer_id, observed_at),
  CONSTRAINT fk_price_obs_offer FOREIGN KEY (offer_id) REFERENCES offers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_deals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  sim_number CHAR(11) NOT NULL,
  deal_type ENUM('buy','sell') NOT NULL,
  amount_toman BIGINT UNSIGNED NOT NULL,
  deal_date DATE NOT NULL,
  note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_team_deals_sim (sim_number),
  KEY idx_team_deals_date (deal_date),
  CONSTRAINT fk_team_deals_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE crawl_runs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id SMALLINT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'running',
  offers_found INT UNSIGNED NOT NULL DEFAULT 0,
  error_message VARCHAR(1000) NULL,
  PRIMARY KEY (id),
  KEY idx_crawl_runs_source (source_id, started_at),
  CONSTRAINT fk_crawl_runs_source FOREIGN KEY (source_id) REFERENCES sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO sources (slug, name, base_url, list_url, is_enabled, refresh_interval_minutes, notes) VALUES
('rond-ir', 'rond.ir', 'https://rond.ir', 'https://rond.ir', 1, 120, 'ممکن است تایم‌اوت یا مسدود باشد؛ سلکتور تأییدشده ثبت نشده.'),
('tehransim', 'tehransim.com', 'https://tehransim.com', 'https://tehransim.com/home/simkart912', 1, 60, 'کارت‌های SSR صفحه اول.'),
('iran912', 'iran912.com', 'https://iran912.com', 'https://iran912.com/simcart-912/price-list-code-0/', 1, 60, 'لیست واقعی روی price-list-code-0 تا 9.'),
('simcartiha', 'simcartiha.ir', 'https://simcartiha.ir', 'https://simcartiha.ir/', 1, 60, 'ووکامرس /code0 تا /code9.'),
('endline912', 'endline912.com', 'https://endline912.com', 'https://endline912.com/', 1, 60, 'حلقه Elementor در صفحه اصلی.'),
('donyayekhat', 'donyayekhat0912.com', 'https://donyayekhat0912.com', 'https://donyayekhat0912.com/', 1, 120, 'ممکن است WAF مانع شود؛ کارت آگهی پایدار دیده نشد.'),
('cafesimcart', 'cafesimcart.com', 'https://cafesimcart.com', 'https://cafesimcart.com/', 1, 60, 'کارت‌های .simdetail.'),
('rond0912', 'rond0912.com', 'http://rond0912.com', 'http://rond0912.com/', 1, 60, 'کارت‌های .simdetail؛ صفحه بزرگ.'),
('simkhan', 'simkhan.ir', 'https://simkhan.ir', 'https://simkhan.ir/simcard912', 1, 120, 'لندینگ Next.js؛ شبکه آگهی عمومی تأیید نشده.'),
('setaresim', 'setaresim.com', 'https://setaresim.com', 'https://setaresim.com/', 1, 120, 'ممکن است تایم‌اوت باشد؛ سلکتور تأییدشده ثبت نشده.'),
('jamsimkart', 'jamsimkart.com', 'https://www.jamsimkart.com', 'https://www.jamsimkart.com/mci/daemi/simcard-pricing', 1, 60, 'کارت‌های .vitrin-item.'),
('rondbaz', 'rondbaz.com', 'https://www.rondbaz.com', 'https://www.rondbaz.com/mci/?cell_box_1_4=0912', 1, 60, 'پارسر کارت‌های .vitrin-sim-card آماده است؛ در ممیزی فعلی مبدأ HTTP 404 برمی‌گرداند.'),
('sim0912', '0912sim.com', 'https://0912sim.com', 'https://0912sim.com/shop/', 1, 60, 'ووکامرس Woodmart؛ دسته code0 تا code9.');
