-- Contact enrichment queue / lookup indexes (phpMyAdmin-safe).
-- Skip any statement that errors with Duplicate key name. Does not delete data.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE rond_contact_checks
  ADD INDEX idx_rond_contact_retry (retry_at, attempts, status);

ALTER TABLE offer_contacts
  ADD INDEX idx_offer_contacts_offer_id (offer_id);
