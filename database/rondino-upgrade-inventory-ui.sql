-- Rondino inventory UI performance indexes
-- Safe to import via phpMyAdmin. Does not delete data.
-- Skip any statement that errors with "Duplicate key name".

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE offers
  ADD INDEX idx_offers_last_checked (last_checked_at);

ALTER TABLE offers
  ADD INDEX idx_offers_source_checked (source_id, last_checked_at);

ALTER TABLE offers
  ADD INDEX idx_offers_sim_price (sim_number_id, price_amount);
