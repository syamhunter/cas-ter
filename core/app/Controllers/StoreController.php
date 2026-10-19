<?php
declare(strict_types=1);

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

final class StoreController
{
    private const PRODUCT_MODULES = [
        'goods' => 'retail',
        'menu' => 'fnb',
        'medicine' => 'pharmacy',
        'laundry' => 'laundry',
        'print' => 'printing',
        'service' => 'service',
    ];

    private const PRODUCT_LABELS = [
        'goods' => 'Produk retail',
        'menu' => 'Menu makanan/minuman',
        'medicine' => 'Obat',
        'laundry' => 'Layanan laundry',
        'print' => 'Jasa percetakan',
        'service' => 'Layanan jasa',
    ];

    private const FULFILLMENT_STEPS = [
        'dine_in' => ['baru', 'diproses', 'siap', 'disajikan', 'selesai'],
        'takeaway' => ['baru', 'diproses', 'siap', 'diserahkan', 'selesai'],
        'laundry' => ['diterima', 'dicuci', 'disetrika', 'siap_diambil', 'selesai'],
        'printing' => ['antri_cetak', 'proses_cetak', 'finishing', 'siap_diambil', 'selesai'],
        'service' => ['menunggu', 'dikerjakan', 'siap', 'selesai'],
    ];

    public function index(): void
    {
        try {
            $pdo = Database::pdo();
            $context = $this->context($pdo);
            $tenantId = $context['tenant_id'];
            $branchId = $context['branch_id'];

            $products = $this->all($pdo,
                'SELECT p.id, p.name, p.product_type, p.pricing_model, p.unit, p.base_price, p.cost_price,
                        p.track_stock, p.track_batch, p.min_stock, p.is_active, p.attributes,
                        c.name AS category_name, v.id AS variant_id, v.name AS variant_name,
                        v.sku, v.barcode, v.price AS variant_price, v.cost_price AS variant_cost,
                        v.attributes AS variant_attributes,
                        COALESCE(sl.qty, 0) AS stock_qty
                   FROM products p
                   LEFT JOIN categories c ON c.id = p.category_id AND c.tenant_id = p.tenant_id
                   JOIN product_variants v ON v.product_id = p.id AND v.tenant_id = p.tenant_id
                        AND v.is_default = 1 AND v.deleted_at IS NULL
                   LEFT JOIN stock_levels sl ON sl.variant_id = v.id AND sl.branch_id = ? AND sl.tenant_id = p.tenant_id
                  WHERE p.tenant_id = ? AND p.deleted_at IS NULL
                  ORDER BY p.is_active DESC, p.name, p.id',
                [$branchId, $tenantId]);
            $customers = $this->all($pdo,
                'SELECT id, name, phone, email, created_at FROM customers
                  WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY name LIMIT 100',
                [$tenantId]);
            $orders = $this->all($pdo,
                'SELECT t.id, t.invoice_no, t.order_type, t.fulfillment_status, t.due_at,
                        t.created_at, t.grand_total, t.notes, c.name AS customer_name,
                        u.name AS cashier_name, COUNT(ti.id) AS item_count,
                        GROUP_CONCAT(ti.item_name ORDER BY ti.id SEPARATOR ", ") AS item_names
                   FROM transactions t
                   LEFT JOIN customers c ON c.id = t.customer_id AND c.tenant_id = t.tenant_id
                   JOIN users u ON u.id = t.cashier_id
                   LEFT JOIN transaction_items ti ON ti.transaction_id = t.id AND ti.tenant_id = t.tenant_id
                  WHERE t.tenant_id = ? AND t.branch_id = ? AND t.status = \'paid\'
                    AND t.fulfillment_status IS NOT NULL
                  GROUP BY t.id
                  ORDER BY CASE WHEN t.due_at IS NULL THEN 1 ELSE 0 END, t.due_at, t.created_at
                  LIMIT 100',
                [$tenantId, $branchId]);
            $batches = $this->all($pdo,
                'SELECT pb.id, pb.batch_number, pb.expired_at, pb.qty_remaining,
                        p.name AS product_name, v.name AS variant_name
                   FROM product_batches pb
                   JOIN product_variants v ON v.id = pb.variant_id AND v.tenant_id = pb.tenant_id
                   JOIN products p ON p.id = v.product_id AND p.tenant_id = pb.tenant_id
                  WHERE pb.tenant_id = ? AND pb.branch_id = ? AND pb.qty_remaining > 0
                    AND pb.expired_at <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                  ORDER BY pb.expired_at, p.name LIMIT 100',
                [$tenantId, $branchId]);
            $flash = $_SESSION['store_flash'] ?? null;
            unset($_SESSION['store_flash']);
            $moduleLabels = [
                'retail' => 'Retail / Toko', 'fnb' => 'Cafe & Resto', 'pharmacy' => 'Apotek',
                'laundry' => 'Laundry', 'printing' => 'Percetakan', 'service' => 'Jasa',
            ];
            $activeModules = array_values(array_intersect(
                array_keys($moduleLabels),
                $context['modules']
            ));
            $availableTypes = [];
            foreach (self::PRODUCT_MODULES as $type => $module) {
                if (in_array($module, $activeModules, true)) {
                    $availableTypes[$type] = self::PRODUCT_LABELS[$type];
                }
            }

            View::render('store/index', [
                'base' => Router::base(),
                'csrf' => Csrf::token(),
                'context' => $context,
                'products' => $products,
                'customers' => $customers,
                'orders' => $orders,
                'batches' => $batches,
                'activeModules' => $activeModules,
                'moduleLabels' => $moduleLabels,
                'availableTypes' => $availableTypes,
                'productLabels' => self::PRODUCT_LABELS,
                'fulfillmentSteps' => self::FULFILLMENT_STEPS,
                'flash' => is_array($flash) ? $flash : null,
            ]);
        } catch (Throwable $e) {
            error_log('[store-management] index gagal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            if ($e instanceof \RuntimeException && !($e instanceof \PDOException)) {
                Response::page(403, 'Akses manajemen toko ditolak', $e->getMessage());
            }
            Response::page(500, 'Manajemen toko tidak tersedia', 'Data manajemen toko gagal dimuat. Silakan coba lagi.');
        }
    }

