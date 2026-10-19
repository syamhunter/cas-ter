<?php
declare(strict_types=1);

/**
 * app/Controllers/CheckoutController.php
 *
 * Checkout kasir dinamis (retail, resto, apotek, laundry, percetakan, jasa) via AJAX.
 * Endpoint : POST /api/checkout   (Content-Type: application/json)
 * Header   : X-CSRF-Token
 *
 * ============================================================================
 * KELAS PENDUKUNG YANG DIASUMSIKAN SUDAH ADA (namespace App\Core) - TIDAK diimplementasikan di sini
 * ============================================================================
 *   Database::pdo(): PDO                     // ERRMODE_EXCEPTION, emulate_prepares=false
 *   Auth::check(): bool                      // sesi login valid
 *   Auth::userId(): int                      // dari sesi server
 *   Auth::tenantId(): ?int                   // dari sesi server
 *   Auth::branchId(): ?int                   // cabang AKTIF di sesi (owner memilihnya saat login)
 *   Csrf::verify(?string $token): bool
 *   Request::method(): string                // 'GET' | 'POST' | ...
 *   Request::header(string $name): ?string
 *   Request::json(): ?array                  // body JSON ter-decode, null bila kosong/tidak valid
 *   Response::send(int $status, bool $success, string $message, ?array $data = null, ?array $errors = null): void
 *                                            // mencetak JSON seragam lalu exit
 *
 * ============================================================================
 * CONTOH BODY REQUEST
 * ============================================================================
 * {
 *   "idempotency_key": "UUID",
 *   "order_type": "retail|dine_in|takeaway|pharmacy|laundry|printing|service",
 *   "customer_id": 1, "table_id": 7, "discount_amount": 0, "notes": "", "due_at": "2026-10-12 17:00",
 *   "meta": {"guest_count":2,"split_mode":"by_item","prescription_id":1,"pickup_due":"...","delivery":false,"deadline":"...","pharmacist":"..."},
 *   "items": [{"variant_id":1,"qty":1,"discount_amount":0,"split_group":1,"notes":"",
 *              "attributes":{ ... lihat per tipe di priceItem() ... }}],
 *   "payments": [{"method":"cash|qris|transfer|debit|credit|other","amount":50000,"reference_no":"","split_group":1}]
 * }
 *
 * ============================================================================
 * ASUMSI
 * ============================================================================
 * - Semua uang dihitung dalam sen (integer) agar bebas error float; qty dalam seperseribu (integer).
 * - Waktu mengikuti timezone tenant; created_at transaksi diisi eksplisit oleh controller ini,
 *   sehingga batas bulan kuota, tanggal invoice, dan kedaluwarsa batch konsisten.
 * - transactions.subtotal = jumlah kotor (sebelum diskon). discount_amount = diskon item + diskon header.
 *   Dasar pajak/service = subtotal - discount_amount. Service charge hanya dine_in (fnb.settings.service_charge_pct).
 *   Pajak = branches.settings.tax_pct; dapat ditambahkan ke total atau sudah termasuk harga.
 * - transaction_payments.amount disimpan NETTO (tunai dikurangi kembalian) agar laporan per metode akurat.
 *   transactions.paid_total = uang diterima kasir (bruto), change_amount = kembalian.
 * - Satu baris transaction_items per batch yang terpakai (apotek, FEFO) karena item hanya punya satu batch_id.
 * - Baris tenants dikunci (FOR UPDATE) di awal transaksi: kuota & penomoran konsisten, tanpa deadlock antar-kasir
 *   tenant yang sama. Kunci stok selalu diambil berurutan variant_id.
 */

namespace App\Controllers;

use App\Core\Auth;
use App\Core\AuditLogger;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\TaxCalculator;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use Throwable;

/** Exception terkontrol: diterjemahkan menjadi respons JSON dengan HTTP code tertentu. */
final class CheckoutException extends \RuntimeException
{
    public int $status;
    public ?array $errors;

    public function __construct(int $status, string $message, ?array $errors = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errors = $errors;
    }
}

final class CheckoutController
{
    private const MAX_ITEMS    = 100;
    private const MAX_PAYMENTS = 10;
    private const MAX_CENTS    = 100000000000000; // 1e12 rupiah (aman untuk DECIMAL(15,2))

    private const ORDER_TYPES = ['retail', 'dine_in', 'takeaway', 'pharmacy', 'laundry', 'printing', 'service'];
    private const PAYMENT_METHODS = ['cash', 'qris', 'transfer', 'debit', 'credit', 'other'];
    private const SPLIT_MODES = ['equal', 'by_item', 'custom'];

    private const MODULE_BY_ORDER = [
        'retail' => 'retail', 'dine_in' => 'fnb', 'takeaway' => 'fnb',
        'pharmacy' => 'pharmacy', 'laundry' => 'laundry', 'printing' => 'printing', 'service' => 'service',
    ];
    private const MODULE_BY_PRODUCT = [
        'goods' => 'retail', 'menu' => 'fnb', 'medicine' => 'pharmacy',
        'laundry' => 'laundry', 'print' => 'printing', 'service' => 'service',
    ];

    // =====================================================================
    // ENTRY POINT
    // =====================================================================

    public function store(): void
    {
        $pdo = Database::pdo();
        $tenantId = 0;
        $idemKey = null;

        try {
            if (Request::method() !== 'POST') {
                throw new CheckoutException(405, 'Metode tidak diizinkan.');
            }
            if (!Auth::check()) {
                throw new CheckoutException(401, 'Sesi berakhir, silakan login kembali.');
            }
            if (!Csrf::verify(Request::header('X-CSRF-Token'))) {
                throw new CheckoutException(403, 'Token CSRF tidak valid.');
            }
            OperationsController::ensureSchema($pdo);

            // Identitas HANYA dari sesi server, tidak pernah dari body.
            $tenantId = (int) Auth::tenantId();
            $branchId = (int) Auth::branchId();
            $userId   = (int) Auth::userId();
            if ($tenantId <= 0 || $branchId <= 0 || $userId <= 0) {
                throw new CheckoutException(403, 'Akun belum terhubung ke tenant/cabang aktif.');
            }

            $raw = Request::json();
            if (!is_array($raw)) {
                throw new CheckoutException(400, 'Body harus berupa JSON yang valid.');
            }
            $data = $this->validateInput($raw);
            $idemKey = $data['idempotency_key'];

            // Jalur cepat idempotensi (tanpa transaksi DB).
            $existingId = $this->findByIdempotency($pdo, $tenantId, $idemKey, false);
            if ($existingId !== null) {
                $receipt = $this->buildReceipt($pdo, $tenantId, $existingId);
                $receipt['duplicate'] = true;
                Response::send(200, true, 'Transaksi ini sudah diproses sebelumnya.', $receipt);
                return;
            }

            $pdo->beginTransaction();
            $receipt = $this->process($pdo, $tenantId, $branchId, $userId, $data);
            if (empty($receipt['duplicate']) && isset($receipt['transaction']['id'])) {
                $transaction = $receipt['transaction'];
                AuditLogger::record($pdo, $tenantId, $branchId, $userId, 'sale.checked_out', 'transaction',
                    (int) $transaction['id'], [
                        'payment_status' => (string) ($transaction['payment_status'] ?? ''),
                        'grand_total' => (string) ($transaction['grand_total'] ?? '0.00'),
                        'paid_total' => (string) ($transaction['paid_total'] ?? '0.00'),
                    ]);
            }
            $pdo->commit();

            if (!empty($receipt['duplicate'])) {
                Response::send(200, true, 'Transaksi ini sudah diproses sebelumnya.', $receipt);
                return;
            }
            Response::send(201, true, 'Transaksi berhasil disimpan.', $receipt);
        } catch (CheckoutException $e) {
            $this->rollback($pdo);
            Response::send($e->status, false, $e->getMessage(), null, $e->errors);
        } catch (PDOException $e) {
            $this->rollback($pdo);
            $code = (int) ($e->errorInfo[1] ?? 0);

            // Request kembar yang berbalapan: kunci idempotensi bentrok -> kembalikan transaksi yang sudah ada.
            if ($code === 1062 && $idemKey !== null && strpos($e->getMessage(), 'uq_trx_idem') !== false) {
                try {
                    $existingId = $this->findByIdempotency($pdo, $tenantId, $idemKey, false);
                    if ($existingId !== null) {
                        $receipt = $this->buildReceipt($pdo, $tenantId, $existingId);
                        $receipt['duplicate'] = true;
                        Response::send(200, true, 'Transaksi ini sudah diproses sebelumnya.', $receipt);
                        return;
                    }
                } catch (Throwable $inner) {
                    error_log('[checkout] idempotency lookup gagal: ' . $inner->getMessage());
                }
            }

            error_log('[checkout] DB error (tenant ' . $tenantId . '): ' . $e->getMessage());
            if ($code === 1205 || $code === 1213) { // lock wait timeout / deadlock
                Response::send(503, false, 'Server sedang sibuk, silakan coba lagi.');
                return;
            }
            Response::send(500, false, 'Terjadi kesalahan pada server. Transaksi tidak disimpan.');
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[checkout] Error (tenant ' . $tenantId . '): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::send(500, false, 'Terjadi kesalahan pada server. Transaksi tidak disimpan.');
        }
    }

