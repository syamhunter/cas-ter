<?php
declare(strict_types=1);

/**
 * Front controller. Mencari core aplikasi di:
 *   1) ../pos_core   (cPanel: core di luar public_html)
 *   2) ./core        (InfinityFree: semua di htdocs)
 */
$core = null;
foreach ([dirname(__DIR__) . '/pos_core', __DIR__ . '/core'] as $candidate) {
    if (is_file($candidate . '/bootstrap.php')) {
        $core = $candidate;
        break;
    }
}
if ($core === null) {
    http_response_code(500);
    exit('Core aplikasi tidak ditemukan. Pastikan folder core (atau pos_core) ikut terunggah.');
}

require $core . '/bootstrap.php';
\App\Core\Router::run();
