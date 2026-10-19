<?php
declare(strict_types=1);

namespace App\Core;

final class Request
{
    private static ?string $rawBody = null;

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    public static function query(string $key): ?string
    {
        $v = $_GET[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function post(string $key): ?string
    {
        $v = $_POST[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    /** Body JSON (maks 1 MB). null bila kosong, tidak valid, atau bukan objek/array. */
    public static function json(): ?array
    {
        if (self::$rawBody === null) {
            self::$rawBody = (string) file_get_contents('php://input', false, null, 0, 1048577);
        }
        if (self::$rawBody === '' || strlen(self::$rawBody) > 1048576) {
            return null;
        }
        $d = json_decode(self::$rawBody, true);
        return is_array($d) ? $d : null;
    }

    public static function ip(): string
    {
        // REMOTE_ADDR saja: header proxy mudah dipalsukan.
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
    }
}
