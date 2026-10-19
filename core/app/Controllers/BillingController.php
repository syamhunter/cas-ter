<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use DateTimeImmutable;
use PDO;
use Throwable;

final class BillingController
{
    private const METHODS = ['bank_transfer', 'qris', 'cash', 'other'];
    private const MAX_PROOF_BYTES = 5242880;

    public function submitProof(): void
    {
        $invoiceId = filter_var(Request::post('invoice_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reference = trim((string) Request::post('reference'));
        $method = Request::post('payment_method') ?? '';
        $paidAt = $this->validDate(Request::post('paid_at'));
        $file = $_FILES['proof'] ?? null;
        $returnPath = '/owner/billing';
        if ($invoiceId === false || $reference === '' || strlen($reference) > 120
            || !in_array($method, self::METHODS, true) || $paidAt === null || $paidAt > date('Y-m-d')
            || !is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || (int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > self::MAX_PROOF_BYTES) {
            $this->ownerFlash('danger', 'Periksa tanggal, metode, referensi, dan file bukti (maksimal 5 MB).');
            $this->back($returnPath);
        }

        $pdo = Database::pdo();
        $this->ensureProofSchema($pdo);
        $accountId = $this->ownerAccountId($pdo, Auth::userId());
        $billingKey = 'owner-' . $accountId;
        $invoice = $pdo->prepare("SELECT id, amount, issued_at, tenant_id FROM subscription_invoices WHERE id = ? AND billing_key = ? AND status IN ('unpaid','overdue')");
        $invoice->execute([(int) $invoiceId, $billingKey]);
        $invoiceRow = $invoice->fetch();
        if (!$invoiceRow) {
            $this->ownerFlash('danger', 'Invoice tidak ditemukan atau tidak dapat menerima bukti pembayaran.');
            $this->back($returnPath);
        }
        if ($paidAt < $invoiceRow['issued_at']) {
            $this->ownerFlash('danger', 'Tanggal transfer tidak boleh sebelum invoice diterbitkan.');
            $this->back($returnPath);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (!isset($extensions[$mime])) {
            $this->ownerFlash('danger', 'File bukti harus berupa PDF, JPG, atau PNG.');
            $this->back($returnPath);
        }
        $uploadDir = CORE_PATH . '/storage/payment-proofs';
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0700, true) && !is_dir($uploadDir)) {
            $this->ownerFlash('danger', 'Penyimpanan bukti belum tersedia. Hubungi administrator.');
            $this->back($returnPath);
        }
        $storedName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        $storedPath = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string) $file['tmp_name'], $storedPath)) {
            $this->ownerFlash('danger', 'File bukti gagal disimpan. Silakan coba lagi.');
            $this->back($returnPath);
        }
        @chmod($storedPath, 0600);

