<?php
declare(strict_types=1);

/**
 * app/Controllers/PosController.php
 *
 *   GET /pos            -> index()     halaman kasir (views/pos/index.php)
 *   GET /api/products   -> products()  pencarian produk untuk kasir (JSON)
 *
 * ============================================================================
 * KELAS PENDUKUNG YANG DIASUMSIKAN SUDAH ADA (namespace App\Core) - TIDAK diimplementasikan di sini
 * ============================================================================
 *   Database::pdo(): PDO                    // ERRMODE_EXCEPTION, emulate_prepares=false
 *   Auth::check(): bool
 *   Auth::userId(): int
 *   Auth::tenantId(): ?int
 *   Auth::branchId(): ?int                  // cabang AKTIF di sesi
 *   Csrf::token(): string                   // token CSRF sesi (untuk meta tag)
 *   Request::query(string $key): ?string    // nilai $_GET atau null
 *   Response::send(int $status, bool $success, string $message, ?array $data = null, ?array $errors = null): void  // exit
 *   Response::redirect(string $url): void   // header Location + exit
 *   View::render(string $view, array $data = []): void   // mis. View::render('pos/index', [...])
 *
 * Konfigurasi: APP_BASE_URL di .env ("" bila di root domain, "/pos" bila di subfolder), dibaca lewat Router::base().
 *
 * ============================================================================
 * KONTRAK  GET /api/products?q=<teks>|barcode=<kode>&limit=<1-100>
 * ============================================================================
 *  - q       : tiap kata harus cocok (AND) dengan nama produk / nama varian / SKU / barcode.
 *  - barcode : cocok PERSIS dengan barcode atau SKU varian (untuk pemindai barcode).
 *  - Hanya produk/varian aktif, milik tenant sesi, dan bertipe modul yang AKTIF di toko.
 *  - stock   : stok cabang aktif (string 3 desimal). Produk batch: jumlah batch yang BELUM kedaluwarsa.
 *              null bila produk tidak melacak stok (jasa, laundry, percetakan, menu).
 *  - batches : hanya untuk produk batch; batch belum kedaluwarsa, urut FEFO, maks 20 per varian.
 *  - HPP (cost_price) TIDAK pernah dikirim ke kasir.
 */

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class PosController
{
    private const DEFAULT_LIMIT = 60;
    private const MAX_LIMIT = 100;
    private const MAX_TERMS = 4;
    private const MAX_BATCHES_PER_VARIANT = 20;

    private const PRODUCT_TYPES_BY_MODULE = [
        'retail'   => ['goods'],
        'fnb'      => ['menu'],
        'pharmacy' => ['medicine'],
        'laundry'  => ['laundry'],
        'printing' => ['print'],
        'service'  => ['service'],
    ];

    /** Atribut produk yang boleh dikirim ke kasir (whitelist). */
    private const PUBLIC_PRODUCT_ATTRS = [
        'requires_prescription', 'generic_name', 'strength', 'dosage_form',
        'services', 'express_surcharge_pct', 'est_hours',
        'min_area_m2', 'max_width_cm', 'finishing_options', 'input_unit',
        'min_duration_minutes', 'rounding_minutes', 'requires_staff',
        'modifier_groups', 'station', 'prep_minutes',
    ];

    // =====================================================================
    // GET /pos
    // =====================================================================

    public function index(): void
    {
        $base = Router::base();
        if (!Auth::check()) {
            Response::redirect($base . '/login');
            return;
        }

        try {
            $pdo = Database::pdo();
            OperationsController::ensureSchema($pdo);
            $ctx = $this->context($pdo);
        } catch (PosAccessException $e) {
            http_response_code($e->status);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
               . '<title>Akses ditolak</title></head><body style="font-family:sans-serif;padding:2rem">'
               . '<h1>Akses ditolak</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p></body></html>';
            return;
        } catch (Throwable $e) {
            error_log('[pos] index gagal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            http_response_code(500);
            echo 'Terjadi kesalahan pada server.';
            return;
        }

        $shiftStatement = $pdo->prepare(
            'SELECT id FROM cashier_shifts
              WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
              ORDER BY id DESC LIMIT 1'
        );
        $shiftStatement->execute([$ctx['tenant_id'], $ctx['branch_id']]);
        $shiftOpen = $shiftStatement->fetchColumn() !== false;
        $tables = [];
        if (in_array('fnb', $ctx['modules'], true)) {
            $st = $pdo->prepare('SELECT id, name, status FROM dining_tables WHERE tenant_id = ? AND branch_id = ? AND deleted_at IS NULL ORDER BY name');
            $st->execute([$ctx['tenant_id'], $ctx['branch_id']]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
                $tables[] = ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'status' => (string) $t['status']];
            }
        }

        $svcPct = (float) ($ctx['module_settings']['fnb']['service_charge_pct'] ?? 0);

        View::render('pos/index', [
            'csrf' => Csrf::token(),
            'posConfig' => [
                'baseUrl' => $base,
                'tenantName' => $ctx['tenant_name'],
                'branchName' => $ctx['branch_name'],
                'businessPhone' => $ctx['business_phone'],
                'branchAddress' => $ctx['branch_address'],
                'branchPhone' => $ctx['branch_phone'],
                'currency' => $ctx['currency'],
                'modules' => $ctx['modules'],
                'tables' => $tables,
                'shiftOpen' => $shiftOpen,
                // Hanya untuk estimasi di layar; CheckoutController menghitung ulang di server.
                'taxPct' => $ctx['tax_pct'],
                'taxMode' => $ctx['tax_mode'],
                'servicePct' => max(0.0, min(100.0, $svcPct)),
                'canDiscount' => $ctx['can_discount'],
                'canReturn' => in_array(Auth::role(), ['owner', 'manager'], true),
                'canManage' => in_array(Auth::role(), ['owner', 'manager'], true),
                'role' => Auth::role(),
                'tenantId' => $ctx['tenant_id'],
                'branchId' => $ctx['branch_id'],
            ],
        ]);
    }

    // =====================================================================
    // GET /api/products
    // =====================================================================

    public function products(): void
    {
        header('Cache-Control: no-store');
        try {
            if (!Auth::check()) {
                throw new PosAccessException(401, 'Sesi berakhir, silakan login kembali.');
            }
            $pdo = Database::pdo();
            $ctx = $this->context($pdo);

            // Tipe produk yang boleh tampil = tipe milik modul aktif.
            $types = [];
            foreach ($ctx['modules'] as $m) {
                foreach (self::PRODUCT_TYPES_BY_MODULE[$m] ?? [] as $t) {
                    $types[] = $t;
                }
            }
            if (!$types) {
                Response::send(200, true, 'OK', ['items' => []]);
                return;
            }

            $limit = (int) (Request::query('limit') ?? self::DEFAULT_LIMIT);
            $limit = max(1, min(self::MAX_LIMIT, $limit > 0 ? $limit : self::DEFAULT_LIMIT));

            $tenantId = $ctx['tenant_id'];
            $branchId = $ctx['branch_id'];
            // Urutan placeholder mengikuti kemunculan di SQL: sl.branch_id, sl.tenant_id (JOIN), v.tenant_id, p.tenant_id (WHERE).
            $params = [$branchId, $tenantId, $tenantId, $tenantId];
            $where = ['v.tenant_id = ?', 'p.tenant_id = ?', 'v.is_active = 1', 'v.deleted_at IS NULL', 'p.is_active = 1', 'p.deleted_at IS NULL'];

            $where[] = 'p.product_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
            array_push($params, ...$types);

            $barcode = trim((string) (Request::query('barcode') ?? ''));
            $q = trim((string) (Request::query('q') ?? ''));
            if ($barcode !== '') {
                if (strlen($barcode) > 64) {
                    Response::send(200, true, 'OK', ['items' => []]);
                    return;
                }
                $where[] = '(v.barcode = ? OR v.sku = ?)';
                array_push($params, $barcode, $barcode);
            } elseif ($q !== '') {
                $terms = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                foreach (array_slice($terms, 0, self::MAX_TERMS) as $term) {
                    // Escape wildcard LIKE dengan karakter '|' agar tidak bergantung pada mode SQL.
                    $like = '%' . str_replace(['|', '%', '_'], ['||', '|%', '|_'], substr($term, 0, 60)) . '%';
                    $where[] = "(p.name LIKE ? ESCAPE '|' OR v.name LIKE ? ESCAPE '|' OR v.sku LIKE ? ESCAPE '|' OR v.barcode LIKE ? ESCAPE '|')";
                    array_push($params, $like, $like, $like, $like);
                }
            }

            $sql = 'SELECT v.id AS variant_id, v.product_id, v.name AS variant_name, v.sku, v.barcode,
                           v.price AS variant_price, v.attributes AS variant_attributes,
                           p.name AS product_name, p.product_type, p.pricing_model, p.unit, p.base_price,
                           p.track_stock, p.track_batch, p.attributes AS product_attributes,
                           sl.qty AS stock_qty
                      FROM product_variants v
                      JOIN products p ON p.id = v.product_id
                      LEFT JOIN stock_levels sl ON sl.variant_id = v.id AND sl.branch_id = ? AND sl.tenant_id = ?
                     WHERE ' . implode(' AND ', $where) . '
                     ORDER BY p.name ASC, v.is_default DESC, v.name ASC
                     LIMIT ' . $limit;
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Batch (apotek): hanya yang belum kedaluwarsa, urut FEFO.
            $batchIds = [];
            foreach ($rows as $r) {
                if ((int) $r['track_batch'] === 1) {
                    $batchIds[] = (int) $r['variant_id'];
                }
            }
            $batches = $batchIds ? $this->loadBatches($pdo, $tenantId, $branchId, $batchIds, $ctx['today']) : [];

            $items = [];
            foreach ($rows as $r) {
                $vid = (int) $r['variant_id'];
                $trackBatch = (int) $r['track_batch'] === 1;
                $trackStock = (int) $r['track_stock'] === 1;

                $stock = null;
                $variantBatches = [];
                if ($trackBatch) {
                    $variantBatches = $batches[$vid] ?? [];
                    $sumMilli = 0;
                    foreach ($variantBatches['all'] ?? [] as $b) {
                        $sumMilli += (int) round(((float) $b['qty_remaining']) * 1000);
                    }
                    $stock = number_format($sumMilli / 1000, 3, '.', '');
                    $variantBatches = $variantBatches['list'] ?? [];
                } elseif ($trackStock) {
                    $stock = number_format((float) ($r['stock_qty'] ?? 0), 3, '.', '');
                }

                $pa = $this->decode($r['product_attributes']);
                $va = $this->decode($r['variant_attributes']);
                $attrs = array_intersect_key($pa, array_flip(self::PUBLIC_PRODUCT_ATTRS));
                if (isset($va['material']) && is_string($va['material'])) {
                    $attrs['material'] = $va['material'];
                }

                $price = $r['variant_price'] ?? $r['base_price'];
                $perM2 = isset($va['price_per_m2']) && is_numeric($va['price_per_m2'])
                    ? number_format((float) $va['price_per_m2'], 2, '.', '') : null;

                $items[] = [
                    'variant_id' => $vid,
                    'product_id' => (int) $r['product_id'],
                    'name' => (string) $r['product_name'],
                    'variant_name' => (string) $r['variant_name'],
                    'product_type' => (string) $r['product_type'],
                    'pricing_model' => (string) $r['pricing_model'],
                    'unit' => (string) $r['unit'],
                    'price' => number_format((float) $price, 2, '.', ''),
                    'price_per_m2' => $perM2,
                    'barcode' => $r['barcode'] !== null ? (string) $r['barcode'] : null,
                    'stock' => $stock,
                    'attributes' => $attrs === [] ? new \stdClass() : $attrs,
                    'batches' => $variantBatches,
                ];
            }

            Response::send(200, true, 'OK', ['items' => $items]);
        } catch (PosAccessException $e) {
            Response::send($e->status, false, $e->getMessage());
        } catch (Throwable $e) {
            error_log('[pos] products gagal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::send(500, false, 'Terjadi kesalahan pada server.');
        }
    }

    // =====================================================================
    // KONTEKS TENANT / CABANG / PENGGUNA (read-only)
    // =====================================================================

    /**
     * Validasi sesi terhadap database dan kumpulkan konteks.
     * @throws PosAccessException bila tenant/akun/cabang tidak boleh memakai kasir
     */
    private function context(PDO $pdo): array
    {
        $tenantId = (int) Auth::tenantId();
        $branchId = (int) Auth::branchId();
        $userId = (int) Auth::userId();
        if ($tenantId <= 0 || $branchId <= 0 || $userId <= 0) {
            throw new PosAccessException(403, 'Akun belum terhubung ke tenant/cabang aktif.');
        }

        $tenant = $this->one($pdo, 'SELECT name, phone, plan_id, status, subscription_ends_at, timezone FROM tenants WHERE id = ? AND deleted_at IS NULL', [$tenantId]);
        if ($tenant === null) {
            throw new PosAccessException(403, 'Tenant tidak ditemukan.');
        }
        try {
            $tz = new DateTimeZone((string) $tenant['timezone']);
        } catch (Throwable $e) {
            $tz = new DateTimeZone('Asia/Jakarta');
        }
        $now = new DateTimeImmutable('now', $tz);

        $ownerAccountSchema = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name IN ('owner_accounts', 'owner_account_tenants')"
        )->fetchColumn() === 2;
        $ownerAccount = $ownerAccountSchema
            ? $this->one($pdo,
                'SELECT oa.plan_id, oa.status, oa.subscription_ends_at
                   FROM owner_account_tenants oat
                   JOIN owner_accounts oa ON oa.id = oat.owner_account_id
                  WHERE oat.tenant_id = ?',
                [$tenantId])
            : null;
        $subscriptionStatus = $ownerAccount['status'] ?? $tenant['status'];
        $subscriptionEndsAt = $ownerAccount['subscription_ends_at'] ?? $tenant['subscription_ends_at'];
        $subscriptionPlanId = (int) ($ownerAccount['plan_id'] ?? $tenant['plan_id']);
        if (!in_array($subscriptionStatus, ['trial', 'active'], true)) {
            throw new PosAccessException(403, 'Langganan toko tidak aktif. Hubungi administrator.');
        }
        if ($subscriptionEndsAt !== null && new DateTimeImmutable((string) $subscriptionEndsAt, $tz) <= $now) {
            throw new PosAccessException(403, 'Masa langganan telah berakhir. Silakan perpanjang.');
        }

        $plan = $this->one($pdo, 'SELECT is_active, allowed_modules FROM plans WHERE id = ?', [$subscriptionPlanId]);
        if ($plan === null || (int) $plan['is_active'] !== 1) {
            throw new PosAccessException(403, 'Paket langganan non-aktif.');
        }

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
        if ($user === null || (int) $user['is_active'] !== 1
            || !in_array($user['role'], ['owner', 'manager', 'cashier'], true)
            || $user['role'] !== Auth::role()) {
            throw new PosAccessException(403, 'Akun tidak berhak memakai kasir.');
        }
        if ($user['branch_id'] !== null && (int) $user['branch_id'] !== $branchId) {
            throw new PosAccessException(403, 'Akun tidak terdaftar pada cabang ini.');
        }

        $branch = $this->one($pdo, 'SELECT name, address, phone, settings FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL', [$branchId, $tenantId]);
        if ($branch === null) {
            throw new PosAccessException(403, 'Cabang tidak aktif atau tidak ditemukan.');
        }

        $modules = [];
        $moduleSettings = [];
        $st = $pdo->prepare('SELECT module_code, settings FROM tenant_modules WHERE tenant_id = ? AND is_active = 1');
        $st->execute([$tenantId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $modules[] = (string) $m['module_code'];
            $moduleSettings[(string) $m['module_code']] = $this->decode($m['settings']);
        }
        if ($plan['allowed_modules'] !== null) {
            $modules = array_values(array_intersect($modules, array_map('strval', $this->decode($plan['allowed_modules']))));
        }
        $modules = array_values(array_intersect($modules, array_keys(self::PRODUCT_TYPES_BY_MODULE)));

        $perms = $this->decode($user['permissions']);
        $settings = $this->decode($branch['settings']);
        $currency = (string) ($this->one($pdo, 'SELECT currency FROM tenants WHERE id = ?', [$tenantId])['currency'] ?? 'IDR');

        return [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'tenant_name' => (string) $tenant['name'],
            'business_phone' => (string) ($tenant['phone'] ?? ''),
            'branch_name' => (string) $branch['name'],
            'branch_address' => (string) ($branch['address'] ?? ''),
            'branch_phone' => (string) ($branch['phone'] ?? ''),
            'currency' => preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'IDR',
            'today' => $now->format('Y-m-d'),
            'modules' => $modules,
            'module_settings' => $moduleSettings,
            'tax_pct' => max(0.0, min(100.0, (float) ($settings['tax_pct'] ?? 0))),
            'tax_mode' => in_array($settings['tax_mode'] ?? '', ['none', 'inclusive', 'exclusive'], true)
                ? $settings['tax_mode'] : 'exclusive',
            'can_discount' => in_array($user['role'], ['owner', 'manager'], true) || ($perms['can_discount'] ?? false) === true,
        ];
    }

    /**
     * Batch belum kedaluwarsa per varian, urut FEFO.
     * @return array<int, array{all: list<array>, list: list<array>}>
     */
    private function loadBatches(PDO $pdo, int $tenantId, int $branchId, array $variantIds, string $today): array
    {
        $ph = implode(',', array_fill(0, count($variantIds), '?'));
        $st = $pdo->prepare(
            "SELECT id, variant_id, batch_number, expired_at, qty_remaining
               FROM product_batches
              WHERE tenant_id = ? AND branch_id = ? AND variant_id IN ($ph) AND qty_remaining > 0 AND expired_at >= ?
              ORDER BY variant_id ASC, expired_at ASC, id ASC"
        );
        $st->execute(array_merge([$tenantId, $branchId], $variantIds, [$today]));

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $vid = (int) $b['variant_id'];
            $out[$vid]['all'][] = $b;
            if (count($out[$vid]['list'] ?? []) < self::MAX_BATCHES_PER_VARIANT) {
                $out[$vid]['list'][] = [
                    'id' => (int) $b['id'],
                    'batch_number' => (string) $b['batch_number'],
                    'expired_at' => (string) $b['expired_at'],
                    'qty_remaining' => number_format((float) $b['qty_remaining'], 3, '.', ''),
                ];
            }
        }
        return $out;
    }

    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

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
}

/** Penolakan akses terkontrol (dipetakan ke HTTP code). */
final class PosAccessException extends \RuntimeException
{
    public int $status;

    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}
