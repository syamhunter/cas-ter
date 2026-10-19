<?php
declare(strict_types=1);

namespace App\Core;

final class Auth
{
    private const IDLE_SECONDS = 28800; // 8 jam tanpa aktivitas
    private static ?bool $valid = null;

    public static function check(): bool
    {
        $a = $_SESSION['auth'] ?? null;
        if (!is_array($a) || empty($a['uid'])) {
            return false;
        }
        if (self::$valid !== null) {
            return self::$valid;
        }
        if (time() - (int) ($a['last'] ?? 0) > self::IDLE_SECONDS) {
            self::logout();
            return false;
        }
        $statement = Database::pdo()->prepare('SELECT tenant_id, role, password_hash, is_active FROM users WHERE id = ? AND deleted_at IS NULL');
        $statement->execute([(int) $a['uid']]);
        $user = $statement->fetch();
        $tenantId = $a['tid'] ?? null;
        $tenantMatches = $user
            && ($user['tenant_id'] !== null ? (int) $user['tenant_id'] : null) === $tenantId;
        if ($user && !$tenantMatches && $user['role'] === 'owner' && $tenantId !== null) {
            $membership = Database::pdo()->prepare(
                'SELECT 1 FROM owner_account_tenants oat
                   JOIN owner_accounts oa ON oa.id = oat.owner_account_id
                  WHERE oa.owner_user_id = ? AND oat.tenant_id = ?'
            );
            $membership->execute([(int) $a['uid'], (int) $tenantId]);
            $tenantMatches = (bool) $membership->fetchColumn();
        }
        if (!$user || (int) $user['is_active'] !== 1 || !$tenantMatches
            || !isset($a['role']) || !hash_equals((string) $user['role'], (string) $a['role'])) {
            self::logout();
            return false;
        }
        $fingerprint = hash('sha256', (string) $user['password_hash']);
        if (!isset($a['pw']) || !hash_equals((string) $a['pw'], $fingerprint)) {
            self::logout();
            return false;
        }
        $_SESSION['auth']['pw'] = $fingerprint;
        $_SESSION['auth']['last'] = time();
        self::$valid = true;
        return true;
    }

    public static function login(array $user, ?int $branchId): void
    {
        self::$valid = null;
        session_regenerate_id(true);
        $_SESSION['auth'] = [
            'uid' => (int) $user['id'],
            'tid' => $user['tenant_id'] !== null ? (int) $user['tenant_id'] : null,
            'bid' => $branchId,
            'role' => (string) $user['role'],
            'name' => (string) $user['name'],
            'last' => time(),
        ];
        if (isset($user['password_hash'])) {
            $_SESSION['auth']['pw'] = hash('sha256', (string) $user['password_hash']);
        }
        unset($_SESSION['csrf']);
    }

    public static function logout(): void
    {
        self::$valid = false;
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function selectBusiness(int $tenantId, int $branchId): void
    {
        if ($tenantId <= 0 || $branchId <= 0 || self::role() !== 'owner') {
            throw new \InvalidArgumentException('Konteks usaha pemilik tidak valid.');
        }
        $_SESSION['auth']['tid'] = $tenantId;
        $_SESSION['auth']['bid'] = $branchId;
        $_SESSION['auth']['last'] = time();
        self::$valid = null;
    }

    public static function userId(): int { return (int) ($_SESSION['auth']['uid'] ?? 0); }
    public static function tenantId(): ?int { return isset($_SESSION['auth']['tid']) ? (int) $_SESSION['auth']['tid'] : null; }
    public static function branchId(): ?int { return isset($_SESSION['auth']['bid']) ? (int) $_SESSION['auth']['bid'] : null; }
    public static function role(): string { return (string) ($_SESSION['auth']['role'] ?? ''); }
    public static function name(): string { return (string) ($_SESSION['auth']['name'] ?? ''); }
}
