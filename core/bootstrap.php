<?php
declare(strict_types=1);

/**
 * core/bootstrap.php - dimuat oleh public/index.php
 * Memuat .env, autoloader PSR-4 (App\ -> app/), penanganan error, dan session.
 */

define('CORE_PATH', __DIR__);
define('APP_INSTALLED', is_file(CORE_PATH . '/storage/installed.lock'));

// ---- Autoloader: App\Core\Router -> app/Core/Router.php ----
spl_autoload_register(static function (string $class): void {
    if (strpos($class, 'App\\') !== 0) {
        return;
    }
    $path = CORE_PATH . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

// ---- .env sederhana (KEY=VALUE, # komentar, nilai boleh diberi tanda kutip) ----
(static function (): void {
    $file = CORE_PATH . '/.env';
    if (!is_file($file)) {
        return;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $_ENV[$k] = \App\Core\Environment::parseValue($v);
    }
})();

// ---- Error handling: tidak menampilkan detail ke pengunjung ----
$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
error_reporting(E_ALL);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(CORE_PATH . '/storage/logs') && is_writable(CORE_PATH . '/storage/logs')) {
    ini_set('error_log', CORE_PATH . '/storage/logs/php-error.log');
}
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Asia/Jakarta');

set_exception_handler(static function (Throwable $e) use ($debug): void {
    error_log('[fatal] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    $isApi = strpos((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/api/') !== false;
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Terjadi kesalahan pada server.', 'data' => null, 'errors' => null]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><meta charset="utf-8"><title>Kesalahan</title><body style="font-family:sans-serif;padding:2rem"><h1>Terjadi kesalahan</h1><p>Silakan coba lagi nanti.</p>'
            . ($debug ? '<pre>' . htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8') . '</pre>' : '') . '</body>';
    }
});

\App\Core\Session::start();
