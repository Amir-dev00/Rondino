<?php

declare(strict_types=1);

/** Local multi-tab crawl job coordination (MySQL only). */
final class CrawlJob
{
    public static function ensureSchema(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS crawl_jobs (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return array<string,mixed> */
    public static function activeOrCreate(string $mode = 'full', bool $forceNew = false): array
    {
        $pdo = Database::pdo();
        self::ensureSchema($pdo);
        $now = now_dt();

        if (!$forceNew) {
            $stmt = $pdo->query(
                "SELECT * FROM crawl_jobs WHERE status IN ('queued','running') ORDER BY id DESC LIMIT 1"
            );
            $row = $stmt->fetch();
            if ($row) {
                return $row;
            }
        }

        // Mark older running as superseded
        $pdo->exec("UPDATE crawl_jobs SET status='cancelled', finished_at=UTC_TIMESTAMP(), message='جایگزین شد' WHERE status IN ('queued','running')");

        $pdo->prepare(
            'INSERT INTO crawl_jobs (status, mode, message, updated_at, started_at) VALUES (?,?,?,?,?)'
        )->execute(['queued', $mode, 'در صف کارگر محلی', $now, $now]);
        $id = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT * FROM crawl_jobs WHERE id=?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: ['id' => $id, 'status' => 'queued'];
    }

    /** @return array<string,mixed>|null */
    public static function latest(): ?array
    {
        $pdo = Database::pdo();
        self::ensureSchema($pdo);
        $row = $pdo->query('SELECT * FROM crawl_jobs ORDER BY id DESC LIMIT 1')->fetch();
        return $row ?: null;
    }

    public static function touch(int $id, string $status, ?string $message = null, ?array $metrics = null): void
    {
        $pdo = Database::pdo();
        $pdo->prepare(
            'UPDATE crawl_jobs SET status=?, message=COALESCE(?, message), metrics_json=COALESCE(?, metrics_json),
             updated_at=?, finished_at=IF(?, UTC_TIMESTAMP(), finished_at), started_at=COALESCE(started_at, ?) WHERE id=?'
        )->execute([
            $status,
            $message,
            $metrics !== null ? json_encode($metrics, JSON_UNESCAPED_UNICODE) : null,
            now_dt(),
            in_array($status, ['completed', 'failed', 'cancelled'], true) ? 1 : 0,
            now_dt(),
            $id,
        ]);
    }

    public static function claimNext(): ?array
    {
        $pdo = Database::pdo();
        self::ensureSchema($pdo);
        $pdo->beginTransaction();
        try {
            $row = $pdo->query(
                "SELECT * FROM crawl_jobs WHERE status='queued' OR (status='running' AND (locked_until IS NULL OR locked_until < UTC_TIMESTAMP())) ORDER BY id ASC LIMIT 1 FOR UPDATE"
            )->fetch();
            if (!$row) {
                $pdo->commit();
                return null;
            }
            $token = bin2hex(random_bytes(8));
            $until = gmdate('Y-m-d H:i:s', time() + 120);
            $pdo->prepare(
                'UPDATE crawl_jobs SET status=?, lock_token=?, locked_until=?, updated_at=?, started_at=COALESCE(started_at, ?) WHERE id=?'
            )->execute(['running', $token, $until, now_dt(), now_dt(), (int) $row['id']]);
            $pdo->commit();
            $row['status'] = 'running';
            $row['lock_token'] = $token;
            return $row;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
