<?php

declare(strict_types=1);

/**
 * Persistent sync watermarks for incremental inventory crawls.
 *
 * Verified API limitation (list payload): listing rows do NOT include
 * lastUpdateDateMillis/createDateMillis even though the URL sorts by them.
 * Incremental early-stop therefore uses overlap of known external_id values
 * from the newest pages, plus scheduled full reconciliation.
 */
final class SourceSync
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS source_sync_state (
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
                PRIMARY KEY (source_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<string,mixed> */
    public static function load(PDO $pdo, int $sourceId): array
    {
        self::ensureSchema($pdo);
        $stmt = $pdo->prepare('SELECT * FROM source_sync_state WHERE source_id = ?');
        $stmt->execute([$sourceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $now = now_dt();
            $overlap = max(1, (int) config('rondino_incremental_overlap_pages', 3));
            $maxPages = max($overlap, (int) config('rondino_incremental_max_pages', 50));
            $hours = max(1, (int) config('rondino_full_reconcile_every_hours', 168));
            $pdo->prepare(
                'INSERT INTO source_sync_state
                 (source_id, overlap_pages, incremental_max_pages, full_reconcile_every_hours, updated_at)
                 VALUES (?,?,?,?,?)'
            )->execute([$sourceId, $overlap, $maxPages, $hours, $now]);
            $stmt->execute([$sourceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        return $row;
    }

    public static function markStarted(PDO $pdo, int $sourceId, string $mode): void
    {
        $row = self::load($pdo, $sourceId);
        $pdo->prepare(
            'UPDATE source_sync_state
             SET mode_last=?, last_started_at=?, consecutive_known_pages=0, updated_at=?
             WHERE source_id=?'
        )->execute([$mode, now_dt(), now_dt(), $sourceId]);
        unset($row);
    }

    /**
     * @param array<string,mixed> $stats
     */
    public static function markCompleted(
        PDO $pdo,
        int $sourceId,
        string $mode,
        int $pagesFetched,
        ?string $lastExternalId,
        ?int $totalPages,
        ?int $totalElements,
        array $stats = []
    ): void {
        self::load($pdo, $sourceId);
        $now = now_dt();
        $fullAt = $mode === 'full' ? $now : null;
        $incAt = $mode === 'incremental' ? $now : null;
        $pdo->prepare(
            'UPDATE source_sync_state SET
                mode_last=?,
                last_success_at=?,
                last_full_completed_at=COALESCE(?, last_full_completed_at),
                last_incremental_completed_at=COALESCE(?, last_incremental_completed_at),
                last_external_id=?,
                last_page_index=?,
                last_total_pages=?,
                last_total_elements=?,
                consecutive_known_pages=0,
                stats_json=?,
                updated_at=?
             WHERE source_id=?'
        )->execute([
            $mode,
            $now,
            $fullAt,
            $incAt,
            $lastExternalId,
            $pagesFetched,
            $totalPages,
            $totalElements,
            json_encode($stats, JSON_UNESCAPED_UNICODE),
            $now,
            $sourceId,
        ]);
    }

    public static function needsFullReconcile(PDO $pdo, int $sourceId): bool
    {
        $row = self::load($pdo, $sourceId);
        if (empty($row['last_full_completed_at'])) {
            return true;
        }
        $hours = max(1, (int) ($row['full_reconcile_every_hours'] ?? 168));
        $last = strtotime((string) $row['last_full_completed_at'] . ' UTC');
        if ($last === false) {
            return true;
        }
        return (time() - $last) >= ($hours * 3600);
    }

    /**
     * Seed sync row after a verified full inventory completion (idempotent).
     */
    public static function seedFromCompletedPass(PDO $pdo, int $sourceId, int $pages, ?int $totalElements): void
    {
        self::load($pdo, $sourceId);
        $now = now_dt();
        $pdo->prepare(
            'UPDATE source_sync_state SET
                mode_last=\'full\',
                last_full_completed_at=COALESCE(last_full_completed_at, ?),
                last_success_at=COALESCE(last_success_at, ?),
                last_page_index=GREATEST(last_page_index, ?),
                last_total_pages=COALESCE(?, last_total_pages),
                last_total_elements=COALESCE(?, last_total_elements),
                updated_at=?
             WHERE source_id=?'
        )->execute([$now, $now, $pages, $pages, $totalElements, $now, $sourceId]);
    }
}
