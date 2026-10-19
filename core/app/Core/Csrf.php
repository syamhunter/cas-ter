<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function verify(?string $token): bool
    {
        return is_string($token) && $token !== '' && isset($_SESSION['csrf']) && hash_equals((string) $_SESSION['csrf'], $token);
    }

    /** Token dari header X-CSRF-Token atau field form _csrf. */
    public static function verifyRequest(): bool
    {
        return self::verify(Request::header('X-CSRF-Token') ?? Request::post('_csrf'));
    }
}