        try {
            $pdo->beginTransaction();
            $locked = $pdo->prepare("SELECT id FROM subscription_invoices WHERE id = ? AND billing_key = ? AND status IN ('unpaid','overdue') FOR UPDATE");
            $locked->execute([(int) $invoiceId, $billingKey]);
            if (!$locked->fetchColumn()) throw new \RuntimeException('Invoice berubah status atau tidak lagi tersedia.');
            $pending = $pdo->prepare("SELECT id FROM invoice_payment_proofs WHERE invoice_id = ? AND status = 'pending' LIMIT 1 FOR UPDATE");
            $pending->execute([(int) $invoiceId]);
            if ($pending->fetchColumn()) throw new \RuntimeException('Bukti untuk invoice ini masih menunggu verifikasi Admin.');
            $pdo->prepare(
                'INSERT INTO invoice_payment_proofs
                    (invoice_id, billing_key, owner_account_id, submitted_by, amount, paid_at, payment_method, reference, file_path, original_name, mime_type, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'pending\')'
            )->execute([
                (int) $invoiceId, $billingKey, $accountId, Auth::userId(), $invoiceRow['amount'], $paidAt,
                $method, $reference, $storedName, substr(basename((string) $file['name']), 0, 255), $mime,
            ]);
            $pdo->commit();
            $this->ownerFlash('success', 'Bukti transfer terkirim dan menunggu verifikasi Admin.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            @unlink($storedPath);
            error_log('[billing] submit proof gagal: ' . $e->getMessage());
            $this->ownerFlash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Bukti gagal dikirim. Periksa referensi transfer.');
        }
        $this->back($returnPath);
    }

    public function reviewProof(): void
    {
        $proofId = filter_var(Request::post('proof_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $decision = Request::post('decision') ?? '';
        $note = trim((string) Request::post('reviewer_note'));
        if ($proofId === false || !in_array($decision, ['approved', 'rejected'], true)
            || ($decision === 'rejected' && $note === '') || strlen($note) > 500) {
            $this->adminFlash('danger', 'Keputusan atau catatan verifikasi tidak valid. Catatan wajib diisi jika bukti ditolak.');
            $this->back('/admin/billing');
        }
        $pdo = Database::pdo();
        $this->ensureProofSchema($pdo);
        $this->ensureAdminAuditSchema($pdo);
        try {
            $pdo->beginTransaction();
            $statement = $pdo->prepare(
                'SELECT pp.*, i.invoice_no, i.status AS invoice_status, i.period_end, i.tenant_id, i.owner_account_id
                   FROM invoice_payment_proofs pp JOIN subscription_invoices i ON i.id = pp.invoice_id
                  WHERE pp.id = ? FOR UPDATE'
            );
            $statement->execute([(int) $proofId]);
            $proof = $statement->fetch();
            if (!$proof || $proof['status'] !== 'pending') throw new \RuntimeException('Bukti tidak ditemukan atau sudah diverifikasi.');
            if ($decision === 'approved') {
                if (!in_array($proof['invoice_status'], ['unpaid', 'overdue'], true)) {
                    throw new \RuntimeException('Invoice sudah tidak dapat menerima pembayaran.');
                }
                $pdo->prepare(
                    'INSERT INTO invoice_payments (invoice_id, billing_key, amount, paid_at, payment_method, reference, notes, recorded_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    (int) $proof['invoice_id'], $proof['billing_key'], $proof['amount'], $proof['paid_at'],
                    $proof['payment_method'], $proof['reference'], $note !== '' ? $note : null, Auth::userId(),
                ]);
                $pdo->prepare("UPDATE subscription_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
                    ->execute([$proof['paid_at'], (int) $proof['invoice_id']]);
                $this->extendOwnerSubscription($pdo, $proof);
            }
            $pdo->prepare('UPDATE invoice_payment_proofs SET status = ?, reviewer_note = ?, reviewed_by = ?, reviewed_at = NOW(6) WHERE id = ?')
                ->execute([$decision, $note !== '' ? $note : null, Auth::userId(), (int) $proofId]);
            $this->recordAdminAction($pdo, 'billing.proof_' . $decision, 'invoice_payment_proof', (int) $proofId, [
                'invoice_no' => $proof['invoice_no'], 'reference' => $proof['reference'], 'note' => $note,
            ]);
            $pdo->commit();
            $this->adminFlash('success', $decision === 'approved' ? 'Bukti disetujui dan invoice ditandai lunas.' : 'Bukti ditolak. Owner dapat mengirim bukti baru.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[billing] review proof gagal: ' . $e->getMessage());
            $this->adminFlash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Verifikasi gagal. Periksa invoice dan referensi pembayaran.');
        }
        $this->back('/admin/billing');
    }

    public function proofFile(): void
    {
        $proofId = Request::query('id');
        $proofId = $proofId === null ? false : filter_var($proofId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($proofId === false) Response::page(404, 'Bukti tidak ditemukan', 'Bukti pembayaran tidak valid.');
        $pdo = Database::pdo();
        $this->ensureProofSchema($pdo);
        $query = 'SELECT id, owner_account_id, file_path, original_name, mime_type FROM invoice_payment_proofs WHERE id = ?';
        $params = [(int) $proofId];
        if (Auth::role() === 'owner') {
            $account = $this->ownerAccountId($pdo, Auth::userId());
            $query .= ' AND owner_account_id = ?';
            $params[] = $account;
        } elseif (Auth::role() !== 'super_admin') {
            Response::page(403, 'Akses ditolak', 'Anda tidak berhak melihat bukti ini.');
        }
        $statement = $pdo->prepare($query);
        $statement->execute($params);
        $proof = $statement->fetch();
        if (!$proof) Response::page(404, 'Bukti tidak ditemukan', 'Bukti tidak tersedia pada akun Anda.');
        $directory = realpath(CORE_PATH . '/storage/payment-proofs');
        $path = $directory ? realpath($directory . DIRECTORY_SEPARATOR . basename((string) $proof['file_path'])) : false;
        if (!$directory || !$path || strpos($path, $directory . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
            Response::page(404, 'Bukti tidak ditemukan', 'File bukti tidak tersedia.');
        }
        header('Content-Type: ' . $proof['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="' . rawurlencode((string) $proof['original_name']) . '"');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    public function generateDueInvoices(): int
    {
        $pdo = Database::pdo();
        (new AdminController())->ensureInvoiceSchema($pdo);
        $this->ensureAdminAuditSchema($pdo);
        $adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'super_admin' AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
        if ($adminId <= 0) throw new \RuntimeException('Akun Super Admin aktif diperlukan sebagai pencatat invoice otomatis.');
        $candidates = $pdo->query(
            "SELECT oa.id AS owner_account_id, oa.plan_id, oa.subscription_ends_at, p.name AS plan_name,
                    p.price_monthly, MIN(oat.tenant_id) AS tenant_id
               FROM owner_accounts oa JOIN plans p ON p.id = oa.plan_id
               JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id
               JOIN tenants t ON t.id = oat.tenant_id AND t.deleted_at IS NULL
              WHERE oa.status IN ('active','trial') AND oa.subscription_ends_at IS NOT NULL
                AND DATE(oa.subscription_ends_at) <= DATE_ADD(CURRENT_DATE, INTERVAL 14 DAY)
                AND p.price_monthly > 0
              GROUP BY oa.id, oa.plan_id, oa.subscription_ends_at, p.name, p.price_monthly
              ORDER BY oa.subscription_ends_at, oa.id"
        )->fetchAll();
        $created = 0;
        foreach ($candidates as $candidate) {
            try {
                $pdo->beginTransaction();
                $account = $pdo->prepare('SELECT status, subscription_ends_at, plan_id FROM owner_accounts WHERE id = ? FOR UPDATE');
                $account->execute([(int) $candidate['owner_account_id']]);
                $current = $account->fetch();
                if (!$current || !in_array($current['status'], ['active', 'trial'], true) || $current['subscription_ends_at'] === null) {
                    $pdo->rollBack();
                    continue;
                }
                $end = new DateTimeImmutable((string) $current['subscription_ends_at']);
                if ($end->format('Y-m-d') > (new DateTimeImmutable('today'))->modify('+14 days')->format('Y-m-d')) {
                    $pdo->rollBack();
                    continue;
                }
                $periodStart = $end->modify('+1 day')->format('Y-m-d');
                $periodEnd = (new DateTimeImmutable($periodStart))->modify('+1 month')->modify('-1 day')->format('Y-m-d');
                $billingKey = 'owner-' . (int) $candidate['owner_account_id'];
                $existing = $pdo->prepare('SELECT id FROM subscription_invoices WHERE billing_key = ? AND period_start <= ? AND period_end >= ? LIMIT 1 FOR UPDATE');
                $existing->execute([$billingKey, $periodEnd, $periodStart]);
                if ($existing->fetchColumn()) {
                    $pdo->commit();
                    continue;
                }
                $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                $dueAt = $end->format('Y-m-d');
                $pdo->prepare(
                    'INSERT INTO subscription_invoices
                        (invoice_no, billing_key, tenant_id, owner_account_id, plan_id, plan_name, amount, period_start, period_end, issued_at, due_at, grace_until, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_DATE, ?, ?, \'unpaid\', ?)'
                )->execute([
                    $invoiceNo, $billingKey, (int) $candidate['tenant_id'], (int) $candidate['owner_account_id'],
                    (int) $current['plan_id'], $candidate['plan_name'], $candidate['price_monthly'],
                    $periodStart, $periodEnd, $dueAt,
                    (new DateTimeImmutable($dueAt))->modify('+7 days')->format('Y-m-d'), $adminId,
                ]);
                $invoiceId = (int) $pdo->lastInsertId();
                $this->recordAdminAction($pdo, $adminId, 'billing.invoice_auto_created', 'subscription_invoice', $invoiceId, [
                    'invoice_no' => $invoiceNo, 'billing_key' => $billingKey,
                    'period_start' => $periodStart, 'period_end' => $periodEnd,
                ]);
                $pdo->commit();
                $created++;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[billing] auto invoice owner ' . (int) $candidate['owner_account_id'] . ': ' . $e->getMessage());
            }
        }
        return $created;
    }

    public function ensureProofSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS invoice_payment_proofs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            billing_key VARCHAR(50) NOT NULL,
            owner_account_id INT UNSIGNED NOT NULL,
            submitted_by INT UNSIGNED NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            paid_at DATE NOT NULL,
            payment_method ENUM(\'bank_transfer\',\'qris\',\'cash\',\'other\') NOT NULL DEFAULT \'bank_transfer\',
            reference VARCHAR(120) NOT NULL,
            file_path VARCHAR(190) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime_type VARCHAR(100) NOT NULL,
            status ENUM(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'pending\',
            reviewer_note VARCHAR(500) NULL,
            reviewed_by INT UNSIGNED NULL,
            reviewed_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id), UNIQUE KEY uq_invoice_proof_reference (billing_key, reference),
            KEY idx_invoice_proof_review (status, created_at), KEY idx_invoice_proof_owner (owner_account_id, created_at),
            CONSTRAINT fk_invoice_proof_invoice FOREIGN KEY (invoice_id) REFERENCES subscription_invoices (id),
            CONSTRAINT fk_invoice_proof_owner FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id),
            CONSTRAINT fk_invoice_proof_submitter FOREIGN KEY (submitted_by) REFERENCES users (id),
            CONSTRAINT fk_invoice_proof_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function extendOwnerSubscription(PDO $pdo, array $proof): void
    {
        if ($proof['period_end'] < date('Y-m-d')) return;
        $newEnd = $proof['period_end'] . ' 23:59:59';
        $statement = $pdo->prepare('SELECT subscription_ends_at FROM owner_accounts WHERE id = ? FOR UPDATE');
        $statement->execute([(int) $proof['owner_account_id']]);
        $currentEnd = $statement->fetchColumn();
        $effectiveEnd = $currentEnd === false || $currentEnd === null || substr((string) $currentEnd, 0, 10) < $proof['period_end']
            ? $newEnd : (string) $currentEnd;
        $pdo->prepare("UPDATE owner_accounts SET subscription_ends_at = ?, status = 'active' WHERE id = ?")
            ->execute([$effectiveEnd, (int) $proof['owner_account_id']]);
        $pdo->prepare("UPDATE tenants SET subscription_ends_at = ?, status = 'active' WHERE id IN (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)")
            ->execute([$effectiveEnd, (int) $proof['owner_account_id']]);
    }

    private function ownerAccountId(PDO $pdo, int $userId): int
    {
        $statement = $pdo->prepare('SELECT id FROM owner_accounts WHERE owner_user_id = ?');
        $statement->execute([$userId]);
        return (int) $statement->fetchColumn();
    }

    private function recordAdminAction(PDO $pdo, int $adminId, string $action, string $type, int $id, array $details): void
    {
        $pdo->prepare('INSERT INTO admin_activity_logs (admin_user_id, action_code, entity_type, entity_id, details) VALUES (?, ?, ?, ?, ?)')
            ->execute([$adminId, $action, $type, $id, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
    }

    private function ensureAdminAuditSchema(PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS admin_activity_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_user_id INT UNSIGNED NOT NULL,
            action_code VARCHAR(60) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            details JSON NULL,
            ip_address VARCHAR(45) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY idx_admin_activity_time (created_at), KEY idx_admin_activity_entity (entity_type, entity_id),
            CONSTRAINT fk_admin_activity_user FOREIGN KEY (admin_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function validDate(?string $value): ?string
    {
        if ($value === null) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function ownerFlash(string $type, string $message): void { $_SESSION['owner_flash'] = ['type' => $type, 'message' => $message]; }
    private function adminFlash(string $type, string $message): void { $_SESSION['admin_flash'] = ['type' => $type, 'msg' => $message]; }
    private function back(string $path): void { Response::redirect(Router::base() . $path); }
}