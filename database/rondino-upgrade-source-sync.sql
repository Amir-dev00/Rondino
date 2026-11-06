-- Incremental crawl sync metadata (safe to re-run).
CREATE TABLE IF NOT EXISTS source_sync_state (
  source_id SMALLINT UNSIGNED NOT NULL,
  mode_last VARCHAR(32) NOT NULL DEFAULT 'full',
  last_full_completed_at DATETIME NULL,
  last_incremental_completed_at DATETIME NULL,
  last_success_at DATETIME NULL,
  last_started_at DATETIME NULL,
  last_external_id VARCHAR(120) NULL,
  last_page_index INT UNSIGNED NOT NULL DEFAULT 0,
  last_total_pages INT UNSIGNED NULL,
  last_total_elements INT UNSIGNED NULL,
  overlap_pages INT UNSIGNED NOT NULL DEFAULT 3,
  incremental_max_pages INT UNSIGNED NOT NULL DEFAULT 50,
  full_reconcile_every_hours INT UNSIGNED NOT NULL DEFAULT 168,
  consecutive_known_pages INT UNSIGNED NOT NULL DEFAULT 0,
  stats_json TEXT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (source_id),
  CONSTRAINT fk_source_sync_source FOREIGN KEY (source_id) REFERENCES sources (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Speed lookups used by incremental overlap detection.
-- Ignore duplicate-index errors if already applied.
ALTER TABLE offers
  ADD INDEX idx_offers_source_last_seen (source_id, last_seen_at);
