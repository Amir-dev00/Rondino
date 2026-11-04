<?php

declare(strict_types=1);

final class Auth
{
    public static function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $stmt = Database::pdo()->prepare('SELECT id, name, email, is_admin, is_active FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user || !(int) $user['is_active']) {
            self::logout();
            return null;
        }
        return $user;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            redirect('/?r=login');
        }
        return $user;
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !(int) $user['is_active']) {
            return false;
        }
        if (!password_verify($password, $user['password_hash'])) {
            return false;
        }
        $_SESSION['user_id'] = (int) $user['id'];
        session_regenerate_id(true);
        return true;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
        session_regenerate_id(true);
    }

    public static function adminExists(): bool
    {
        $n = (int) Database::pdo()->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
        return $n > 0;
    }

    public static function createAdmin(string $name, string $email, string $password): void
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = Database::pdo()->prepare(
            'INSERT INTO users (name, email, password_hash, is_admin, is_active, created_at) VALUES (?,?,?,?,1,?)'
        );
        $stmt->execute([$name, $email, $hash, 1, now_dt()]);
    }
}