    public function saveProduct(): void
    {
        $pdo = Database::pdo();
        try {
            $context = $this->context($pdo);
            $tenantId = $context['tenant_id'];
            $branchId = $context['branch_id'];
            $name = trim((string) Request::post('name'));
            $type = (string) Request::post('product_type');
            $pricing = (string) Request::post('pricing_model');
            $unit = trim((string) Request::post('unit'));
            $categoryName = trim((string) Request::post('category'));
            $sku = trim((string) Request::post('sku'));
            $barcode = trim((string) Request::post('barcode'));
            $price = $this->decimal(Request::post('price'));
            $cost = $this->decimal(Request::post('cost_price') ?: '0');
            $initialQty = $this->decimal(Request::post('initial_qty') ?: '0');
            $minStock = $this->decimal(Request::post('min_stock') ?: '0');
            $trackStock = isset($_POST['track_stock']);
            $trackBatch = isset($_POST['track_batch']);
            $module = self::PRODUCT_MODULES[$type] ?? '';

            if ($this->length($name) < 2 || $this->length($name) > 190
                || !in_array($module, $context['modules'], true)
                || !$this->validPricing($type, $pricing)
                || $unit === '' || $this->length($unit) > 20
                || $price === null || $price < 0 || $price > 9999999999999
                || $cost === null || $cost < 0 || $cost > 9999999999999
                || $initialQty === null || $initialQty < 0 || $initialQty > 999999999
                || $minStock === null || $minStock < 0 || $minStock > 999999999
                || $this->length($categoryName) > 120
                || strlen($sku) > 60 || strlen($barcode) > 64
                || ($trackBatch && ($type !== 'medicine' || !$trackStock))) {
                throw new \RuntimeException('Periksa nama, bidang, harga, satuan, barcode/SKU, dan pengaturan stok produk.');
            }
            if (in_array($type, ['print', 'service'], true)) {
                $trackStock = false;
                $trackBatch = false;
            }
            if ($type === 'medicine' && $trackBatch) {
                $trackStock = true;
            }

            $attributes = $this->productAttributes($type);
            $pricePerM2 = null;
            if ($type === 'print') {
                $pricePerM2 = $price;
                $attributes['min_area_m2'] = max(0, (float) ($this->decimal(Request::post('min_area_m2')) ?? 0));
                $maxWidth = $this->decimal(Request::post('max_width_cm'));
                if ($maxWidth !== null && $maxWidth > 0) {
                    $attributes['max_width_cm'] = $maxWidth;
                }
                $attributes['finishing_options'] = $this->commaList(Request::post('finishing_options'), 12, 50);
            } elseif ($type === 'laundry') {
                $attributes['services'] = $this->commaList(Request::post('services'), 20, 40);
                $attributes['express_surcharge_pct'] = max(0, min(100, (float) ($this->decimal(Request::post('express_surcharge_pct')) ?? 0)));
            } elseif ($type === 'medicine') {
                $attributes['requires_prescription'] = isset($_POST['requires_prescription']);
                foreach (['generic_name' => 100, 'strength' => 60, 'dosage_form' => 60] as $key => $limit) {
                    $value = trim((string) Request::post($key));
                    if ($value !== '') {
                        if ($this->length($value) > $limit) {
                            throw new \RuntimeException('Informasi obat terlalu panjang.');
                        }
                        $attributes[$key] = $value;
                    }
                }
            } elseif ($type === 'menu') {
                $station = trim((string) Request::post('station'));
                if ($station !== '') {
                    $attributes['station'] = $this->clip($station, 60);
                }
                $prepMinutes = $this->positiveInt(Request::post('prep_minutes'));
                if ($prepMinutes !== null) {
                    $attributes['prep_minutes'] = min(1440, $prepMinutes);
                }
            } elseif ($type === 'service') {
                $attributes['requires_staff'] = isset($_POST['requires_staff']);
                $attributes['min_duration_minutes'] = max(0, min(1440, $this->positiveInt(Request::post('min_duration_minutes')) ?? 0));
                $attributes['rounding_minutes'] = max(1, min(1440, $this->positiveInt(Request::post('rounding_minutes')) ?? 1));
            }
            $variantAttributes = [];
            if ($pricePerM2 !== null) {
                $variantAttributes['price_per_m2'] = $pricePerM2;
                $material = trim((string) Request::post('material'));
                if ($material !== '') {
                    $variantAttributes['material'] = $this->clip($material, 100);
                }
            }

            $batchNumber = trim((string) Request::post('batch_number'));
            $expiry = trim((string) Request::post('expired_at'));
            if ($trackBatch && $initialQty > 0
                && (!$this->validDate($expiry) || $expiry < date('Y-m-d') || $batchNumber === '' || strlen($batchNumber) > 60)) {
                throw new \RuntimeException('Untuk stok awal obat batch, isi nomor batch dan tanggal kedaluwarsa hari ini atau yang akan datang.');
            }
            if ($initialQty > 0 && !$trackStock) {
                throw new \RuntimeException('Jumlah stok awal hanya berlaku jika pelacakan stok aktif.');
            }

            $this->assertUniqueVariant($pdo, $tenantId, $sku, $barcode);
            $pdo->beginTransaction();
            $categoryId = $this->categoryId($pdo, $tenantId, $categoryName);
            $pdo->prepare(
                'INSERT INTO products
                    (tenant_id, category_id, name, product_type, pricing_model, unit, base_price, cost_price,
                     track_stock, track_batch, min_stock, attributes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $tenantId, $categoryId, $name, $type, $pricing, $unit,
                $price, $cost, $trackStock ? 1 : 0, $trackBatch ? 1 : 0, $minStock,
                $this->json($attributes),
            ]);
            $productId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO product_variants
                    (tenant_id, product_id, name, sku, barcode, price, cost_price, is_default, attributes)
                 VALUES (?, ?, \'Default\', ?, ?, ?, ?, 1, ?)'
            )->execute([
                $tenantId, $productId, $sku !== '' ? $sku : null, $barcode !== '' ? $barcode : null,
                $price, $cost, $this->json($variantAttributes),
            ]);
            $variantId = (int) $pdo->lastInsertId();
            $initialBatchId = null;
            if ($trackStock) {
                $pdo->prepare('INSERT INTO stock_levels (tenant_id, branch_id, variant_id, qty) VALUES (?, ?, ?, ?)')
                    ->execute([$tenantId, $branchId, $variantId, $initialQty]);
                if ($initialQty > 0) {
                    if ($trackBatch) {
                        $pdo->prepare(
                            'INSERT INTO product_batches
                                (tenant_id, branch_id, variant_id, batch_number, expired_at, qty_initial, qty_remaining,
                                 cost_price, received_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
                        )->execute([$tenantId, $branchId, $variantId, $batchNumber, $expiry, $initialQty, $initialQty, $cost]);
                        $initialBatchId = (int) $pdo->lastInsertId();
                    }
                    $pdo->prepare(
                        'INSERT INTO stock_movements
                            (tenant_id, branch_id, variant_id, batch_id, movement_type, qty_change, qty_after,
                             reference_type, reference_id, note, created_by)
                         VALUES (?, ?, ?, ?, \'adjustment\', ?, ?, \'initial_stock\', ?, ?, ?)'
                    )->execute([
                        $tenantId, $branchId, $variantId, $initialBatchId, $initialQty, $initialQty,
                        $productId, 'Stok awal produk', $context['user_id'],
                    ]);
                }
            }
            $pdo->commit();
            $this->flash('success', 'Produk berhasil ditambahkan ke katalog toko ini.');
        } catch (\RuntimeException $e) {
            $this->rollback($pdo);
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[store-management] simpan produk gagal: ' . $e->getMessage());
            $this->flash('danger', 'Produk gagal disimpan. Periksa data dan coba lagi.');
        }
        Response::redirect(Router::base() . '/store#catalog');
    }

    public function toggleProduct(): void
    {
        $pdo = Database::pdo();
        try {
            $context = $this->context($pdo);
            $id = $this->positiveInt(Request::post('product_id'));
            if ($id === null) {
                throw new \RuntimeException('Produk tidak valid.');
            }
            $pdo->prepare(
                'UPDATE products SET is_active = IF(is_active = 1, 0, 1)
                  WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
            )->execute([$id, $context['tenant_id']]);
            if ($pdo->rowCount() !== 1) {
                throw new \RuntimeException('Produk tidak ditemukan.');
            }
            $this->flash('success', 'Status produk diperbarui.');
        } catch (\RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[store-management] status produk gagal: ' . $e->getMessage());
            $this->flash('danger', 'Status produk gagal diperbarui.');
        }
        Response::redirect(Router::base() . '/store#catalog');
    }

    public function updateProduct(): void
    {
        $pdo = Database::pdo();
        try {
            $context = $this->context($pdo);
            $productId = $this->positiveInt(Request::post('product_id'));
            $variantId = $this->positiveInt(Request::post('variant_id'));
            $name = trim((string) Request::post('name'));
            $categoryName = trim((string) Request::post('category'));
            $sku = trim((string) Request::post('sku'));
            $barcode = trim((string) Request::post('barcode'));
            $price = $this->decimal(Request::post('price'));
            $cost = $this->decimal(Request::post('cost_price'));
            if ($productId === null || $variantId === null || $this->length($name) < 2 || $this->length($name) > 190
                || $this->length($categoryName) > 120 || strlen($sku) > 60 || strlen($barcode) > 64
                || $price === null || $price < 0 || $price > 9999999999999
                || $cost === null || $cost < 0 || $cost > 9999999999999) {
                throw new \RuntimeException('Periksa nama, kategori, harga, modal, SKU, dan barcode.');
            }
            $product = $this->one($pdo,
                'SELECT id, product_type FROM products WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL',
                [$productId, $context['tenant_id']]);
            $variant = $this->one($pdo,
                'SELECT id, attributes FROM product_variants
                  WHERE id = ? AND product_id = ? AND tenant_id = ? AND is_default = 1 AND deleted_at IS NULL',
                [$variantId, $productId, $context['tenant_id']]);
            if ($product === null || $variant === null
                || !in_array(self::PRODUCT_MODULES[$product['product_type']] ?? '', $context['modules'], true)) {
                throw new \RuntimeException('Produk tidak ditemukan pada toko atau bidang aktif ini.');
            }
            foreach ([['sku', $sku], ['barcode', $barcode]] as [$column, $value]) {
                if ($value === '') {
                    continue;
                }
                $duplicate = $this->one($pdo,
                    "SELECT id FROM product_variants WHERE tenant_id = ? AND $column = ? AND id <> ? AND deleted_at IS NULL LIMIT 1",
                    [$context['tenant_id'], $value, $variantId]);
                if ($duplicate !== null) {
                    throw new \RuntimeException(($column === 'sku' ? 'SKU' : 'Barcode') . ' sudah digunakan produk lain.');
                }
            }
            $pdo->beginTransaction();
            $categoryId = $this->categoryId($pdo, $context['tenant_id'], $categoryName);
            $variantAttributes = $this->decode($variant['attributes']);
            if ($product['product_type'] === 'print') {
                $variantAttributes['price_per_m2'] = $price;
            }
            $pdo->prepare('UPDATE products SET name = ?, category_id = ?, base_price = ?, cost_price = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$name, $categoryId, $price, $cost, $productId, $context['tenant_id']]);
            $pdo->prepare(
                'UPDATE product_variants SET sku = ?, barcode = ?, price = ?, cost_price = ?, attributes = ?
                  WHERE id = ? AND product_id = ? AND tenant_id = ?'
            )->execute([
                $sku !== '' ? $sku : null, $barcode !== '' ? $barcode : null, $price, $cost,
                $this->json($variantAttributes), $variantId, $productId, $context['tenant_id'],
            ]);
            $pdo->commit();
            $this->flash('success', 'Data katalog produk berhasil diperbarui.');
        } catch (\RuntimeException $e) {
            $this->rollback($pdo);
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[store-management] edit produk gagal: ' . $e->getMessage());
            $this->flash('danger', 'Produk gagal diperbarui.');
        }
        Response::redirect(Router::base() . '/store#catalog');
    }

    public function adjustStock(): void
    {
        $pdo = Database::pdo();
        try {
            $context = $this->context($pdo);
            $variantId = $this->positiveInt(Request::post('variant_id'));
            $qty = $this->decimal(Request::post('quantity'));
            $note = trim((string) Request::post('note'));
            if ($variantId === null || $qty === null || abs($qty) < 0.001 || abs($qty) > 999999.999
                || $this->length($note) < 3 || $this->length($note) > 255) {
                throw new \RuntimeException('Isi produk, jumlah stok, dan alasan penyesuaian dengan benar.');
            }
            $product = $this->one($pdo,
                'SELECT p.name, p.track_stock, p.track_batch, v.id
                   FROM product_variants v JOIN products p ON p.id = v.product_id AND p.tenant_id = v.tenant_id
                  WHERE v.id = ? AND v.tenant_id = ? AND v.is_active = 1 AND v.deleted_at IS NULL
                    AND p.deleted_at IS NULL',
                [$variantId, $context['tenant_id']]);
            if ($product === null || (int) $product['track_stock'] !== 1) {
                throw new \RuntimeException('Produk tidak ditemukan atau tidak melacak stok.');
            }
            if ((int) $product['track_batch'] === 1) {
                throw new \RuntimeException('Obat yang dilacak per batch harus ditambahkan melalui formulir penerimaan batch.');
            }

            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO stock_levels (tenant_id, branch_id, variant_id, qty) VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE qty = qty'
            )->execute([$context['tenant_id'], $context['branch_id'], $variantId]);
            $stock = $this->one($pdo,
                'SELECT qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                [$context['tenant_id'], $context['branch_id'], $variantId]);
            $before = (float) ($stock['qty'] ?? 0);
            $after = round($before + $qty, 3);
            if ($after < 0) {
                throw new \RuntimeException('Stok tidak boleh menjadi negatif.');
            }
            $pdo->prepare(
                'UPDATE stock_levels SET qty = ? WHERE tenant_id = ? AND branch_id = ? AND variant_id = ?'
            )->execute([$after, $context['tenant_id'], $context['branch_id'], $variantId]);
            $pdo->prepare(
                'INSERT INTO stock_movements
                    (tenant_id, branch_id, variant_id, movement_type, qty_change, qty_after,
                     reference_type, note, created_by)
                 VALUES (?, ?, ?, \'adjustment\', ?, ?, \'manual_adjustment\', ?, ?)'
            )->execute([
                $context['tenant_id'], $context['branch_id'], $variantId, $qty, $after, $note, $context['user_id'],
            ]);
            $pdo->commit();
            $this->flash('success', 'Stok ' . $product['name'] . ' berhasil disesuaikan dan tercatat di riwayat stok.');
        } catch (\RuntimeException $e) {
            $this->rollback($pdo);
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[store-management] penyesuaian stok gagal: ' . $e->getMessage());
            $this->flash('danger', 'Penyesuaian stok gagal disimpan.');
        }
        Response::redirect(Router::base() . '/store#inventory');
    }

    public function receiveBatch(): void
    {
        $pdo = Database::pdo();
        try {
            $context = $this->context($pdo);
            $variantId = $this->positiveInt(Request::post('variant_id'));
            $quantity = $this->decimal(Request::post('quantity'));
            $batchNumber = trim((string) Request::post('batch_number'));
            $expiry = trim((string) Request::post('expired_at'));
            $supplier = trim((string) Request::post('supplier_name'));
            $cost = $this->decimal(Request::post('cost_price') ?: '0');
            if ($variantId === null || $quantity === null || $quantity <= 0 || $quantity > 999999.999
                || $batchNumber === '' || strlen($batchNumber) > 60 || !$this->validDate($expiry) || $expiry < date('Y-m-d')
                || $cost === null || $cost < 0 || $cost > 9999999999999 || $this->length($supplier) > 150) {
                throw new \RuntimeException('Periksa jumlah, nomor batch (tidak boleh kedaluwarsa), biaya, dan pemasok.');
            }
            $product = $this->one($pdo,
                'SELECT p.name, p.track_stock, p.track_batch, v.id
                   FROM product_variants v JOIN products p ON p.id = v.product_id AND p.tenant_id = v.tenant_id
                  WHERE v.id = ? AND v.tenant_id = ? AND v.is_active = 1 AND v.deleted_at IS NULL
                    AND p.deleted_at IS NULL',
                [$variantId, $context['tenant_id']]);
            if ($product === null || (int) $product['track_batch'] !== 1 || (int) $product['track_stock'] !== 1) {
                throw new \RuntimeException('Penerimaan batch hanya tersedia untuk obat yang melacak batch.');
            }
            $pdo->beginTransaction();
            $batch = $this->one($pdo,
                'SELECT id FROM product_batches
                  WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? AND batch_number = ? FOR UPDATE',
                [$context['tenant_id'], $context['branch_id'], $variantId, $batchNumber]);
            if ($batch === null) {
                $pdo->prepare(
                    'INSERT INTO product_batches
                        (tenant_id, branch_id, variant_id, batch_number, expired_at, qty_initial, qty_remaining,
                         cost_price, supplier_name, received_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
                )->execute([
                    $context['tenant_id'], $context['branch_id'], $variantId, $batchNumber, $expiry,
                    $quantity, $quantity, $cost, $supplier !== '' ? $supplier : null,
                ]);
                $batchId = (int) $pdo->lastInsertId();
            } else {
                $batchId = (int) $batch['id'];
                $existing = $this->one($pdo,
                    'SELECT expired_at FROM product_batches WHERE id = ? FOR UPDATE',
                    [$batchId]);
                if ($existing === null || (string) $existing['expired_at'] !== $expiry) {
                    throw new \RuntimeException('Nomor batch sudah terdaftar dengan tanggal kedaluwarsa berbeda.');
                }
                $pdo->prepare(
                    'UPDATE product_batches SET qty_initial = qty_initial + ?, qty_remaining = qty_remaining + ?,
                            cost_price = ?, supplier_name = COALESCE(?, supplier_name)
                      WHERE id = ?'
                )->execute([$quantity, $quantity, $cost, $supplier !== '' ? $supplier : null, $batchId]);
            }
            $pdo->prepare(
                'INSERT INTO stock_levels (tenant_id, branch_id, variant_id, qty) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
            )->execute([$context['tenant_id'], $context['branch_id'], $variantId, $quantity]);
            $stock = $this->one($pdo,
                'SELECT qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id = ?',
                [$context['tenant_id'], $context['branch_id'], $variantId]);
            $pdo->prepare(
                'INSERT INTO stock_movements
                    (tenant_id, branch_id, variant_id, batch_id, movement_type, qty_change, qty_after,
                     reference_type, reference_id, note, created_by)
                 VALUES (?, ?, ?, ?, \'purchase\', ?, ?, \'batch_receipt\', ?, ?, ?)'
            )->execute([
                $context['tenant_id'], $context['branch_id'], $variantId, $batchId, $quantity,
                (float) ($stock['qty'] ?? $quantity), $batchId,
                'Penerimaan batch ' . $batchNumber . ($supplier !== '' ? ' · ' . $supplier : ''),
                $context['user_id'],
            ]);
            $pdo->commit();
            $this->flash('success', 'Penerimaan batch ' . $batchNumber . ' berhasil dicatat.');
        } catch (\RuntimeException $e) {
            $this->rollback($pdo);
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[store-management] penerimaan batch gagal: ' . $e->getMessage());
            $this->flash('danger', 'Penerimaan batch gagal disimpan.');
        }
        Response::redirect(Router::base() . '/store#inventory');
    }

    public function createCustomer(): void
    {
        $pdo = Database::pdo();
        try {
            $context = $this->context($pdo);
            $name = trim((string) Request::post('name'));
            $phone = trim((string) Request::post('phone'));
            $email = strtolower(trim((string) Request::post('email')));
            $address = trim((string) Request::post('address'));
            if ($this->length($name) < 2 || $this->length($name) > 150 || strlen($phone) > 30
                || ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190))
                || $this->length($address) > 1000) {
                throw new \RuntimeException('Periksa nama, nomor telepon, email, dan alamat pelanggan.');
            }
            $pdo->prepare(
                'INSERT INTO customers (tenant_id, name, phone, email, address)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $context['tenant_id'], $name, $phone !== '' ? $phone : null,
                $email !== '' ? $email : null, $address !== '' ? $address : null,
            ]);
            $this->flash('success', 'Pelanggan berhasil ditambahkan ke data usaha ini.');
        } catch (\RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[store-management] tambah pelanggan gagal: ' . $e->getMessage());
            $this->flash('danger', 'Pelanggan gagal disimpan.');
        }
        Response::redirect(Router::base() . '/store#customers');
    }

    public function customers(): void
    {
        try {
            $pdo = Database::pdo();
            $context = $this->context($pdo, false);
            $query = trim((string) (Request::query('q') ?? ''));
            if ($this->length($query) > 80) {
                Response::send(422, false, 'Pencarian pelanggan maksimal 80 karakter.');
            }
            $rows = $this->all($pdo,
                'SELECT id, name, phone, email FROM customers
                  WHERE tenant_id = ? AND deleted_at IS NULL
                    AND (? = \'\' OR name LIKE ? OR phone LIKE ? OR email LIKE ?)
                  ORDER BY name LIMIT 20',
                [$context['tenant_id'], $query, '%' . $query . '%', '%' . $query . '%', '%' . $query . '%']);
            Response::send(200, true, 'OK', ['items' => $rows]);
        } catch (Throwable $e) {
            error_log('[store-management] pencarian pelanggan gagal: ' . $e->getMessage());
            Response::send(500, false, 'Gagal memuat daftar pelanggan.');
        }
    }

    public function updateOrderStatus(): void
    {
        $pdo = Database::pdo();
        try {
            $context = $this->context($pdo);
            $orderId = $this->positiveInt(Request::post('transaction_id'));
            $next = (string) Request::post('status');
            if ($orderId === null) {
                throw new \RuntimeException('Pesanan tidak valid.');
            }
            $pdo->beginTransaction();
            $order = $this->one($pdo,
                'SELECT id, order_type, fulfillment_status, table_id
                   FROM transactions
                  WHERE id = ? AND tenant_id = ? AND branch_id = ? AND status = \'paid\'
                  FOR UPDATE',
                [$orderId, $context['tenant_id'], $context['branch_id']]);
            if ($order === null || !isset(self::FULFILLMENT_STEPS[$order['order_type']])) {
                throw new \RuntimeException('Pesanan tidak ditemukan atau tidak memiliki alur pengerjaan.');
            }
            $steps = self::FULFILLMENT_STEPS[$order['order_type']];
            $currentIndex = array_search($order['fulfillment_status'], $steps, true);
            $nextIndex = array_search($next, $steps, true);
            if ($currentIndex === false || $nextIndex === false || $nextIndex !== $currentIndex + 1) {
                throw new \RuntimeException('Status pesanan harus dilanjutkan satu tahap dari status saat ini.');
            }
            $pdo->prepare('UPDATE transactions SET fulfillment_status = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$next, $orderId, $context['tenant_id']]);
            $pdo->prepare(
                'INSERT INTO transaction_status_logs (tenant_id, transaction_id, status_from, status_to, changed_by, note)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $context['tenant_id'], $orderId, $order['fulfillment_status'], $next,
                $context['user_id'], 'Status diperbarui melalui antrean operasional',
            ]);
            if ($next === 'selesai' && $order['order_type'] === 'dine_in' && $order['table_id'] !== null) {
                $unfinished = $this->one($pdo,
                    'SELECT COUNT(*) AS active_orders FROM transactions
                      WHERE tenant_id = ? AND branch_id = ? AND table_id = ? AND id <> ?
                        AND status = \'paid\' AND fulfillment_status NOT IN (\'selesai\', \'diserahkan\', \'disajikan\')',
                    [$context['tenant_id'], $context['branch_id'], $order['table_id'], $orderId]);
                if ((int) ($unfinished['active_orders'] ?? 0) === 0) {
                    $pdo->prepare('UPDATE dining_tables SET status = \'available\' WHERE id = ? AND tenant_id = ? AND branch_id = ?')
                        ->execute([$order['table_id'], $context['tenant_id'], $context['branch_id']]);
                }
            }
            $pdo->commit();
            $this->flash('success', 'Status pesanan diperbarui menjadi ' . str_replace('_', ' ', $next) . '.');
        } catch (\RuntimeException $e) {
            $this->rollback($pdo);
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[store-management] status pesanan gagal: ' . $e->getMessage());
            $this->flash('danger', 'Status pesanan gagal diperbarui.');
        }
        Response::redirect(Router::base() . '/store#orders');
    }

    private function context(PDO $pdo, bool $managerOnly = true): array
    {
        if (!Auth::check()) {
            throw new \RuntimeException('Sesi berakhir. Silakan login kembali.');
        }
        $tenantId = (int) Auth::tenantId();
        $branchId = (int) Auth::branchId();
        $userId = Auth::userId();
        if ($tenantId <= 0 || $branchId <= 0 || $userId <= 0) {
            throw new \RuntimeException('Akun belum terhubung ke usaha dan cabang aktif.');
        }
        $user = $this->one($pdo,
            'SELECT id, tenant_id, branch_id, role, is_active FROM users
              WHERE id = ? AND deleted_at IS NULL',
            [$userId]);
        if ($user === null || (int) $user['is_active'] !== 1 || $user['role'] !== Auth::role()
            || !in_array($user['role'], $managerOnly ? ['owner', 'manager'] : ['owner', 'manager', 'cashier'], true)) {
            throw new \RuntimeException('Akun tidak berwenang mengelola data toko.');
        }
        $hasAccountSchema = $this->tableExists($pdo, 'owner_accounts')
            && $this->tableExists($pdo, 'owner_account_tenants');
        $ownerAccount = null;
        if ($user['role'] === 'owner' && $hasAccountSchema) {
            $ownerAccount = $this->one($pdo,
                'SELECT oa.id, oa.plan_id, oa.status, oa.subscription_ends_at, p.allowed_modules, p.is_active AS plan_active
                   FROM owner_accounts oa
                   JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id
                   JOIN plans p ON p.id = oa.plan_id
                  WHERE oa.owner_user_id = ? AND oat.tenant_id = ?',
                [$userId, $tenantId]);
            if ($ownerAccount === null) {
                throw new \RuntimeException('Usaha ini tidak terhubung ke akun pemilik.');
            }
        } elseif ($user['tenant_id'] === null || (int) $user['tenant_id'] !== $tenantId) {
            throw new \RuntimeException('Akun tidak terhubung ke usaha yang dipilih.');
        }
        if (in_array($user['role'], ['manager', 'cashier'], true)
            && $user['branch_id'] !== null && (int) $user['branch_id'] !== $branchId) {
            throw new \RuntimeException('Akun manajer tidak terdaftar pada cabang ini.');
        }

        $tenant = $this->one($pdo,
            'SELECT t.name, t.phone, t.plan_id, t.status, t.subscription_ends_at, t.timezone,
                    b.name AS branch_name, b.address AS branch_address
               FROM tenants t JOIN branches b ON b.tenant_id = t.id
              WHERE t.id = ? AND b.id = ? AND t.deleted_at IS NULL
                AND b.is_active = 1 AND b.deleted_at IS NULL',
            [$tenantId, $branchId]);
        if ($tenant === null) {
            throw new \RuntimeException('Toko atau cabang tidak ditemukan atau tidak aktif.');
        }
        $status = $ownerAccount['status'] ?? $tenant['status'];
        $endsAt = $ownerAccount['subscription_ends_at'] ?? $tenant['subscription_ends_at'];
        if (!in_array($status, ['trial', 'active'], true)) {
            throw new \RuntimeException('Langganan toko tidak aktif.');
        }
        try {
            $timezone = new DateTimeZone((string) $tenant['timezone']);
        } catch (Throwable $e) {
            $timezone = new DateTimeZone('Asia/Jakarta');
        }
        if ($endsAt !== null && new DateTimeImmutable((string) $endsAt, $timezone) <= new DateTimeImmutable('now', $timezone)) {
            throw new \RuntimeException('Masa langganan telah berakhir.');
        }
        $planId = (int) ($ownerAccount['plan_id'] ?? $tenant['plan_id']);
        $plan = $ownerAccount ?? $this->one($pdo,
            'SELECT p.allowed_modules, p.is_active AS plan_active FROM plans p WHERE p.id = ?',
            [$planId]);
        if ($plan === null || (int) $plan['plan_active'] !== 1) {
            throw new \RuntimeException('Paket usaha tidak aktif.');
        }
        $modules = $this->all($pdo,
            'SELECT module_code FROM tenant_modules WHERE tenant_id = ? AND is_active = 1',
            [$tenantId]);
        $activeModules = array_column($modules, 'module_code');
        $allowed = $this->decode($plan['allowed_modules'] ?? null);
        if ($allowed !== []) {
            $activeModules = array_values(array_intersect($activeModules, $allowed));
        }
        return [
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'user_id' => $userId,
            'role' => (string) $user['role'], 'tenant_name' => (string) $tenant['name'],
            'branch_name' => (string) $tenant['branch_name'], 'modules' => $activeModules,
        ];
    }

    private function productAttributes(string $type): array
    {
        $attributes = [];
        if ($type === 'menu') {
            $attributes['modifier_groups'] = [];
        }
        return $attributes;
    }

    private function validPricing(string $type, string $pricing): bool
    {
        $allowed = [
            'goods' => ['unit', 'fixed'], 'menu' => ['unit', 'fixed'],
            'medicine' => ['unit', 'fixed'], 'laundry' => ['per_kg', 'unit', 'fixed'],
            'print' => ['per_m2'], 'service' => ['per_hour', 'unit', 'fixed'],
        ];
        return in_array($pricing, $allowed[$type] ?? [], true);
    }

    private function assertUniqueVariant(PDO $pdo, int $tenantId, string $sku, string $barcode): void
    {
        foreach ([['sku', $sku], ['barcode', $barcode]] as [$column, $value]) {
            if ($value === '') {
                continue;
            }
            $found = $this->one($pdo,
                "SELECT id FROM product_variants WHERE tenant_id = ? AND $column = ? AND deleted_at IS NULL LIMIT 1",
                [$tenantId, $value]);
            if ($found !== null) {
                throw new \RuntimeException(($column === 'sku' ? 'SKU' : 'Barcode') . ' sudah digunakan produk lain di toko ini.');
            }
        }
    }

    private function categoryId(PDO $pdo, int $tenantId, string $name): ?int
    {
        if ($name === '') {
            return null;
        }
        $category = $this->one($pdo,
            'SELECT id FROM categories WHERE tenant_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1',
            [$tenantId, $name]);
        if ($category !== null) {
            return (int) $category['id'];
        }
        $pdo->prepare('INSERT INTO categories (tenant_id, name) VALUES (?, ?)')->execute([$tenantId, $name]);
        return (int) $pdo->lastInsertId();
    }

    private function commaList($value, int $maxItems, int $maxLength): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $items = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), static fn($item) => $item !== '')));
        if (count($items) > $maxItems) {
            throw new \RuntimeException('Terlalu banyak pilihan layanan/finishing.');
        }
        foreach ($items as $item) {
            if ($this->length($item) > $maxLength) {
                throw new \RuntimeException('Nama pilihan terlalu panjang.');
            }
        }
        return $items;
    }

    private function json(array $value): ?string
    {
        return $value === [] ? null : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function decode($json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $value = json_decode($json, true);
        return is_array($value) ? $value : [];
    }

    private function tableExists(PDO $pdo, string $name): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $statement->execute([$name]);
        return $statement->fetchColumn() !== false;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))
            && $date->format('Y-m-d') === $value;
    }

    private function decimal($value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^-?\d{1,13}(?:\.\d{1,3})?$/', $text)) {
            return null;
        }
        $number = (float) $text;
        return is_finite($number) ? $number : null;
    }

    private function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        return is_string($value) && ctype_digit($value) && strlen($value) < 10 && (int) $value > 0
            ? (int) $value : null;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : (int) preg_match_all('/./us', $value);
    }

    private function clip(string $value, int $limit): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }

    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function all(PDO $pdo, string $sql, array $params): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['store_flash'] = ['type' => $type, 'message' => $message];
    }
}
