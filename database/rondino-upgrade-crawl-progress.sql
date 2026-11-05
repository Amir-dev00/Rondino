-- Non-destructive upgrade for existing Rondino MySQL databases.
-- Import via phpMyAdmin into the existing database (no CREATE DATABASE / USE / DROP).
-- Preserves users, offers, price history, deals, and sources.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS crawl_passes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_id SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'running',
  seed_index INT UNSIGNED NOT NULL DEFAULT 0,
  page_index INT UNSIGNED NOT NULL DEFAULT 1,
  current_url VARCHAR(1000) NULL,
  offers_found INT UNSIGNED NOT NULL DEFAULT 0,
  pages_fetched INT UNSIGNED NOT NULL DEFAULT 0,
  last_batch_offers INT UNSIGNED NOT NULL DEFAULT 0,
  last_batch_at DATETIME NULL,
  progress_label VARCHAR(255) NULL,
  branch_errors TEXT NULL,
  last_error VARCHAR(1000) NULL,
  last_fingerprint CHAR(40) NULL,
  seed_fingerprint CHAR(40) NULL,
  lock_token VARCHAR(64) NULL,
  locked_until DATETIME NULL,
  started_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_crawl_passes_source (source_id),
  CONSTRAINT fk_crawl_passes_source FOREIGN KEY (source_id) REFERENCES sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safe for databases that already created crawl_passes without fingerprint columns:
SET @db := DATABASE();
SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crawl_passes' AND COLUMN_NAME = 'last_fingerprint'
    ),
    'SELECT 1',
    'ALTER TABLE crawl_passes ADD COLUMN last_fingerprint CHAR(40) NULL, ADD COLUMN seed_fingerprint CHAR(40) NULL'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
