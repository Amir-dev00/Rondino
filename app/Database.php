<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) config('db.host', '127.0.0.1');
        $port = (int) config('db.port', 3306);
        $name = (string) config('db.name', '');
        $user = (string) config('db.user', '');
        $pass = (string) config('db.pass', '');
        $charset = (string) config('db.charset', 'utf8mb4');

        if ($name === '') {
            throw new RuntimeException('نام پایگاه داده در تنظیمات خالی است.');
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 30,
        ]);
        // Avoid idle disconnects during long crawl waves.
        try {
            self::$pdo->exec('SET SESSION wait_timeout=28800');
            self::$pdo->exec('SET SESSION interactive_timeout=28800');
            // Full inventory crawl state can exceed the default 1 MiB packet.
            self::$pdo->exec('SET SESSION max_allowed_packet=16777216');
        } catch (Throwable) {
            // Host may forbid SESSION timeout/packet changes; ignore.
        }
        return self::$pdo;
    }

    public static function reconnect(): PDO
    {
        self::$pdo = null;
        return self::pdo();
    }

    public static function connectServer(string $host, int $port, string $user, string $pass): PDO
    {
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public static function importSqlFile(PDO $pdo, string $sqlPath): void
    {
        $sql = file_get_contents($sqlPath);
        if ($sql === false) {
            throw new RuntimeException('خواندن فایل SQL ممکن نشد.');
        }
        // Strip comments and split into statements (PDO often disallows multi-query).
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $pdo->exec($part);
        }
    }
}
