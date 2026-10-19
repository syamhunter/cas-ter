<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    /** Render app/Views/<view>.php dengan variabel $data (lingkup terisolasi). */
    public static function render(string $view, array $data = []): void
    {
        if (!preg_match('#^[a-z0-9_/-]+$#i', $view)) {
            throw new \InvalidArgumentException('Nama view tidak valid.');
        }
        $file = CORE_PATH . '/app/Views/' . $view . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('View tidak ditemukan: ' . $view);
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        (static function (string $__file, array $__data): void {
            extract($__data, EXTR_SKIP);
            require $__file;
        })($file, $data);
    }
}
