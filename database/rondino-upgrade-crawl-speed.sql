-- Non-destructive speed/concurrency upgrade for existing Rondino MySQL DBs.
-- Safe alongside rondino-upgrade-crawl-progress.sql
-- Preserves offers, price history, users, deals, crawl_passes rows.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

SET @db := DATABASE();

SET @sql := (
  SELECT IF(
    EXISTS(
      SELECT 1 FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'crawl_passes' AND COLUMN_NAME = 'branch_state'
    ),
    'SELECT 1',
    'ALTER TABLE crawl_passes ADD COLUMN branch_state MEDIUMTEXT NULL'
  )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS crawl_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(32) NOT NULL DEFAULT 'queued',
  mode VARCHAR(32) NOT NULL DEFAULT 'full',
  requested_by VARCHAR(64) NULL,
  message VARCHAR(500) NULL,
  metrics_json TEXT NULL,
  lock_token VARCHAR(64) NULL,
  locked_until DATETIME NULL,
  started_at DATETIME NULL,
  updated_at DATETIME NOT NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_crawl_jobs_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
