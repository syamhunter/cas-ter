<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** URL dasar (subfolder). Dari .env bila ada; sebelum instalasi dideteksi dari lokasi index.php. */
    public static function base(): string
    {
        if (array_key_exists('APP_BASE_URL', $_ENV)) {
            return rtrim((string) $_ENV['APP_BASE_URL'], '/');
        }
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        return ($dir === '/' || $dir === '.' || $dir === '\\') ? '' : rtrim($dir, '/');
    }

    public static function run(): void
    {
        $routes = require CORE_PATH . '/config/routes.php';
        $method = Request::method() === 'HEAD' ? 'GET' : Request::method();

        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = rawurldecode($path);
        $base = self::base();
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        if ($path === '/index.php') {
            $path = '/';
        }
        $path = $path === '' ? '/' : ($path === '/' ? '/' : rtrim($path, '/'));

        if ($path === '/favicon.ico') {
            http_response_code(204);
            return;
        }

        $isApi = strpos($path, '/api/') === 0;
        $matched = null;
        $pathExists = false;
        foreach ($routes as $r) {
            if ($r[1] !== $path) {
                continue;
            }
            $pathExists = true;
            if ($r[0] === $method) {
                $matched = $r;
                break;
            }
        }

        // Mode instalasi: sebelum terpasang hanya /install yang boleh diakses.
        $isInstallRoute = $matched !== null && !empty($matched[3]['install']);
        if (!APP_INSTALLED) {
            if (!$isInstallRoute) {
                Response::redirect($base . '/install');
            }
        } elseif ($isInstallRoute || ($matched === null && strpos($path, '/install') === 0)) {
            self::fail(404, 'Halaman tidak ditemukan.', $isApi);
        }

        if ($matched === null) {
            self::fail($pathExists ? 405 : 404, $pathExists ? 'Metode tidak diizinkan.' : 'Halaman tidak ditemukan.', $isApi);
        }

        $opts = $matched[3] ?? [];

        if (!empty($opts['auth']) && !Auth::check()) {
            if ($isApi) {
                Response::send(401, false, 'Sesi berakhir, silakan login kembali.');
            }
            Response::redirect($base . '/login');
        }
        if (!empty($opts['roles']) && !in_array(Auth::role(), $opts['roles'], true)) {
            self::fail(403, 'Anda tidak memiliki akses ke halaman ini.', $isApi);
        }
        if (!empty($opts['csrf']) && !Csrf::verifyRequest()) {
            self::fail(403, 'Sesi formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.', $isApi);
        }

        [$class, $action] = explode('@', $matched[2]);
        $fqcn = 'App\\Controllers\\' . $class;
        (new $fqcn())->$action();
    }

    private static function fail(int $status, string $message, bool $isApi): void
    {
        if ($isApi) {
            Response::send($status, false, $message);
        }
        Response::page($status, (string) $status, $message);
    }
}
