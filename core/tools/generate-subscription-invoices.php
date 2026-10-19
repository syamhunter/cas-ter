<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/bootstrap.php';

try {
    $created = (new \App\Controllers\BillingController())->generateDueInvoices();
    fwrite(STDOUT, sprintf("Generated %d subscription invoice(s) at %s\n", $created, date(DATE_ATOM)));
} catch (Throwable $e) {
    error_log('[billing-cron] ' . $e->getMessage());
    fwrite(STDERR, "Invoice generation failed. Check the application log.\n");
    exit(1);
}
