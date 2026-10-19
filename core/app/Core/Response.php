<?php
declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** JSON seragam {success, message, data, errors}, lalu exit. */
    public static function send(int $status, bool $success, string $message, ?array $data = null, ?array $errors = null): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode(
            ['success' => $success, 'message' => $message, 'data' => $data, 'errors' => $errors],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
        exit;
    }

    public static function redirect(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
        }
        exit;
    }

    /** Halaman pesan sederhana (403/404/dst.). */
    public static function page(int $status, string $title, string $message): void
    {
        http_response_code($status);
        View::render('errors/message', ['title' => $title, 'message' => $message]);
        exit;
    }
}