    // =====================================================================
    // PROSES UTAMA (dipanggil di dalam DB transaction)
    // =====================================================================

    private function process(PDO $pdo, int $tenantId, int $branchId, int $userId, array $d): array
    {
        // Serialize transactions across every business on the same owner subscription.
        $ownerAccountTables = $this->one($pdo,
            'SELECT COUNT(*) AS available FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name IN (\'owner_accounts\', \'owner_account_tenants\')',
            []);
        $ownerAccount = (int) ($ownerAccountTables['available'] ?? 0) === 2
            ? $this->one($pdo,
                'SELECT oa.id, oa.plan_id, oa.status, oa.subscription_ends_at
                   FROM owner_account_tenants oat
                   JOIN owner_accounts oa ON oa.id = oat.owner_account_id
                  WHERE oat.tenant_id = ? FOR UPDATE',
                [$tenantId])
            : null;

        // 1) Kunci baris tenant untuk konsistensi data usaha.
        $tenant = $this->one($pdo,
            'SELECT id, plan_id, status, subscription_ends_at, timezone FROM tenants WHERE id = ? AND deleted_at IS NULL FOR UPDATE',
            [$tenantId]);
        if ($tenant === null) {
            throw new CheckoutException(403, 'Tenant tidak ditemukan.');
        }
        try {
            $tz = new DateTimeZone((string) $tenant['timezone']);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Asia/Jakarta');
        }
        $now = new DateTimeImmutable('now', $tz);
        $nowStr = $now->format('Y-m-d H:i:s');
        $today = $now->format('Y-m-d');

        // 2) Cek ulang idempotensi SETELAH kunci tenant (menutup celah request kembar).
        $dupId = $this->findByIdempotency($pdo, $tenantId, $d['idempotency_key'], true);
        if ($dupId !== null) {
            $receipt = $this->buildReceipt($pdo, $tenantId, $dupId);
            $receipt['duplicate'] = true;
            return $receipt;
        }

        // 3) Langganan, paket, kuota.
        $subscriptionStatus = $ownerAccount['status'] ?? $tenant['status'];
        $subscriptionEndsAt = $ownerAccount['subscription_ends_at'] ?? $tenant['subscription_ends_at'];
        $subscriptionPlanId = (int) ($ownerAccount['plan_id'] ?? $tenant['plan_id']);
        if (!in_array($subscriptionStatus, ['trial', 'active'], true)) {
            throw new CheckoutException(403, 'Langganan toko tidak aktif. Hubungi administrator.');
        }
        if ($subscriptionEndsAt !== null
            && new DateTimeImmutable((string) $subscriptionEndsAt, $tz) <= $now) {
            throw new CheckoutException(403, 'Masa langganan telah berakhir. Silakan perpanjang.');
        }
        $plan = $this->one($pdo,
            'SELECT is_active, max_transactions_month, allowed_modules FROM plans WHERE id = ?',
            [$subscriptionPlanId]);
        if ($plan === null || (int) $plan['is_active'] !== 1) {
            throw new CheckoutException(403, 'Paket langganan non-aktif.');
        }
        if ($plan['max_transactions_month'] !== null) {
            $from = $now->modify('first day of this month')->setTime(0, 0, 0);
            $to = $from->modify('+1 month');
            $cnt = $ownerAccount !== null
                ? $this->one($pdo,
                    'SELECT COUNT(*) AS c FROM transactions t
                      JOIN owner_account_tenants oat ON oat.tenant_id = t.tenant_id
                     WHERE oat.owner_account_id = ? AND t.created_at >= ? AND t.created_at < ?',
                    [(int) $ownerAccount['id'], $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')])
                : $this->one($pdo,
                    'SELECT COUNT(*) AS c FROM transactions WHERE tenant_id = ? AND created_at >= ? AND created_at < ?',
                    [$tenantId, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s')]);
            if ((int) ($cnt['c'] ?? 0) >= (int) $plan['max_transactions_month']) {
                throw new CheckoutException(403, 'Kuota transaksi bulanan paket telah habis. Silakan upgrade paket.');
            }
        }

        // 4) Modul aktif per toko (dibatasi juga oleh paket).
        $activeModules = [];
        $moduleSettings = [];
        foreach ($this->all($pdo, 'SELECT module_code, settings FROM tenant_modules WHERE tenant_id = ? AND is_active = 1', [$tenantId]) as $m) {
            $activeModules[] = (string) $m['module_code'];
            $moduleSettings[(string) $m['module_code']] = $this->decode($m['settings']);
        }
        if ($plan['allowed_modules'] !== null) {
            $activeModules = array_values(array_intersect($activeModules, array_map('strval', $this->decode($plan['allowed_modules']))));
        }

        // 5) Kasir, cabang, pelanggan, meja.
        $user = Auth::role() === 'owner' && $ownerAccount !== null
            ? $this->one($pdo,
                'SELECT u.role, u.permissions, u.is_active, u.branch_id
                   FROM users u
                   JOIN owner_accounts oa ON oa.owner_user_id = u.id
                   JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id AND oat.tenant_id = ?
                  WHERE u.id = ? AND u.role = \'owner\' AND u.deleted_at IS NULL',
                [$tenantId, $userId])
            : $this->one($pdo,
                'SELECT role, permissions, is_active, branch_id
                   FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                [$userId, $tenantId]);
        if ($user === null || (int) $user['is_active'] !== 1 || !in_array($user['role'], ['owner', 'manager', 'cashier'], true)) {
            throw new CheckoutException(403, 'Akun tidak berhak melakukan transaksi.');
        }
        if ($user['role'] !== Auth::role()) {
            throw new CheckoutException(403, 'Peran akun telah berubah. Silakan login kembali.');
        }
        if ($user['branch_id'] !== null && (int) $user['branch_id'] !== $branchId) {
            throw new CheckoutException(403, 'Akun tidak terdaftar pada cabang ini.');
        }
        $perms = $this->decode($user['permissions']);

        $branch = $this->one($pdo,
            'SELECT id, code, settings FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$branchId, $tenantId]);
        if ($branch === null) {
            throw new CheckoutException(403, 'Cabang tidak aktif atau tidak ditemukan.');
        }
        $branchSettings = json_decode((string) $branch['settings'], true);
        $branchSettings = is_array($branchSettings) ? $branchSettings : [];
        $taxPct = max(0.0, min(100.0, (float) ($branchSettings['tax_pct'] ?? 0)));
        $taxMode = in_array($branchSettings['tax_mode'] ?? '', ['none', 'inclusive', 'exclusive'], true)
            ? $branchSettings['tax_mode'] : 'exclusive';

        if ($d['customer_id'] !== null) {
            $c = $this->one($pdo, 'SELECT id FROM customers WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$d['customer_id'], $tenantId]);
            if ($c === null) {
                throw new CheckoutException(422, 'Pelanggan tidak ditemukan.', ['customer_id' => 'Pelanggan tidak ditemukan.']);
            }
        }

        $tableId = null;
        if ($d['order_type'] === 'dine_in') {
            $t = $this->one($pdo,
                'SELECT id, status FROM dining_tables WHERE id = ? AND tenant_id = ? AND branch_id = ? AND deleted_at IS NULL FOR UPDATE',
                [(int) $d['table_id'], $tenantId, $branchId]);
            if ($t === null) {
                throw new CheckoutException(422, 'Meja tidak ditemukan di cabang ini.', ['table_id' => 'Meja tidak ditemukan.']);
            }
            if (!in_array($t['status'], ['available', 'occupied'], true)) {
                throw new CheckoutException(409, 'Meja sedang tidak dapat dipakai (' . $t['status'] . ').', ['table_id' => 'Status meja: ' . $t['status']]);
            }
            $tableId = (int) $t['id'];
        }

        // 6) Muat produk/varian dari DB (harga TIDAK dipercaya dari klien).
        $variantIds = array_values(array_unique(array_column($d['items'], 'variant_id')));
        $ph = implode(',', array_fill(0, count($variantIds), '?'));
        $variantRows = $this->all($pdo,
            "SELECT v.id AS variant_id, v.product_id, v.name AS variant_name, v.is_default,
                    v.price AS variant_price, v.cost_price AS variant_cost, v.attributes AS variant_attributes,
                    p.name AS product_name, p.product_type, p.pricing_model, p.unit, p.base_price,
                    p.cost_price AS product_cost, p.track_stock, p.track_batch, p.attributes AS product_attributes
               FROM product_variants v
               JOIN products p ON p.id = v.product_id AND p.tenant_id = v.tenant_id
              WHERE v.tenant_id = ? AND v.id IN ($ph)
                AND v.is_active = 1 AND v.deleted_at IS NULL
                AND p.is_active = 1 AND p.deleted_at IS NULL",
            array_merge([$tenantId], $variantIds));
        $rows = [];
        foreach ($variantRows as $r) {
            $rows[(int) $r['variant_id']] = $r;
        }

        // 7) Hitung harga tiap item di server.
        $lines = [];
        $grossTotal = 0;
        $itemDiscTotal = 0;
        $requiredModules = [self::MODULE_BY_ORDER[$d['order_type']]];
        $needRx = false;
        $uploadIds = [];
        $hasMenu = false;

        foreach ($d['items'] as $idx => $it) {
            $row = $rows[$it['variant_id']] ?? null;
            if ($row === null) {
                throw new CheckoutException(422, 'Produk tidak ditemukan atau nonaktif.', ["items.$idx.variant_id" => 'Produk tidak ditemukan atau nonaktif.']);
            }
            $priced = $this->priceItem($row, $it, $idx);
            if ($it['discount_cents'] > $priced['gross_cents']) {
                throw new CheckoutException(422, 'Diskon item melebihi harga item.', ["items.$idx.discount_amount" => 'Diskon melebihi harga item.']);
            }
            $requiredModules[] = self::MODULE_BY_PRODUCT[$row['product_type']] ?? 'retail';
            if ($this->decode($row['product_attributes'])['requires_prescription'] ?? false) {
                $needRx = true;
            }
            if (!empty($priced['upload_id'])) {
                $uploadIds[] = (int) $priced['upload_id'];
            }
            if ($row['product_type'] === 'menu') {
                $hasMenu = true;
            }
            $unitCost = $row['variant_cost'] !== null ? $this->dbCents((string) $row['variant_cost']) : $this->dbCents((string) $row['product_cost']);
            $lines[] = ['row' => $row, 'in' => $it, 'priced' => $priced, 'cost_cents' => $unitCost];
            $grossTotal += $priced['gross_cents'];
            $itemDiscTotal += $it['discount_cents'];
        }

        foreach (array_unique($requiredModules) as $mod) {
            if (!in_array($mod, $activeModules, true)) {
                throw new CheckoutException(403, "Modul '$mod' tidak aktif untuk toko ini.");
            }
        }

        // 8) Hak diskon.
        $hasDiscount = $itemDiscTotal > 0 || $d['discount_cents'] > 0;
        if ($hasDiscount && $user['role'] === 'cashier' && ($perms['can_discount'] ?? false) !== true) {
            throw new CheckoutException(403, 'Anda tidak memiliki izin memberi diskon.');
        }

        // 9) Resep (apotek) & file desain (percetakan).
        $rxId = null;
        if ($needRx) {
            $rxId = $d['meta']['prescription_id'] ?? null;
            $rx = $rxId === null ? null : $this->one($pdo,
                'SELECT id FROM prescriptions WHERE id = ? AND tenant_id = ? AND branch_id = ? AND transaction_id IS NULL FOR UPDATE',
                [$rxId, $tenantId, $branchId]);
            if ($rx === null) {
                throw new CheckoutException(422, 'Obat keras/resep wajib menyertakan resep yang valid dan belum terpakai.', ['meta.prescription_id' => 'Resep wajib/valid.']);
            }
        }
        $uploadIds = array_values(array_unique($uploadIds));
        foreach ($uploadIds as $uid) {
            $u = $this->one($pdo,
                "SELECT id FROM uploads WHERE id = ? AND tenant_id = ? AND purpose = 'design' AND transaction_id IS NULL FOR UPDATE",
                [$uid, $tenantId]);
            if ($u === null) {
                throw new CheckoutException(422, 'File desain tidak ditemukan atau sudah terpakai.', ['upload_id' => 'File desain tidak valid.']);
            }
        }

        // 10) Total, service, pajak.
        if ($d['discount_cents'] > $grossTotal - $itemDiscTotal) {
            throw new CheckoutException(422, 'Diskon melebihi subtotal.', ['discount_amount' => 'Diskon melebihi subtotal.']);
        }
        $discountTotal = $itemDiscTotal + $d['discount_cents'];
        $base = $grossTotal - $discountTotal;
        $service = 0;
        if ($d['order_type'] === 'dine_in') {
            $svcPct = max(0.0, min(100.0, (float) ($moduleSettings['fnb']['service_charge_pct'] ?? 0)));
            $service = (int) round($base * $svcPct / 100);
        }
        $taxBase = $base + $service;
        $taxTotals = TaxCalculator::calculate($taxBase, $taxPct, $taxMode);
        $tax = $taxTotals['tax_cents'];
        $grand = $taxTotals['grand_cents'];
        if ($grand <= 0) {
            throw new CheckoutException(422, 'Total transaksi harus lebih dari 0.');
        }

        // 11) Validasi pembayaran (split bill).
        $paidTotal = 0;
        $nonCash = 0;
        foreach ($d['payments'] as $p) {
            $paidTotal += $p['amount_cents'];
            if ($p['method'] !== 'cash') {
                $nonCash += $p['amount_cents'];
            }
        }
        if ($paidTotal < $grand && !$d['on_account']) {
            throw new CheckoutException(422, 'Total pembayaran kurang dari total tagihan.', [
                'payments' => 'Kurang ' . $this->money($grand - $paidTotal),
            ]);
        }
        if ($d['on_account'] && $paidTotal > $grand) {
            throw new CheckoutException(422, 'Pembayaran penjualan tempo tidak boleh melebihi total tagihan.', ['payments' => 'Pembayaran melebihi tagihan.']);
        }
        if ($nonCash > $grand) {
            throw new CheckoutException(422, 'Pembayaran non-tunai tidak boleh melebihi total tagihan.', ['payments' => 'Non-tunai melebihi tagihan.']);
        }
        $change = max(0, $paidTotal - $grand);
        $paymentStatus = $paidTotal >= $grand ? 'paid' : ($paidTotal > 0 ? 'partial' : 'unpaid');

        // 12) Kunci stok (berurutan variant_id) sebelum menulis apa pun.
        $trackedIds = [];
        foreach ($lines as $ln) {
            if ((int) $ln['row']['track_stock'] === 1 || (int) $ln['row']['track_batch'] === 1) {
                $trackedIds[(int) $ln['row']['variant_id']] = true;
            }
        }
        $trackedIds = array_keys($trackedIds);
        sort($trackedIds);
        $stockMilli = [];
        if ($trackedIds) {
            $ph2 = implode(',', array_fill(0, count($trackedIds), '?'));
            foreach ($this->all($pdo,
                "SELECT variant_id, qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id IN ($ph2) ORDER BY variant_id FOR UPDATE",
                array_merge([$tenantId, $branchId], $trackedIds)) as $s) {
                $stockMilli[(int) $s['variant_id']] = $this->dbMilli((string) $s['qty']);
            }
        }

        // 13) Nomor invoice atomik per cabang per hari.
        $pdo->prepare('INSERT INTO invoice_counters (branch_id, counter_date, tenant_id, last_number) VALUES (?, ?, ?, 1)
                       ON DUPLICATE KEY UPDATE last_number = last_number + 1')
            ->execute([$branchId, $today, $tenantId]);
        $seq = $this->one($pdo, 'SELECT last_number FROM invoice_counters WHERE branch_id = ? AND counter_date = ?', [$branchId, $today]);
        $invoiceNo = sprintf('%s-%s-%04d', $branch['code'], $now->format('Ymd'), (int) $seq['last_number']);

        // 14) Header transaksi.
        $fulfillment = null;
        if ($d['order_type'] === 'laundry') {
            $fulfillment = 'diterima';
        } elseif ($d['order_type'] === 'printing') {
            $fulfillment = 'antri_cetak';
        } elseif (in_array($d['order_type'], ['dine_in', 'takeaway'], true) && $hasMenu) {
            $fulfillment = 'baru';
        } elseif ($d['order_type'] === 'service') {
            $fulfillment = 'menunggu';
        }
        $meta = $d['meta'];
        $meta['tax_mode'] = $taxMode;
        if ($rxId !== null) {
            $meta['prescription_id'] = $rxId;
        }

        $shift = $this->one($pdo,
            'SELECT id FROM cashier_shifts
              WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
              ORDER BY id DESC LIMIT 1 FOR UPDATE',
            [$tenantId, $branchId]);
        if ($shift === null) {
            throw new CheckoutException(409, 'Buka shift untuk akun ini terlebih dahulu sebelum melakukan transaksi.');
        }
        $shiftId = $shift !== null ? (int) $shift['id'] : null;

        $pdo->prepare(
            'INSERT INTO transactions
               (tenant_id, branch_id, invoice_no, idempotency_key, order_type, status, payment_status, fulfillment_status,
                customer_id, table_id, cashier_id, cashier_shift_id, subtotal, discount_amount, tax_amount, service_charge, grand_total,
                paid_total, change_amount, notes, meta, due_at, paid_at, created_at)
             VALUES (?, ?, ?, ?, ?, \'paid\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tenantId, $branchId, $invoiceNo, $d['idempotency_key'], $d['order_type'], $paymentStatus, $fulfillment,
            $d['customer_id'], $tableId, $userId, $shiftId,
            $this->money($grossTotal), $this->money($discountTotal), $this->money($tax), $this->money($service), $this->money($grand),
            $this->money($paidTotal), $this->money($change), $d['notes'], $this->jsonObj($meta), $d['due_at'],
            $paymentStatus === 'paid' ? $nowStr : null, $nowStr,
        ]);
        $trxId = (int) $pdo->lastInsertId();

        // 15) Item + stok + ledger.
        $insItem = $pdo->prepare(
            'INSERT INTO transaction_items
               (tenant_id, transaction_id, product_id, variant_id, batch_id, item_name, item_type, qty, unit, unit_price,
                discount_amount, line_total, cost_price, split_group, kitchen_status, item_attributes, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insMove = $pdo->prepare(
            'INSERT INTO stock_movements
               (tenant_id, branch_id, variant_id, batch_id, movement_type, qty_change, qty_after, reference_type, reference_id, note, created_by)
             VALUES (?, ?, ?, ?, \'sale\', ?, ?, \'transaction\', ?, ?, ?)'
        );
        $updStock = $pdo->prepare('UPDATE stock_levels SET qty = qty - ? WHERE tenant_id = ? AND branch_id = ? AND variant_id = ?');

        foreach ($lines as $ln) {
            $row = $ln['row'];
            $in = $ln['in'];
            $pr = $ln['priced'];
            $vid = (int) $row['variant_id'];
            $trackStock = (int) $row['track_stock'] === 1 || (int) $row['track_batch'] === 1;

            // Alokasi batch (FEFO) atau satu alokasi tanpa batch.
            if ((int) $row['track_batch'] === 1) {
                $batchPref = isset($in['attributes']['batch_id']) ? $this->toId($in['attributes']['batch_id']) : null;
                $allocs = $this->allocateBatches($pdo, $tenantId, $branchId, $vid, $pr['qty_milli'], $batchPref, $today, (string) $row['product_name']);
            } else {
                $allocs = [['batch_id' => null, 'qty_milli' => $pr['qty_milli'], 'cost_cents' => $ln['cost_cents'], 'extra' => []]];
            }

            // Stok total per cabang.
            $running = 0;
            if ($trackStock) {
                $have = $stockMilli[$vid] ?? 0;
                if ($have < $pr['qty_milli']) {
                    throw new CheckoutException(409, 'Stok tidak cukup untuk "' . $row['product_name'] . '".', ['stock' => (string) $row['product_name']]);
                }
                $running = $have;
                $stockMilli[$vid] = $have - $pr['qty_milli'];
                $updStock->execute([$this->qty($pr['qty_milli']), $tenantId, $branchId, $vid]);
            }

            // Pecah harga & diskon proporsional bila ada >1 alokasi batch.
            $weights = array_column($allocs, 'qty_milli');
            $grossParts = $this->splitProportional($pr['gross_cents'], $weights);
            $discParts = $this->splitProportional($in['discount_cents'], $weights);

            foreach ($allocs as $k => $al) {
                $attrs = array_merge($pr['attributes'], $al['extra']);
                $lineGross = $grossParts[$k];
                $lineDisc = $discParts[$k];
                $name = $row['product_name'];
                if ($row['variant_name'] !== 'Default' && ((int) $row['is_default'] === 0 || $row['product_type'] === 'print')) {
                    $name .= ' ' . $row['variant_name'];
                }
                $insItem->execute([
                    $tenantId, $trxId, (int) $row['product_id'], $vid, $al['batch_id'],
                    $name, $row['product_type'], $this->qty($al['qty_milli']), $pr['unit'],
                    $this->money($pr['unit_price_cents']), $this->money($lineDisc), $this->money($lineGross - $lineDisc),
                    $this->money((int) $al['cost_cents']), $in['split_group'],
                    $row['product_type'] === 'menu' ? 'baru' : null,
                    $this->jsonObj($attrs), $in['notes'],
                ]);
                if ($trackStock) {
                    $running -= $al['qty_milli'];
                    $insMove->execute([
                        $tenantId, $branchId, $vid, $al['batch_id'],
                        '-' . $this->qty($al['qty_milli']), $this->qty($running),
                        $trxId, $invoiceNo, $userId,
                    ]);
                }
            }
        }

        // 16) Pembayaran (netto: kembalian dipotong dari pembayaran tunai terakhir).
        $net = [];
        foreach ($d['payments'] as $p) {
            $net[] = $p;
        }
        $remainingChange = $change;
        for ($i = count($net) - 1; $i >= 0 && $remainingChange > 0; $i--) {
            if ($net[$i]['method'] === 'cash') {
                $cut = min($net[$i]['amount_cents'], $remainingChange);
                $net[$i]['amount_cents'] -= $cut;
                $remainingChange -= $cut;
            }
        }
        $insPay = $pdo->prepare(
            'INSERT INTO transaction_payments (tenant_id, transaction_id, method, amount, reference_no, split_group, cashier_shift_id, paid_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($net as $p) {
            if ($p['amount_cents'] <= 0) {
                continue;
            }
            $insPay->execute([$tenantId, $trxId, $p['method'], $this->money($p['amount_cents']), $p['reference_no'], $p['split_group'], $shiftId, $nowStr]);
        }

        // 17) Log status pengerjaan (laundry / dapur / percetakan).
        if ($fulfillment !== null) {
            $pdo->prepare('INSERT INTO transaction_status_logs (tenant_id, transaction_id, status_from, status_to, changed_by, note) VALUES (?, ?, NULL, ?, ?, ?)')
                ->execute([$tenantId, $trxId, $fulfillment, $userId, 'Transaksi dibuat']);
        }

        // 18) Meja resto, file desain, resep.
        if ($tableId !== null) {
            $pdo->prepare("UPDATE dining_tables SET status = 'occupied' WHERE id = ? AND tenant_id = ? AND branch_id = ?")
                ->execute([$tableId, $tenantId, $branchId]);
        }
        foreach ($uploadIds as $uid) {
            $pdo->prepare('UPDATE uploads SET transaction_id = ? WHERE id = ? AND tenant_id = ? AND transaction_id IS NULL')
                ->execute([$trxId, $uid, $tenantId]);
        }
        if ($rxId !== null) {
            $pdo->prepare('UPDATE prescriptions SET transaction_id = ? WHERE id = ? AND tenant_id = ? AND transaction_id IS NULL')
                ->execute([$trxId, $rxId, $tenantId]);
        }

        return $this->buildReceipt($pdo, $tenantId, $trxId);
    }

    // =====================================================================
    // HARGA PER TIPE ITEM (dihitung di server)
    // =====================================================================

    /**
     * Atribut yang dibaca dari $in['attributes'] per model harga:
     *  unit / fixed : (qty bilangan bulat) laundry per pcs: service, express
     *  per_kg       : weight_kg, + (laundry) service, express
     *  per_m2       : width_cm, height_cm, finishing[], upload_id ; qty = jumlah lembar
     *  per_hour     : duration_minutes, start_at?, staff?
     *  tambahan     : menu -> modifiers[]; medicine -> batch_id?, dosage_instruction?
     *
     * @return array{qty_milli:int,unit:string,unit_price_cents:int,gross_cents:int,attributes:array,upload_id?:int}
     */
    private function priceItem(array $row, array $in, int $idx): array
    {
        $pa = $this->decode($row['product_attributes']);
        $va = $this->decode($row['variant_attributes']);
        $a = $in['attributes'];
        $err = static function (string $field, string $msg) use ($idx): CheckoutException {
            return new CheckoutException(422, $msg, ["items.$idx.$field" => $msg]);
        };

        $priceCents = $this->dbCents((string) ($row['variant_price'] ?? $row['base_price']));
        $model = (string) $row['pricing_model'];
        $unit = (string) $row['unit'];
        $attrs = [];
        $out = [];

        // --- Laundry: layanan & ekspres (berlaku untuk per_kg maupun per pcs)
        $laundryMult = null;
        if ($row['product_type'] === 'laundry') {
            $services = $pa['services'] ?? null;
            $service = isset($a['service']) && is_string($a['service']) ? $a['service'] : null;
            if (is_array($services) && $services !== []) {
                if ($service === null || !in_array($service, $services, true)) {
                    throw $err('attributes.service', 'Layanan laundry tidak valid.');
                }
            }
            $express = ($a['express'] ?? false) === true;
            if ($express) {
                if (!isset($pa['express_surcharge_pct']) || !is_numeric($pa['express_surcharge_pct'])) {
                    throw $err('attributes.express', 'Layanan ekspres tidak tersedia untuk produk ini.');
                }
                $laundryMult = 100 + (float) $pa['express_surcharge_pct'];
                $priceCents = (int) round($priceCents * $laundryMult / 100);
            }
            $attrs['service'] = $service;
            $attrs['express'] = $express;
        }

        switch ($model) {
            case 'per_kg':
                $w = $this->qtyMilli($a['weight_kg'] ?? null);
                if ($w === null) {
                    throw $err('attributes.weight_kg', 'Berat (kg) wajib diisi, maks 3 desimal.');
                }
                $qtyMilli = $w;
                $gross = (int) round($priceCents * $qtyMilli / 1000);
                $attrs = ['weight_kg' => $qtyMilli / 1000] + $attrs;
                if (isset($a['pieces'])) {
                    $pcs = $this->toId($a['pieces']);
                    if ($pcs === null) {
                        throw $err('attributes.pieces', 'Jumlah potong tidak valid.');
                    }
                    $attrs['pieces'] = $pcs;
                }
                $out = ['qty_milli' => $qtyMilli, 'unit_price_cents' => $priceCents, 'gross_cents' => $gross];
                break;

            case 'per_m2':
                $wCm = $this->decimal($a['width_cm'] ?? null);
                $hCm = $this->decimal($a['height_cm'] ?? null);
                if ($wCm === null || $hCm === null || $wCm <= 0 || $hCm <= 0) {
                    throw $err('attributes.width_cm', 'Lebar dan tinggi (cm) wajib diisi dan > 0.');
                }
                if ($in['qty_milli'] % 1000 !== 0) {
                    throw $err('qty', 'Jumlah lembar harus bilangan bulat.');
                }
                if (isset($pa['max_width_cm']) && min($wCm, $hCm) > (float) $pa['max_width_cm']) {
                    throw $err('attributes.width_cm', 'Ukuran melebihi lebar bahan maksimum (' . $pa['max_width_cm'] . ' cm).');
                }
                $area = ($wCm * $hCm) / 10000;
                $billArea = max($area, (float) ($pa['min_area_m2'] ?? 0));
                $pricePerM2 = isset($va['price_per_m2']) && is_numeric($va['price_per_m2'])
                    ? (int) round(((float) $va['price_per_m2']) * 100)
                    : $priceCents;
                $perSheet = (int) round($billArea * $pricePerM2);
                $copies = intdiv($in['qty_milli'], 1000);
                $finishing = [];
                if (isset($a['finishing'])) {
                    $allowed = $pa['finishing_options'] ?? [];
                    if (!is_array($a['finishing']) || !array_is_list($a['finishing'])) {
                        throw $err('attributes.finishing', 'Format finishing tidak valid.');
                    }
                    foreach ($a['finishing'] as $f) {
                        if (!is_string($f) || !in_array($f, $allowed, true)) {
                            throw $err('attributes.finishing', 'Finishing tidak tersedia.');
                        }
                        $finishing[] = $f;
                    }
                }
                $attrs = [
                    'width_cm' => $this->numOut($wCm), 'height_cm' => $this->numOut($hCm), 'area_m2' => $this->numOut(round($area, 4)),
                    'price_per_m2' => $this->centsToNum($pricePerM2),
                ];
                if (isset($va['material'])) {
                    $attrs['material'] = $va['material'];
                }
                if ($finishing) {
                    $attrs['finishing'] = $finishing;
                }
                if (isset($a['upload_id'])) {
                    $uid = $this->toId($a['upload_id']);
                    if ($uid === null) {
                        throw $err('attributes.upload_id', 'ID file desain tidak valid.');
                    }
                    $attrs['upload_id'] = $uid;
                    $out['upload_id'] = $uid;
                }
                $out += ['qty_milli' => $in['qty_milli'], 'unit_price_cents' => $perSheet, 'gross_cents' => $perSheet * $copies];
                break;

            case 'per_hour':
                $minutes = $this->toId($a['duration_minutes'] ?? null);
                if ($minutes === null || $minutes > 1440) {
                    throw $err('attributes.duration_minutes', 'Durasi (menit) wajib diisi (1-1440).');
                }
                $rounding = max(1, (int) ($pa['rounding_minutes'] ?? 1));
                $billed = max($minutes, (int) ($pa['min_duration_minutes'] ?? 0));
                $billed = (int) (ceil($billed / $rounding) * $rounding);
                // qty (jam) dibulatkan 3 desimal; total dihitung dari menit agar tidak bergeser.
                $qtyMilli = (int) round($billed * 1000 / 60);
                $gross = (int) round($priceCents * $billed / 60);
                $attrs = ['duration_minutes' => $minutes, 'billed_minutes' => $billed];
                if (isset($a['start_at'])) {
                    $start = is_string($a['start_at']) ? $this->parseDateTime($a['start_at']) : null;
                    if ($start === null) {
                        throw $err('attributes.start_at', 'Waktu mulai tidak valid.');
                    }
                    $attrs['start_at'] = $start;
                    $attrs['end_at'] = (new DateTimeImmutable($start))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
                }
                $staff = isset($a['staff']) && is_string($a['staff']) ? trim($a['staff']) : '';
                if (($pa['requires_staff'] ?? false) === true && $staff === '') {
                    throw $err('attributes.staff', 'Petugas/staf wajib diisi.');
                }
                if ($staff !== '') {
                    $attrs['staff'] = $this->strCut($staff, 0, 100);
                }
                $out = ['qty_milli' => $qtyMilli, 'unit_price_cents' => $priceCents, 'gross_cents' => $gross];
                break;

            case 'unit':
            case 'fixed':
            default:
                if ($in['qty_milli'] % 1000 !== 0) {
                    throw $err('qty', 'Jumlah harus bilangan bulat.');
                }
                $qtyMilli = $in['qty_milli'];
                $gross = $priceCents * intdiv($qtyMilli, 1000);
                if ($row['product_type'] === 'laundry') {
                    $attrs['pieces'] = intdiv($qtyMilli, 1000);
                }
                $out = ['qty_milli' => $qtyMilli, 'unit_price_cents' => $priceCents, 'gross_cents' => $gross];
        }

        // --- Atribut khusus tipe produk
        if ($row['product_type'] === 'menu') {
            $mods = [];
            if (isset($a['modifiers'])) {
                if (!is_array($a['modifiers']) || !array_is_list($a['modifiers']) || count($a['modifiers']) > 10) {
                    throw $err('attributes.modifiers', 'Modifier tidak valid (maks 10).');
                }
                foreach ($a['modifiers'] as $m) {
                    if (!is_string($m) || $this->strLen($m) > 60) {
                        throw $err('attributes.modifiers', 'Modifier maksimal 60 karakter.');
                    }
                    $mods[] = trim($m);
                }
            }
            $attrs['modifiers'] = $mods;
            if (isset($pa['station'])) {
                $attrs['station'] = $pa['station'];
            }
        }
        if ($row['product_type'] === 'medicine' && isset($a['dosage_instruction']) && is_string($a['dosage_instruction'])) {
            $attrs['dosage_instruction'] = $this->strCut(trim($a['dosage_instruction']), 0, 150);
        }

        if ($out['gross_cents'] < 0 || $out['gross_cents'] > self::MAX_CENTS) {
            throw $err('qty', 'Nilai item di luar batas.');
        }
        $out['unit'] = $unit;
        $out['attributes'] = $attrs;
        return $out;
    }

    // =====================================================================
    // STOK BATCH (FEFO)
    // =====================================================================

    /** @return list<array{batch_id:int,qty_milli:int,cost_cents:int,extra:array}> */
    private function allocateBatches(PDO $pdo, int $tenantId, int $branchId, int $variantId, int $needMilli, ?int $batchId, string $today, string $productName): array
    {
        $sql = 'SELECT id, batch_number, expired_at, qty_remaining, cost_price FROM product_batches
                 WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? AND qty_remaining > 0 AND expired_at >= ?'
             . ($batchId !== null ? ' AND id = ?' : '')
             . ' ORDER BY expired_at ASC, id ASC FOR UPDATE';
        $params = [$tenantId, $branchId, $variantId, $today];
        if ($batchId !== null) {
            $params[] = $batchId;
        }

        $allocs = [];
        $upd = $pdo->prepare('UPDATE product_batches SET qty_remaining = qty_remaining - ? WHERE id = ? AND tenant_id = ?');
        foreach ($this->all($pdo, $sql, $params) as $b) {
            if ($needMilli <= 0) {
                break;
            }
            $take = min($needMilli, $this->dbMilli((string) $b['qty_remaining']));
            if ($take <= 0) {
                continue;
            }
            $upd->execute([$this->qty($take), (int) $b['id'], $tenantId]);
            $allocs[] = [
                'batch_id' => (int) $b['id'],
                'qty_milli' => $take,
                'cost_cents' => $this->dbCents((string) $b['cost_price']),
                'extra' => ['batch_number' => $b['batch_number'], 'expired_at' => $b['expired_at']],
            ];
            $needMilli -= $take;
        }
        if ($needMilli > 0) {
            throw new CheckoutException(409, 'Stok batch tidak cukup atau sudah kedaluwarsa untuk "' . $productName . '".', ['stock' => $productName]);
        }
        return $allocs;
    }

    // =====================================================================
    // VALIDASI & NORMALISASI INPUT
    // =====================================================================

    private function validateInput(array $in): array
    {
        $e = [];

        $key = $in['idempotency_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
            $e['idempotency_key'] = 'Harus berupa UUID.';
            $key = '';
        }
        $key = strtolower($key);

        $orderType = $in['order_type'] ?? null;
        if (!is_string($orderType) || !in_array($orderType, self::ORDER_TYPES, true)) {
            $e['order_type'] = 'Tipe pesanan tidak valid.';
            $orderType = 'retail';
        }

        $customerId = null;
        if (isset($in['customer_id'])) {
            $customerId = $this->toId($in['customer_id']);
            if ($customerId === null) {
                $e['customer_id'] = 'ID pelanggan tidak valid.';
            }
        }

        $tableId = null;
        if ($orderType === 'dine_in') {
            $tableId = $this->toId($in['table_id'] ?? null);
            if ($tableId === null) {
                $e['table_id'] = 'Meja wajib dipilih untuk dine-in.';
            }
        }

        $headerDisc = 0;
        if (isset($in['discount_amount'])) {
            $headerDisc = $this->moneyCents($in['discount_amount']);
            if ($headerDisc === null) {
                $e['discount_amount'] = 'Diskon tidak valid.';
                $headerDisc = 0;
            }
        }

        $notes = $this->optStr($in['notes'] ?? null, 500, 'notes', $e);

        $dueAt = null;
        if (isset($in['due_at']) && $in['due_at'] !== '') {
            $dueAt = is_string($in['due_at']) ? $this->parseDateTime($in['due_at']) : null;
            if ($dueAt === null) {
                $e['due_at'] = 'Format tanggal tidak valid (YYYY-MM-DD HH:MM).';
            }
        }
        $onAccount = $in['on_account'] ?? false;
        if (!is_bool($onAccount)) {
            $e['on_account'] = 'Status penjualan tempo tidak valid.';
            $onAccount = false;
        }
        if ($onAccount) {
            if (!in_array(Auth::role(), ['owner', 'manager'], true)) {
                throw new CheckoutException(403, 'Penjualan tempo hanya dapat disetujui pemilik atau manajer.');
            }
            if ($customerId === null) {
                $e['customer_id'] = 'Pilih pelanggan untuk mencatat piutang.';
            }
            if ($dueAt === null) {
                $e['due_at'] = 'Tanggal jatuh tempo wajib diisi untuk penjualan tempo.';
            }
        }

        // meta: hanya kunci yang di-whitelist.
        $meta = [];
        $rawMeta = $in['meta'] ?? [];
        if (!is_array($rawMeta)) {
            $e['meta'] = 'Meta harus berupa objek.';
            $rawMeta = [];
        }
        if (isset($rawMeta['guest_count'])) {
            $g = $this->toId($rawMeta['guest_count']);
            if ($g === null || $g > 999) {
                $e['meta.guest_count'] = 'Jumlah tamu tidak valid.';
            } else {
                $meta['guest_count'] = $g;
            }
        }
        if (isset($rawMeta['split_mode'])) {
            if (!is_string($rawMeta['split_mode']) || !in_array($rawMeta['split_mode'], self::SPLIT_MODES, true)) {
                $e['meta.split_mode'] = 'Mode split tidak valid.';
            } else {
                $meta['split_mode'] = $rawMeta['split_mode'];
            }
        }
        foreach (['pickup_due', 'deadline'] as $f) {
            if (isset($rawMeta[$f]) && $rawMeta[$f] !== '') {
                $dt = is_string($rawMeta[$f]) ? $this->parseDateTime($rawMeta[$f]) : null;
                if ($dt === null) {
                    $e["meta.$f"] = 'Format tanggal tidak valid.';
                } else {
                    $meta[$f] = $dt;
                }
            }
        }
        if (isset($rawMeta['delivery'])) {
            $meta['delivery'] = $rawMeta['delivery'] === true;
        }
        if (isset($rawMeta['prescription_id'])) {
            $rid = $this->toId($rawMeta['prescription_id']);
            if ($rid === null) {
                $e['meta.prescription_id'] = 'ID resep tidak valid.';
            } else {
                $meta['prescription_id'] = $rid;
            }
        }
        $pharm = $this->optStr($rawMeta['pharmacist'] ?? null, 100, 'meta.pharmacist', $e);
        if ($pharm !== null) {
            $meta['pharmacist'] = $pharm;
        }

        // items
        $items = [];
        $rawItems = $in['items'] ?? null;
        if (!is_array($rawItems) || $rawItems === [] || !array_is_list($rawItems)) {
            $e['items'] = 'Keranjang tidak boleh kosong.';
        } elseif (count($rawItems) > self::MAX_ITEMS) {
            $e['items'] = 'Maksimal ' . self::MAX_ITEMS . ' item per transaksi.';
        } else {
            foreach ($rawItems as $i => $it) {
                if (!is_array($it)) {
                    $e["items.$i"] = 'Format item tidak valid.';
                    continue;
                }
                $vid = $this->toId($it['variant_id'] ?? null);
                if ($vid === null) {
                    $e["items.$i.variant_id"] = 'variant_id tidak valid.';
                }
                $qty = array_key_exists('qty', $it) ? $this->qtyMilli($it['qty']) : 1000;
                if ($qty === null) {
                    $e["items.$i.qty"] = 'Qty harus > 0, maks 3 desimal.';
                    $qty = 1000;
                }
                $disc = 0;
                if (isset($it['discount_amount'])) {
                    $disc = $this->moneyCents($it['discount_amount']);
                    if ($disc === null) {
                        $e["items.$i.discount_amount"] = 'Diskon tidak valid.';
                        $disc = 0;
                    }
                }
                $grp = null;
                if (isset($it['split_group'])) {
                    $grp = $this->toId($it['split_group']);
                    if ($grp === null || $grp > 20) {
                        $e["items.$i.split_group"] = 'Grup split 1-20.';
                        $grp = null;
                    }
                }
                $attrs = $it['attributes'] ?? [];
                if (!is_array($attrs)) {
                    $e["items.$i.attributes"] = 'Atribut harus berupa objek.';
                    $attrs = [];
                }
                $items[] = [
                    'variant_id' => (int) $vid,
                    'qty_milli' => $qty,
                    'discount_cents' => $disc,
                    'split_group' => $grp,
                    'notes' => $this->optStr($it['notes'] ?? null, 255, "items.$i.notes", $e),
                    'attributes' => $attrs,
                ];
            }
        }

        // payments
        $payments = [];
        $rawPay = $in['payments'] ?? null;
        if (!is_array($rawPay) || (!$onAccount && $rawPay === []) || !array_is_list($rawPay)) {
            $e['payments'] = 'Minimal satu pembayaran.';
        } elseif (count($rawPay) > self::MAX_PAYMENTS) {
            $e['payments'] = 'Maksimal ' . self::MAX_PAYMENTS . ' pembayaran.';
        } else {
            foreach ($rawPay as $i => $p) {
                if (!is_array($p)) {
                    $e["payments.$i"] = 'Format pembayaran tidak valid.';
                    continue;
                }
                $method = $p['method'] ?? null;
                if (!is_string($method) || !in_array($method, self::PAYMENT_METHODS, true)) {
                    $e["payments.$i.method"] = 'Metode pembayaran tidak valid.';
                    $method = 'cash';
                }
                $amt = $this->moneyCents($p['amount'] ?? null);
                if ($amt === null || $amt <= 0) {
                    $e["payments.$i.amount"] = 'Nominal harus > 0.';
                    $amt = 0;
                }
                $grp = null;
                if (isset($p['split_group'])) {
                    $grp = $this->toId($p['split_group']);
                    if ($grp === null || $grp > 20) {
                        $e["payments.$i.split_group"] = 'Grup split 1-20.';
                        $grp = null;
                    }
                }
                $payments[] = [
                    'method' => $method,
                    'amount_cents' => $amt,
                    'reference_no' => $this->optStr($p['reference_no'] ?? null, 80, "payments.$i.reference_no", $e),
                    'split_group' => $grp,
                ];
            }
        }

        if ($e) {
            throw new CheckoutException(422, 'Data transaksi tidak valid.', $e);
        }

        return [
            'idempotency_key' => $key,
            'order_type' => $orderType,
            'customer_id' => $customerId,
            'table_id' => $tableId,
            'discount_cents' => $headerDisc,
            'notes' => $notes,
            'due_at' => $dueAt,
            'on_account' => $onAccount,
            'meta' => $meta,
            'items' => $items,
            'payments' => $payments,
        ];
    }

    // =====================================================================
    // RESPONS STRUK
    // =====================================================================

    private function buildReceipt(PDO $pdo, int $tenantId, int $trxId): array
    {
        $t = $this->one($pdo,
            'SELECT t.id, t.invoice_no, t.order_type, t.status, t.payment_status, t.fulfillment_status, t.meta,
                    t.subtotal, t.discount_amount, t.service_charge, t.tax_amount, t.grand_total,
                    t.paid_total, t.change_amount, t.due_at, t.created_at,
                    c.name AS customer_name, c.phone AS customer_phone
               FROM transactions t
               LEFT JOIN customers c ON c.id = t.customer_id AND c.tenant_id = t.tenant_id
              WHERE t.id = ? AND t.tenant_id = ?',
            [$trxId, $tenantId]) ?? [];
        if ($t) {
            $t['id'] = (int) $t['id'];
            $meta = json_decode((string) ($t['meta'] ?? ''), true);
            $t['tax_mode'] = is_array($meta) && ($meta['tax_mode'] ?? '') === 'inclusive'
                ? 'inclusive' : 'exclusive';
            unset($t['meta']);
        }
        return [
            'transaction' => $t,
            'items' => $this->all($pdo,
                'SELECT item_name, qty, unit, unit_price, discount_amount, line_total, batch_id, split_group
                   FROM transaction_items WHERE transaction_id = ? AND tenant_id = ? ORDER BY id',
                [$trxId, $tenantId]),
            'payments' => $this->all($pdo,
                'SELECT method, amount, reference_no, split_group
                   FROM transaction_payments WHERE transaction_id = ? AND tenant_id = ? ORDER BY id',
                [$trxId, $tenantId]),
        ];
    }

    private function findByIdempotency(PDO $pdo, int $tenantId, string $key, bool $lock): ?int
    {
        $r = $this->one($pdo,
            'SELECT id FROM transactions WHERE tenant_id = ? AND idempotency_key = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$tenantId, $key]);
        return $r === null ? null : (int) $r['id'];
    }

    // =====================================================================
    // HELPER
    // =====================================================================

    private function rollback(PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            error_log('[checkout] rollback gagal: ' . $e->getMessage());
        }
    }

    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    private function all(PDO $pdo, string $sql, array $params): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Decode kolom JSON dari DB menjadi array (aman terhadap NULL/rusak). */
    private function decode($json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    private function jsonObj(array $a): string
    {
        return json_encode($a === [] ? new \stdClass() : $a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Sen (int) -> "12345.67" */
    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Seperseribu (int) -> "12.345" */
    private function qty(int $milli): string
    {
        return number_format($milli / 1000, 3, '.', '');
    }

    private function dbCents(string $s): int
    {
        return (int) round(((float) $s) * 100);
    }

    private function dbMilli(string $s): int
    {
        return (int) round(((float) $s) * 1000);
    }

    /** Panjang karakter UTF-8 tanpa bergantung pada ekstensi mbstring. */
    private function strLen(string $s): int
    {
        return function_exists('mb_strlen') ? mb_strlen($s) : (int) preg_match_all('/./us', $s);
    }

    /** Potong string UTF-8 tanpa bergantung pada ekstensi mbstring. */
    private function strCut(string $s, int $start, int $len): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($s, $start, $len);
        }
        return (string) (preg_match('/^.{0,' . $len . '}/us', $s, $m) ? $m[0] : '');
    }

    /** Float bulat -> int agar JSON rapi (300 bukan 300.0). */
    private function numOut(float $f)
    {
        return fmod($f, 1.0) === 0.0 ? (int) $f : $f;
    }

    private function centsToNum(int $cents)
    {
        return $cents % 100 === 0 ? intdiv($cents, 100) : $cents / 100;
    }

    /** Bagi $total (int) sesuai bobot; sisa pembulatan masuk ke elemen terakhir. */
    private function splitProportional(int $total, array $weights): array
    {
        $n = count($weights);
        if ($n <= 1) {
            return [$total];
        }
        $sumW = array_sum($weights);
        $parts = [];
        $acc = 0;
        for ($i = 0; $i < $n - 1; $i++) {
            $p = (int) round($total * $weights[$i] / $sumW);
            $parts[] = $p;
            $acc += $p;
        }
        $parts[] = $total - $acc;
        return $parts;
    }

    /** ID positif dari int atau string digit. */
    private function toId($v): ?int
    {
        if (is_int($v)) {
            return $v > 0 ? $v : null;
        }
        if (is_string($v) && ctype_digit($v) && strlen($v) <= 18 && (int) $v > 0) {
            return (int) $v;
        }
        return null;
    }

    /** Nominal uang -> sen. Maks 2 desimal, tidak negatif. */
    private function moneyCents($v): ?int
    {
        if (is_int($v)) {
            if ($v < 0 || $v > 1000000000000) {
                return null;
            }
            $c = $v * 100;
        } elseif (is_float($v)) {
            if ($v < 0 || $v > 1e12) {
                return null;
            }
            $c = $v * 100;
            if (abs($c - round($c)) > 1e-6) {
                return null;
            }
            $c = (int) round($c);
        } elseif (is_string($v) && preg_match('/^\d{1,12}(\.\d{1,2})?$/', $v)) {
            $c = (int) round(((float) $v) * 100);
        } else {
            return null;
        }
        return ($c < 0 || $c > self::MAX_CENTS) ? null : $c;
    }

    /** Qty -> seperseribu. > 0, maks 3 desimal, maks 999999. */
    private function qtyMilli($v): ?int
    {
        if (is_int($v)) {
            $m = $v > 0 && $v <= 999999 ? $v * 1000 : 0;
        } elseif (is_float($v)) {
            $m = $v * 1000;
            if ($v <= 0 || $v > 999999 || abs($m - round($m)) > 1e-6) {
                return null;
            }
            $m = (int) round($m);
        } elseif (is_string($v) && preg_match('/^\d{1,6}(\.\d{1,3})?$/', $v)) {
            $m = (int) round(((float) $v) * 1000);
        } else {
            return null;
        }
        return $m > 0 ? $m : null;
    }

    /** Angka desimal (maks 2 digit) untuk dimensi, 0 < x <= 999999. */
    private function decimal($v): ?float
    {
        if (is_int($v) || is_float($v)) {
            $f = (float) $v;
            if ($f < 0 || $f > 999999 || abs($f * 100 - round($f * 100)) > 1e-6) {
                return null;
            }
            return round($f, 2);
        }
        if (is_string($v) && preg_match('/^\d{1,6}(\.\d{1,2})?$/', $v)) {
            return round((float) $v, 2);
        }
        return null;
    }

    /** String opsional dengan batas panjang; mengisi $e bila melebihi. */
    private function optStr($v, int $max, string $field, array &$e): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (!is_string($v) || $this->strLen($v) > $max) {
            $e[$field] = "Teks tidak valid atau melebihi $max karakter.";
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /** Terima 'Y-m-d H:i[:s]' atau 'Y-m-dTH:i[:s]'; kembalikan 'Y-m-d H:i:s'. */
    private function parseDateTime(string $s): ?string
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $fmt) {
            $dt = DateTimeImmutable::createFromFormat('!' . $fmt, $s);
            $err = DateTimeImmutable::getLastErrors();
            if ($dt !== false && ($err === false || ($err['warning_count'] === 0 && $err['error_count'] === 0))) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
        return null;
    }
}
