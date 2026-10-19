<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\AuditLogger;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

final class OperationsController
{
    private const EXPENSE_CATEGORIES = ['Sewa', 'Utilitas', 'Gaji', 'Transportasi', 'Perlengkapan', 'Perawatan', 'Lainnya'];
    private const EXPENSE_METHODS = ['cash', 'qris', 'transfer', 'debit', 'credit', 'other'];

    public static function ensureSchema(PDO $pdo): void
    {
        $tables = [
            'cashier_shifts' => 'CREATE TABLE cashier_shifts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL, branch_id INT UNSIGNED NOT NULL, cashier_id INT UNSIGNED NOT NULL,
                opening_cash DECIMAL(15,2) NOT NULL DEFAULT 0.00, expected_cash DECIMAL(15,2) NULL,
                counted_cash DECIMAL(15,2) NULL, cash_difference DECIMAL(15,2) NULL,
                status ENUM(\'open\',\'closed\') NOT NULL DEFAULT \'open\',
                opening_note VARCHAR(255) NULL, closing_note VARCHAR(255) NULL,
                opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, closed_at DATETIME NULL, closed_by INT UNSIGNED NULL,
                PRIMARY KEY (id), KEY idx_shift_open (tenant_id, branch_id, status),
                KEY idx_shift_report (tenant_id, branch_id, opened_at),
                CONSTRAINT fk_shift_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_shift_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_shift_cashier FOREIGN KEY (cashier_id) REFERENCES users (id),
                CONSTRAINT fk_shift_closed_by FOREIGN KEY (closed_by) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'cashier_shift_movements' => 'CREATE TABLE cashier_shift_movements (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL, branch_id INT UNSIGNED NOT NULL,
                shift_id BIGINT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
                movement_type ENUM(\'cash_in\',\'cash_out\') NOT NULL, amount DECIMAL(15,2) NOT NULL,
                reason VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_shift_movement (tenant_id, branch_id, shift_id, created_at),
                CONSTRAINT fk_shift_movement_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_shift_movement_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_shift_movement_shift FOREIGN KEY (shift_id) REFERENCES cashier_shifts (id),
                CONSTRAINT fk_shift_movement_user FOREIGN KEY (user_id) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'operating_expenses' => 'CREATE TABLE operating_expenses (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL, branch_id INT UNSIGNED NOT NULL,
                expense_date DATE NOT NULL, category VARCHAR(40) NOT NULL,
                amount DECIMAL(15,2) NOT NULL, payment_method ENUM(\'cash\',\'qris\',\'transfer\',\'debit\',\'credit\',\'other\') NOT NULL DEFAULT \'cash\',
                description VARCHAR(255) NOT NULL, created_by INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_expense_branch_date (tenant_id, branch_id, expense_date),
                CONSTRAINT fk_expense_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_expense_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_expense_user FOREIGN KEY (created_by) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'audit_logs' => 'CREATE TABLE audit_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                tenant_id INT UNSIGNED NOT NULL, branch_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL,
                action_code VARCHAR(60) NOT NULL, entity_type VARCHAR(50) NOT NULL, entity_id BIGINT UNSIGNED NULL,
                details JSON NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_audit_tenant_time (tenant_id, created_at),
                KEY idx_audit_branch_time (tenant_id, branch_id, created_at),
                CONSTRAINT fk_audit_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_audit_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'suppliers' => 'CREATE TABLE suppliers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                name VARCHAR(150) NOT NULL, phone VARCHAR(30) NULL, email VARCHAR(190) NULL,
                address TEXT NULL, notes VARCHAR(255) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_supplier_tenant (tenant_id, is_active, name),
                CONSTRAINT fk_supplier_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'purchase_orders' => 'CREATE TABLE purchase_orders (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                branch_id INT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NOT NULL, order_no VARCHAR(40) NOT NULL,
                status ENUM(\'ordered\',\'received\',\'cancelled\') NOT NULL DEFAULT \'ordered\',
                total_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00, notes VARCHAR(255) NULL,
                created_by INT UNSIGNED NOT NULL, received_by INT UNSIGNED NULL,
                due_at DATE NULL, ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, received_at DATETIME NULL,
                PRIMARY KEY (id), UNIQUE KEY uq_purchase_order_no (tenant_id, order_no),
                KEY idx_purchase_branch_status (tenant_id, branch_id, status, ordered_at),
                CONSTRAINT fk_purchase_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_purchase_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_purchase_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
                CONSTRAINT fk_purchase_created_by FOREIGN KEY (created_by) REFERENCES users (id),
                CONSTRAINT fk_purchase_received_by FOREIGN KEY (received_by) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'purchase_order_items' => 'CREATE TABLE purchase_order_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                purchase_order_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NOT NULL,
                qty_ordered DECIMAL(12,3) NOT NULL, qty_received DECIMAL(12,3) NOT NULL DEFAULT 0.000,
                unit_cost DECIMAL(15,2) NOT NULL, batch_number VARCHAR(60) NULL, expired_at DATE NULL,
                received_batch_id BIGINT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_purchase_item_order (purchase_order_id),
                CONSTRAINT fk_purchase_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_purchase_item_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
                CONSTRAINT fk_purchase_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'supplier_payables' => 'CREATE TABLE supplier_payables (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                branch_id INT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NOT NULL,
                purchase_order_id BIGINT UNSIGNED NOT NULL, billed_amount DECIMAL(15,2) NOT NULL,
                paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00, credited_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                due_at DATE NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), UNIQUE KEY uq_payable_order (tenant_id, purchase_order_id),
                KEY idx_payable_supplier_due (tenant_id, branch_id, supplier_id, due_at),
                CONSTRAINT fk_payable_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_payable_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_payable_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
                CONSTRAINT fk_payable_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'supplier_payments' => 'CREATE TABLE supplier_payments (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                branch_id INT UNSIGNED NOT NULL, payable_id BIGINT UNSIGNED NOT NULL,
                amount DECIMAL(15,2) NOT NULL, payment_method ENUM(\'cash\',\'qris\',\'transfer\',\'debit\',\'credit\',\'other\') NOT NULL,
                reference_no VARCHAR(80) NULL, note VARCHAR(255) NULL, paid_by INT UNSIGNED NOT NULL,
                cashier_shift_id BIGINT UNSIGNED NULL, paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_supplier_payment_payable (tenant_id, payable_id, paid_at),
                CONSTRAINT fk_supplier_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_supplier_payment_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_supplier_payment_payable FOREIGN KEY (payable_id) REFERENCES supplier_payables (id),
                CONSTRAINT fk_supplier_payment_user FOREIGN KEY (paid_by) REFERENCES users (id),
                CONSTRAINT fk_supplier_payment_shift FOREIGN KEY (cashier_shift_id) REFERENCES cashier_shifts (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'purchase_returns' => 'CREATE TABLE purchase_returns (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                branch_id INT UNSIGNED NOT NULL, supplier_id BIGINT UNSIGNED NOT NULL,
                purchase_order_id BIGINT UNSIGNED NOT NULL, payable_id BIGINT UNSIGNED NOT NULL,
                total_amount DECIMAL(15,2) NOT NULL, note VARCHAR(255) NULL, returned_by INT UNSIGNED NOT NULL,
                returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id), KEY idx_purchase_return_order (tenant_id, purchase_order_id, returned_at),
                CONSTRAINT fk_purchase_return_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_purchase_return_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_purchase_return_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers (id),
                CONSTRAINT fk_purchase_return_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders (id),
                CONSTRAINT fk_purchase_return_payable FOREIGN KEY (payable_id) REFERENCES supplier_payables (id),
                CONSTRAINT fk_purchase_return_user FOREIGN KEY (returned_by) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'purchase_return_items' => 'CREATE TABLE purchase_return_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, return_id BIGINT UNSIGNED NOT NULL,
                purchase_order_item_id BIGINT UNSIGNED NOT NULL, qty DECIMAL(12,3) NOT NULL,
                unit_cost DECIMAL(15,2) NOT NULL, amount DECIMAL(15,2) NOT NULL,
                PRIMARY KEY (id), UNIQUE KEY uq_purchase_return_item (return_id, purchase_order_item_id),
                KEY idx_return_purchase_item (purchase_order_item_id),
                CONSTRAINT fk_return_purchase_header FOREIGN KEY (return_id) REFERENCES purchase_returns (id),
                CONSTRAINT fk_return_purchase_line FOREIGN KEY (purchase_order_item_id) REFERENCES purchase_order_items (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'stock_counts' => 'CREATE TABLE stock_counts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL, branch_id INT UNSIGNED NOT NULL,
                status ENUM(\'open\',\'posted\') NOT NULL DEFAULT \'open\', notes VARCHAR(255) NULL,
                created_by INT UNSIGNED NOT NULL, posted_by INT UNSIGNED NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, posted_at DATETIME NULL,
                PRIMARY KEY (id), KEY idx_stock_count_branch (tenant_id, branch_id, status, created_at),
                CONSTRAINT fk_stock_count_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_stock_count_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
                CONSTRAINT fk_stock_count_user FOREIGN KEY (created_by) REFERENCES users (id),
                CONSTRAINT fk_stock_count_posted_by FOREIGN KEY (posted_by) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'stock_count_items' => 'CREATE TABLE stock_count_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                stock_count_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NOT NULL, batch_id BIGINT UNSIGNED NULL,
                expected_qty DECIMAL(12,3) NOT NULL, counted_qty DECIMAL(12,3) NULL, variance_qty DECIMAL(12,3) NULL,
                PRIMARY KEY (id), KEY idx_stock_count_item (stock_count_id, variant_id),
                CONSTRAINT fk_stock_count_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_stock_count_item_count FOREIGN KEY (stock_count_id) REFERENCES stock_counts (id),
                CONSTRAINT fk_stock_count_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id),
                CONSTRAINT fk_stock_count_item_batch FOREIGN KEY (batch_id) REFERENCES product_batches (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'stock_transfers' => 'CREATE TABLE stock_transfers (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                from_branch_id INT UNSIGNED NOT NULL, to_branch_id INT UNSIGNED NOT NULL,
                status ENUM(\'in_transit\',\'received\',\'cancelled\') NOT NULL DEFAULT \'in_transit\',
                notes VARCHAR(255) NULL, created_by INT UNSIGNED NOT NULL, received_by INT UNSIGNED NULL,
                shipped_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, received_at DATETIME NULL,
                PRIMARY KEY (id), KEY idx_transfer_from (tenant_id, from_branch_id, status, shipped_at),
                KEY idx_transfer_to (tenant_id, to_branch_id, status, shipped_at),
                CONSTRAINT fk_transfer_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_transfer_from FOREIGN KEY (from_branch_id) REFERENCES branches (id),
                CONSTRAINT fk_transfer_to FOREIGN KEY (to_branch_id) REFERENCES branches (id),
                CONSTRAINT fk_transfer_created_by FOREIGN KEY (created_by) REFERENCES users (id),
                CONSTRAINT fk_transfer_received_by FOREIGN KEY (received_by) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            'stock_transfer_items' => 'CREATE TABLE stock_transfer_items (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, tenant_id INT UNSIGNED NOT NULL,
                transfer_id BIGINT UNSIGNED NOT NULL, variant_id BIGINT UNSIGNED NOT NULL,
                source_batch_id BIGINT UNSIGNED NULL, batch_number VARCHAR(60) NULL, expired_at DATE NULL,
                qty DECIMAL(12,3) NOT NULL, unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (id), KEY idx_transfer_item (transfer_id, variant_id),
                CONSTRAINT fk_transfer_item_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
                CONSTRAINT fk_transfer_item_transfer FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id),
                CONSTRAINT fk_transfer_item_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id),
                CONSTRAINT fk_transfer_item_batch FOREIGN KEY (source_batch_id) REFERENCES product_batches (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];

        foreach ($tables as $name => $sql) {
            $check = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
            $check->execute([$name]);
            if (!$check->fetchColumn()) {
                $pdo->exec($sql);
            }
        }
        foreach ([
            'transactions' => ['cashier_shift_id' => 'BIGINT UNSIGNED NULL'],
            'transaction_returns' => [
                'cashier_shift_id' => 'BIGINT UNSIGNED NULL',
                'settled_refund_amount' => 'DECIMAL(15,2) NULL',
            ],
            'transaction_payments' => ['cashier_shift_id' => 'BIGINT UNSIGNED NULL'],
            'purchase_orders' => ['due_at' => 'DATE NULL'],
            'purchase_order_items' => ['received_batch_id' => 'BIGINT UNSIGNED NULL'],
        ] as $table => $columns) {
            foreach ($columns as $column => $definition) {
                $check = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
                $check->execute([$table, $column]);
                if (!$check->fetchColumn()) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                }
            }
        }
    }

    public function index(): void
    {
        try {
            $pdo = Database::pdo();
            self::ensureSchema($pdo);
            $ctx = $this->context($pdo);
            $branchId = $ctx['branch_id'];
            $tenantId = $ctx['tenant_id'];
            $openShift = $this->openShift($pdo, $ctx);
            if ($openShift !== null) {
                $openShift['expected_cash'] = $this->expectedCash($pdo, (int) $openShift['id']);
                $opener = $this->one($pdo, 'SELECT name FROM users WHERE id = ?', [$openShift['cashier_id']]);
                $openShift['cashier_name'] = $opener['name'] ?? 'Akun tidak ditemukan';
                $openShift['movements'] = $this->all($pdo,
                    'SELECT movement_type, amount, reason, created_at FROM cashier_shift_movements
                      WHERE shift_id = ? AND tenant_id = ? ORDER BY id DESC LIMIT 20',
                    [$openShift['id'], $tenantId]);
            }
            $shifts = $this->all($pdo,
                'SELECT s.id, s.cashier_id, u.name AS cashier_name, s.opening_cash, s.expected_cash,
                        s.counted_cash, s.cash_difference, s.status, s.opened_at, s.closed_at
                   FROM cashier_shifts s JOIN users u ON u.id = s.cashier_id
                  WHERE s.tenant_id = ? AND s.branch_id = ?
                  ORDER BY s.opened_at DESC LIMIT 30',
                [$tenantId, $branchId]);
            foreach ($shifts as &$shift) {
                if ($shift['status'] === 'open') {
                    $shift['expected_cash'] = $this->expectedCash($pdo, (int) $shift['id']);
                }
            }
            unset($shift);
            $suppliers = $this->all($pdo,
                'SELECT id, name, phone, email, is_active FROM suppliers WHERE tenant_id = ? AND is_active = 1 ORDER BY name LIMIT 200',
                [$tenantId]);
            $products = $this->all($pdo,
                'SELECT p.name, p.product_type, p.track_batch, p.track_stock, p.unit,
                        v.id AS variant_id, v.name AS variant_name, v.sku
                   FROM products p JOIN product_variants v ON v.product_id = p.id AND v.tenant_id = p.tenant_id
                    AND v.is_default = 1 AND v.is_active = 1 AND v.deleted_at IS NULL
                  WHERE p.tenant_id = ? AND p.is_active = 1 AND p.deleted_at IS NULL
                    AND p.track_stock = 1
                  ORDER BY p.name LIMIT 500',
                [$tenantId]);
            $batches = $this->all($pdo,
                'SELECT pb.id, pb.variant_id, pb.batch_number, pb.expired_at, pb.qty_remaining
                   FROM product_batches pb
                  WHERE pb.tenant_id = ? AND pb.branch_id = ? AND pb.qty_remaining > 0 AND pb.expired_at >= CURDATE()
                  ORDER BY pb.expired_at, pb.batch_number',
                [$tenantId, $branchId]);
            $orders = $this->all($pdo,
                'SELECT po.id, po.order_no, po.status, po.total_amount, po.due_at, po.ordered_at, s.name AS supplier_name,
                        COUNT(poi.id) AS item_count, SUM(poi.qty_ordered) AS qty_ordered,
                        MAX(poi.id) AS purchase_item_id, SUM(poi.qty_received) AS qty_received,
                        GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR \', \') AS product_names
                   FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id AND s.tenant_id = po.tenant_id
                   LEFT JOIN purchase_order_items poi ON poi.purchase_order_id = po.id AND poi.tenant_id = po.tenant_id
                   LEFT JOIN product_variants v ON v.id = poi.variant_id AND v.tenant_id = poi.tenant_id
                   LEFT JOIN products p ON p.id = v.product_id AND p.tenant_id = v.tenant_id
                  WHERE po.tenant_id = ? AND po.branch_id = ?
                  GROUP BY po.id ORDER BY po.ordered_at DESC LIMIT 50',
                [$tenantId, $branchId]);
            $payables = $this->all($pdo,
                'SELECT sp.id, sp.purchase_order_id, sp.billed_amount, sp.paid_amount, sp.credited_amount,
                        sp.due_at, sp.created_at, po.order_no, s.name AS supplier_name,
                        sp.billed_amount - sp.paid_amount - sp.credited_amount AS balance,
                        CASE WHEN sp.due_at IS NOT NULL AND sp.due_at < CURDATE()
                                  AND sp.billed_amount > sp.paid_amount + sp.credited_amount THEN 1 ELSE 0 END AS overdue
                   FROM supplier_payables sp
                   JOIN purchase_orders po ON po.id = sp.purchase_order_id AND po.tenant_id = sp.tenant_id
                   JOIN suppliers s ON s.id = sp.supplier_id AND s.tenant_id = sp.tenant_id
                  WHERE sp.tenant_id = ? AND sp.branch_id = ?
                  ORDER BY (sp.billed_amount - sp.paid_amount - sp.credited_amount) DESC, sp.due_at LIMIT 100',
                [$tenantId, $branchId]);
            $supplierCredit = $this->one($pdo,
                'SELECT COALESCE(SUM(GREATEST(0, sp.paid_amount + sp.credited_amount - sp.billed_amount)), 0) AS credit_total
                   FROM supplier_payables sp WHERE sp.tenant_id = ? AND sp.branch_id = ?',
                [$tenantId, $branchId]);
            $supplierPayments = $this->all($pdo,
                'SELECT pay.amount, pay.payment_method, pay.reference_no, pay.note, pay.paid_at,
                        po.order_no, s.name AS supplier_name, u.name AS paid_by_name
                   FROM supplier_payments pay
                   JOIN supplier_payables sp ON sp.id = pay.payable_id AND sp.tenant_id = pay.tenant_id
                   JOIN purchase_orders po ON po.id = sp.purchase_order_id AND po.tenant_id = sp.tenant_id
                   JOIN suppliers s ON s.id = sp.supplier_id AND s.tenant_id = sp.tenant_id
                   JOIN users u ON u.id = pay.paid_by
                  WHERE pay.tenant_id = ? AND pay.branch_id = ?
                  ORDER BY pay.paid_at DESC, pay.id DESC LIMIT 100',
                [$tenantId, $branchId]);
            $purchaseReturns = $this->all($pdo,
                'SELECT pr.total_amount, pr.note, pr.returned_at, po.order_no, s.name AS supplier_name,
                        SUM(pri.qty) AS quantity, u.name AS returned_by_name
                   FROM purchase_returns pr
                   JOIN purchase_orders po ON po.id = pr.purchase_order_id AND po.tenant_id = pr.tenant_id
                   JOIN suppliers s ON s.id = pr.supplier_id AND s.tenant_id = pr.tenant_id
                   JOIN purchase_return_items pri ON pri.return_id = pr.id
                   JOIN users u ON u.id = pr.returned_by
                  WHERE pr.tenant_id = ? AND pr.branch_id = ?
                  GROUP BY pr.id ORDER BY pr.returned_at DESC, pr.id DESC LIMIT 100',
                [$tenantId, $branchId]);
            $counts = $this->all($pdo,
                'SELECT sc.id, sc.status, sc.notes, sc.created_at, u.name AS created_by,
                        COUNT(sci.id) AS item_count, SUM(sci.counted_qty IS NOT NULL) AS counted_items,
                        SUM(ABS(sci.variance_qty)) AS total_variance
                   FROM stock_counts sc JOIN users u ON u.id = sc.created_by
                   LEFT JOIN stock_count_items sci ON sci.stock_count_id = sc.id
                  WHERE sc.tenant_id = ? AND sc.branch_id = ?
                  GROUP BY sc.id ORDER BY sc.created_at DESC LIMIT 20',
                [$tenantId, $branchId]);
            $transfers = $this->all($pdo,
                'SELECT st.id, st.to_branch_id, st.status, st.notes, st.shipped_at, fb.name AS from_name, tb.name AS to_name,
                        COUNT(sti.id) AS item_count, SUM(sti.qty) AS quantity
                   FROM stock_transfers st
                   JOIN branches fb ON fb.id = st.from_branch_id AND fb.tenant_id = st.tenant_id
                   JOIN branches tb ON tb.id = st.to_branch_id AND tb.tenant_id = st.tenant_id
                   LEFT JOIN stock_transfer_items sti ON sti.transfer_id = st.id AND sti.tenant_id = st.tenant_id
                  WHERE st.tenant_id = ? AND (st.from_branch_id = ? OR st.to_branch_id = ?)
                  GROUP BY st.id ORDER BY st.shipped_at DESC LIMIT 50',
                [$tenantId, $branchId, $branchId]);
            $branches = $ctx['role'] === 'cashier' ? [] : $this->all($pdo,
                'SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY name',
                [$tenantId]);
            $countItems = [];
            foreach ($counts as $count) {
                if ($count['status'] === 'open') {
                    $countItems[(int) $count['id']] = $this->all($pdo,
                        'SELECT sci.id, sci.variant_id, sci.batch_id, sci.expected_qty, sci.counted_qty,
                                p.name AS product_name, p.unit, v.name AS variant_name, v.sku, pb.batch_number
                           FROM stock_count_items sci
                           JOIN product_variants v ON v.id = sci.variant_id AND v.tenant_id = sci.tenant_id
                           JOIN products p ON p.id = v.product_id AND p.tenant_id = sci.tenant_id
                           LEFT JOIN product_batches pb ON pb.id = sci.batch_id AND pb.tenant_id = sci.tenant_id
                          WHERE sci.stock_count_id = ? AND sci.tenant_id = ? ORDER BY p.name, pb.expired_at',
                        [$count['id'], $tenantId]);
                }
            }
            $flash = $_SESSION['operations_flash'] ?? null;
            unset($_SESSION['operations_flash']);
            View::render('operations/index', [
                'base' => Router::base(), 'csrf' => Csrf::token(), 'context' => $ctx,
                'openShift' => $openShift, 'shifts' => $shifts, 'suppliers' => $suppliers,
                'products' => $products, 'orders' => $orders, 'payables' => $payables,
                'supplierCredit' => $supplierCredit['credit_total'] ?? 0,
                'supplierPayments' => $supplierPayments, 'purchaseReturns' => $purchaseReturns, 'counts' => $counts,
                'countItems' => $countItems, 'transfers' => $transfers,
                'branches' => $branches, 'batches' => $batches, 'flash' => is_array($flash) ? $flash : null,
            ]);
        } catch (Throwable $e) {
            error_log('[operations] page gagal: ' . $e->getMessage());
            Response::page(500, 'Operasional tidak tersedia', 'Data operasional gagal dimuat. Silakan coba lagi.');
        }
    }

    public function expenses(): void
    {
        try {
            $pdo = Database::pdo();
            self::ensureSchema($pdo);
            $ctx = $this->context($pdo);
            if (!in_array($ctx['role'], ['owner', 'manager'], true)) {
                Response::page(403, 'Akses ditolak', 'Pengeluaran hanya dapat dikelola pemilik atau manajer.');
                return;
            }
            $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
            $from = Request::query('from') ?? substr($today, 0, 8) . '01';
            $to = Request::query('to') ?? $today;
            if (!$this->validDate($from) || !$this->validDate($to) || $from > $to
                || (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days > 365) {
                Response::page(422, 'Rentang tanggal tidak valid', 'Pilih rentang tanggal maksimal 366 hari.');
                return;
            }
            $expenses = $this->all($pdo,
                'SELECT e.id, e.expense_date, e.category, e.amount, e.payment_method, e.description,
                        e.created_at, u.name AS created_by
                   FROM operating_expenses e JOIN users u ON u.id = e.created_by
                  WHERE e.tenant_id = ? AND e.branch_id = ? AND e.expense_date >= ? AND e.expense_date <= ?
                  ORDER BY e.expense_date DESC, e.id DESC LIMIT 500',
                [$ctx['tenant_id'], $ctx['branch_id'], $from, $to]);
            $total = $this->one($pdo,
                'SELECT COALESCE(SUM(amount), 0) AS total, COUNT(*) AS count
                   FROM operating_expenses WHERE tenant_id = ? AND branch_id = ?
                    AND expense_date >= ? AND expense_date <= ?',
                [$ctx['tenant_id'], $ctx['branch_id'], $from, $to]);
            $cashShiftOpen = $this->one($pdo,
                'SELECT id FROM cashier_shifts
                  WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
                  ORDER BY opened_at DESC, id DESC LIMIT 1',
                [$ctx['tenant_id'], $ctx['branch_id']]) !== null;
            $flash = $_SESSION['operations_flash'] ?? null;
            unset($_SESSION['operations_flash']);
            View::render('operations/expenses', [
                'base' => Router::base(), 'csrf' => Csrf::token(), 'context' => $ctx,
                'expenses' => $expenses, 'total' => $total, 'from' => $from, 'to' => $to,
                'categories' => self::EXPENSE_CATEGORIES, 'methods' => self::EXPENSE_METHODS,
                'cashShiftOpen' => $cashShiftOpen,
                'flash' => is_array($flash) ? $flash : null,
            ]);
        } catch (Throwable $e) {
            error_log('[expenses] page gagal: ' . $e->getMessage());
            Response::page(500, 'Pengeluaran tidak tersedia', 'Data pengeluaran gagal dimuat. Silakan coba lagi.');
        }
    }

    public function createExpense(): void
    {
        try {
            $pdo = Database::pdo();
            self::ensureSchema($pdo);
            $ctx = $this->context($pdo);
            if (!in_array($ctx['role'], ['owner', 'manager'], true)) {
                throw new \RuntimeException('Pengeluaran hanya dapat dicatat pemilik atau manajer.');
            }
            $date = trim((string) Request::post('expense_date'));
            $category = trim((string) Request::post('category'));
            $amount = $this->money(Request::post('amount'));
            $method = trim((string) Request::post('payment_method'));
            $description = trim((string) Request::post('description'));
            if (!$this->validDate($date) || $date > date('Y-m-d')
                || !in_array($category, self::EXPENSE_CATEGORIES, true)
                || $amount === null || $amount <= 0 || $amount > 9999999999999.99
                || !in_array($method, self::EXPENSE_METHODS, true)
                || $description === '' || $this->length($description) > 255) {
                throw new \RuntimeException('Tanggal, kategori, nominal, metode pembayaran, atau keterangan tidak valid.');
            }
            $pdo->beginTransaction();
            $shift = null;
            if ($method === 'cash') {
                $shift = $this->one($pdo,
                    'SELECT id FROM cashier_shifts
                      WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
                      ORDER BY opened_at DESC, id DESC LIMIT 1 FOR UPDATE',
                    [$ctx['tenant_id'], $ctx['branch_id']]);
                if ($shift === null) {
                    throw new \RuntimeException('Pengeluaran tunai hanya dapat dicatat saat shift kas cabang sedang terbuka.');
                }
            }
            $statement = $pdo->prepare(
                'INSERT INTO operating_expenses
                    (tenant_id, branch_id, expense_date, category, amount, payment_method, description, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $statement->execute([
                $ctx['tenant_id'], $ctx['branch_id'], $date, $category, $amount,
                $method, $description, $ctx['user_id'],
            ]);
            $expenseId = (int) $pdo->lastInsertId();
            if ($shift !== null) {
                $movement = $pdo->prepare(
                    'INSERT INTO cashier_shift_movements
                        (tenant_id, branch_id, shift_id, user_id, movement_type, amount, reason)
                     VALUES (?, ?, ?, ?, \'cash_out\', ?, ?)'
                );
                $movement->execute([
                    $ctx['tenant_id'], $ctx['branch_id'], $shift['id'], $ctx['user_id'],
                    $amount, 'Pengeluaran #' . $expenseId,
                ]);
            }
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'expense.created', 'operating_expense', $expenseId,
                ['amount' => number_format($amount, 2, '.', ''), 'category' => $category, 'payment_method' => $method]);
            $pdo->commit();
            $this->flash('success', 'Pengeluaran berhasil dicatat.');
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[expenses] simpan gagal: ' . $e->getMessage());
            $this->flash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Pengeluaran gagal disimpan.');
        }
        Response::redirect(Router::base() . '/expenses');
    }

    public function startShift(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $openingCash = $this->money(Request::post('opening_cash'));
            $note = trim((string) Request::post('note'));
            if ($openingCash === null || $openingCash < 0 || $openingCash > 9999999999999 || $this->length($note) > 255) {
                throw new \RuntimeException('Nominal kas awal atau catatan tidak valid.');
            }
            $pdo->beginTransaction();
            $branch = $this->one($pdo,
                'SELECT id FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL FOR UPDATE',
                [$ctx['branch_id'], $ctx['tenant_id']]);
            if ($branch === null) {
                throw new \RuntimeException('Cabang aktif tidak ditemukan.');
            }
            if ($this->openShift($pdo, $ctx, true) !== null) {
                throw new \RuntimeException('Cabang ini masih memiliki shift bersama yang terbuka.');
            }
            $pdo->prepare(
                'INSERT INTO cashier_shifts (tenant_id, branch_id, cashier_id, opening_cash, opening_note)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([$ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'], $openingCash, $note ?: null]);
            $shiftId = (int) $pdo->lastInsertId();
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'shift.opened', 'cashier_shift', $shiftId, ['opening_cash' => number_format($openingCash, 2, '.', '')]);
            $pdo->commit();
            $this->flash('success', 'Shift kas bersama berhasil dibuka. Transaksi semua kasir di cabang ini akan masuk ke rekonsiliasi shift yang sama.');
        });
        Response::redirect(Router::base() . '/operations#shift');
    }

    public function shiftMovement(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $amount = $this->money(Request::post('amount'));
            $type = (string) Request::post('movement_type');
            $reason = trim((string) Request::post('reason'));
            if (!in_array($type, ['cash_in', 'cash_out'], true) || $amount === null || $amount <= 0
                || $amount > 9999999999999 || $this->length($reason) < 3 || $this->length($reason) > 255) {
                throw new \RuntimeException('Jenis, nominal, dan alasan pergerakan kas tidak valid.');
            }
            $pdo->beginTransaction();
            $shift = $this->openShift($pdo, $ctx, true);
            if ($shift === null) {
                throw new \RuntimeException('Buka shift sebelum mencatat kas masuk atau keluar.');
            }
            if ($type === 'cash_out' && $amount > $this->expectedCash($pdo, (int) $shift['id'])) {
                throw new \RuntimeException('Kas keluar melebihi saldo laci yang tersedia.');
            }
            $pdo->prepare(
                'INSERT INTO cashier_shift_movements (tenant_id, branch_id, shift_id, user_id, movement_type, amount, reason)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$ctx['tenant_id'], $ctx['branch_id'], $shift['id'], $ctx['user_id'], $type, $amount, $reason]);
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'shift.cash_movement', 'cashier_shift', (int) $shift['id'],
                ['movement_type' => $type, 'amount' => number_format($amount, 2, '.', '')]);
            $pdo->commit();
            $this->flash('success', 'Pergerakan kas berhasil dicatat.');
        });
        Response::redirect(Router::base() . '/operations#shift');
    }

    public function closeShift(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $counted = $this->money(Request::post('counted_cash'));
            $note = trim((string) Request::post('note'));
            if ($counted === null || $counted < 0 || $counted > 9999999999999 || $this->length($note) > 255) {
                throw new \RuntimeException('Uang hasil hitung atau catatan tutup kas tidak valid.');
            }
            $pdo->beginTransaction();
            $shift = $this->openShift($pdo, $ctx, true);
            if ($shift === null) {
                throw new \RuntimeException('Tidak ada shift aktif untuk ditutup.');
            }
            if ($ctx['role'] === 'cashier' && (int) $shift['cashier_id'] !== $ctx['user_id']) {
                throw new \RuntimeException('Hanya pembuka shift atau pemilik/manajer yang dapat menutup shift kas bersama.');
            }
            $expected = $this->expectedCash($pdo, (int) $shift['id']);
            $difference = round($counted - $expected, 2);
            $pdo->prepare(
                'UPDATE cashier_shifts SET status = \'closed\', expected_cash = ?, counted_cash = ?,
                        cash_difference = ?, closing_note = ?, closed_at = NOW(), closed_by = ?
                  WHERE id = ? AND tenant_id = ? AND status = \'open\''
            )->execute([$expected, $counted, $difference, $note ?: null, $ctx['user_id'], $shift['id'], $ctx['tenant_id']]);
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'shift.closed', 'cashier_shift', (int) $shift['id'], [
                    'expected_cash' => number_format($expected, 2, '.', ''),
                    'counted_cash' => number_format($counted, 2, '.', ''),
                    'difference' => number_format($difference, 2, '.', ''),
                ]);
            $pdo->commit();
            $this->flash($difference === 0.0 ? 'success' : 'warning',
                'Shift ditutup. Saldo seharusnya Rp' . number_format($expected, 2, ',', '.') .
                ', aktual Rp' . number_format($counted, 2, ',', '.') .
                ', selisih Rp' . number_format($difference, 2, ',', '.') . '.');
        });
        Response::redirect(Router::base() . '/operations#shift');
    }

    public function saveSupplier(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $name = trim((string) Request::post('name'));
            $phone = trim((string) Request::post('phone'));
            $email = strtolower(trim((string) Request::post('email')));
            $address = trim((string) Request::post('address'));
            $notes = trim((string) Request::post('notes'));
            if ($this->length($name) < 2 || $this->length($name) > 150 || strlen($phone) > 30
                || ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190))
                || $this->length($address) > 2000 || $this->length($notes) > 255) {
                throw new \RuntimeException('Periksa nama, kontak, alamat, dan catatan pemasok.');
            }
            $pdo->prepare('INSERT INTO suppliers (tenant_id, name, phone, email, address, notes) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$ctx['tenant_id'], $name, $phone ?: null, $email ?: null, $address ?: null, $notes ?: null]);
            $this->flash('success', 'Pemasok berhasil ditambahkan.');
        });
        Response::redirect(Router::base() . '/operations#purchases');
    }

    public function createPurchase(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $supplierId = $this->id(Request::post('supplier_id'));
            $variantId = $this->id(Request::post('variant_id'));
            $quantity = $this->decimal(Request::post('quantity'));
            $unitCost = $this->money(Request::post('unit_cost'));
            $batchNumber = trim((string) Request::post('batch_number'));
            $expiry = trim((string) Request::post('expired_at'));
            $dueAt = trim((string) Request::post('due_at'));
            $notes = trim((string) Request::post('notes'));
            if ($supplierId === null || $variantId === null || $quantity === null || $quantity <= 0 || $quantity > 999999.999
                || $unitCost === null || $unitCost < 0 || $unitCost > 9999999999999 || $this->length($notes) > 255
                || ($dueAt !== '' && (!$this->validDate($dueAt) || $dueAt < date('Y-m-d')))) {
                throw new \RuntimeException('Data pemasok, produk, jumlah, biaya, atau catatan pesanan tidak valid.');
            }
            $supplier = $this->one($pdo, 'SELECT id FROM suppliers WHERE id = ? AND tenant_id = ? AND is_active = 1', [$supplierId, $ctx['tenant_id']]);
            $product = $this->one($pdo,
                'SELECT p.id, p.name, p.track_batch, p.track_stock, v.id AS variant_id
                   FROM product_variants v JOIN products p ON p.id = v.product_id AND p.tenant_id = v.tenant_id
                  WHERE v.id = ? AND v.tenant_id = ? AND v.is_active = 1 AND v.deleted_at IS NULL
                    AND p.is_active = 1 AND p.deleted_at IS NULL',
                [$variantId, $ctx['tenant_id']]);
            if ($supplier === null || $product === null || (int) $product['track_stock'] !== 1) {
                throw new \RuntimeException('Pemasok atau produk stok tidak ditemukan pada usaha ini.');
            }
            if ((int) $product['track_batch'] === 1) {
                if ($batchNumber === '' || strlen($batchNumber) > 60 || !$this->validDate($expiry) || $expiry < date('Y-m-d')) {
                    throw new \RuntimeException('Produk batch wajib memiliki nomor batch dan kedaluwarsa hari ini atau yang akan datang.');
                }
            } elseif ($batchNumber !== '' || $expiry !== '') {
                throw new \RuntimeException('Nomor batch hanya diisi untuk produk yang melacak batch.');
            }
            $orderNo = 'PO-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO purchase_orders (tenant_id, branch_id, supplier_id, order_no, status, total_amount, notes, created_by, due_at)
                 VALUES (?, ?, ?, ?, \'ordered\', ?, ?, ?, ?)'
            )->execute([
                $ctx['tenant_id'], $ctx['branch_id'], $supplierId, $orderNo,
                number_format($quantity * $unitCost, 2, '.', ''), $notes ?: null, $ctx['user_id'], $dueAt ?: null,
            ]);
            $orderId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO purchase_order_items (tenant_id, purchase_order_id, variant_id, qty_ordered, unit_cost, batch_number, expired_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx['tenant_id'], $orderId, $variantId, $quantity, $unitCost,
                $batchNumber ?: null, $expiry ?: null,
            ]);
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'purchase_order.created', 'purchase_order', $orderId,
                ['order_no' => $orderNo, 'amount' => number_format($quantity * $unitCost, 2, '.', '')]);
            $pdo->commit();
            $this->flash('success', 'Pesanan pembelian ' . $orderNo . ' dibuat. Terima barang saat pemasok mengirimkannya.');
        });
        Response::redirect(Router::base() . '/operations#purchases');
    }

    public function receivePurchase(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $orderId = $this->id(Request::post('order_id'));
            if ($orderId === null) {
                throw new \RuntimeException('Pesanan pembelian tidak valid.');
            }
            $pdo->beginTransaction();
            $order = $this->one($pdo,
                'SELECT id, order_no, supplier_id, total_amount, due_at, status FROM purchase_orders
                  WHERE id = ? AND tenant_id = ? AND branch_id = ? FOR UPDATE',
                [$orderId, $ctx['tenant_id'], $ctx['branch_id']]);
            if ($order === null || $order['status'] !== 'ordered') {
                throw new \RuntimeException('Pesanan tidak tersedia atau sudah diproses.');
            }
            $items = $this->all($pdo,
                'SELECT poi.id, poi.variant_id, poi.qty_ordered, poi.unit_cost, poi.batch_number, poi.expired_at,
                        p.id AS product_id, p.name, p.track_batch, p.cost_price AS product_cost,
                        v.cost_price AS variant_cost
                   FROM purchase_order_items poi
                   JOIN product_variants v ON v.id = poi.variant_id AND v.tenant_id = poi.tenant_id
                   JOIN products p ON p.id = v.product_id AND p.tenant_id = poi.tenant_id
                  WHERE poi.purchase_order_id = ? AND poi.tenant_id = ? FOR UPDATE',
                [$orderId, $ctx['tenant_id']]);
            if (!$items) {
                throw new \RuntimeException('Pesanan tidak memiliki item untuk diterima.');
            }
            foreach ($items as $item) {
                $qty = (float) $item['qty_ordered'];
                $variantId = (int) $item['variant_id'];
                if ((int) $item['track_batch'] === 1 && (!$item['batch_number'] || !$this->validDate((string) $item['expired_at']) || (string) $item['expired_at'] < date('Y-m-d'))) {
                    throw new \RuntimeException('Data batch/kedaluwarsa item ' . $item['name'] . ' tidak valid.');
                }
                $this->ensureStockRow($pdo, $ctx, $variantId);
                $stock = $this->one($pdo,
                    'SELECT qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                    [$ctx['tenant_id'], $ctx['branch_id'], $variantId]);
                $before = (float) ($stock['qty'] ?? 0);
                $after = round($before + $qty, 3);
                $batchId = null;
                if ((int) $item['track_batch'] === 1) {
                    $batchId = $this->receiveBatchIntoStock($pdo, $ctx, $item, $qty);
                }
                $pdo->prepare('UPDATE stock_levels SET qty = ? WHERE tenant_id = ? AND branch_id = ? AND variant_id = ?')
                    ->execute([$after, $ctx['tenant_id'], $ctx['branch_id'], $variantId]);
                $pdo->prepare(
                    'INSERT INTO stock_movements
                     (tenant_id, branch_id, variant_id, batch_id, movement_type, qty_change, qty_after, reference_type, reference_id, note, created_by)
                     VALUES (?, ?, ?, ?, \'purchase\', ?, ?, \'purchase_order\', ?, ?, ?)'
                )->execute([
                    $ctx['tenant_id'], $ctx['branch_id'], $variantId, $batchId, $qty, $after,
                    $orderId, 'Penerimaan ' . $order['order_no'], $ctx['user_id'],
                ]);
                $oldCost = $before > 0 ? (float) ($item['variant_cost'] ?? $item['product_cost']) : (float) $item['unit_cost'];
                $averageCost = $after > 0 ? (($before * $oldCost) + ($qty * (float) $item['unit_cost'])) / $after : (float) $item['unit_cost'];
                $pdo->prepare('UPDATE products SET cost_price = ? WHERE id = ? AND tenant_id = ?')
                    ->execute([number_format($averageCost, 2, '.', ''), $item['product_id'], $ctx['tenant_id']]);
                $pdo->prepare('UPDATE product_variants SET cost_price = ? WHERE id = ? AND tenant_id = ?')
                    ->execute([number_format($averageCost, 2, '.', ''), $variantId, $ctx['tenant_id']]);
                $pdo->prepare('UPDATE purchase_order_items SET qty_received = qty_ordered WHERE id = ? AND tenant_id = ?')
                    ->execute([$item['id'], $ctx['tenant_id']]);
                $pdo->prepare('UPDATE purchase_order_items SET received_batch_id = ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$batchId, $item['id'], $ctx['tenant_id']]);
            }
            $pdo->prepare(
                'UPDATE purchase_orders SET status = \'received\', received_by = ?, received_at = NOW()
                  WHERE id = ? AND tenant_id = ?'
            )->execute([$ctx['user_id'], $orderId, $ctx['tenant_id']]);
            $pdo->prepare(
                'INSERT INTO supplier_payables
                 (tenant_id, branch_id, supplier_id, purchase_order_id, billed_amount, due_at)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx['tenant_id'], $ctx['branch_id'], $order['supplier_id'], $orderId,
                $order['total_amount'], $order['due_at'],
            ]);
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'purchase_order.received', 'purchase_order', $orderId,
                ['order_no' => $order['order_no'], 'amount' => (string) $order['total_amount']]);
            $pdo->commit();
            $this->flash('success', 'Barang pesanan ' . $order['order_no'] . ' diterima. Stok, harga modal, dan tagihan pemasok diperbarui.');
        });
        Response::redirect(Router::base() . '/operations#purchases');
    }

    public function paySupplier(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $payableId = $this->id(Request::post('payable_id'));
            $amount = $this->money(Request::post('amount'));
            $method = (string) Request::post('payment_method');
            $reference = trim((string) Request::post('reference_no'));
            $note = trim((string) Request::post('note'));
            $methods = ['cash', 'qris', 'transfer', 'debit', 'credit', 'other'];
            if ($payableId === null || $amount === null || $amount <= 0 || $amount > 9999999999999
                || !in_array($method, $methods, true) || strlen($reference) > 80 || $this->length($note) > 255) {
                throw new \RuntimeException('Data pembayaran pemasok tidak valid.');
            }

            $pdo->beginTransaction();
            $payable = $this->one($pdo,
                'SELECT id, purchase_order_id, billed_amount, paid_amount, credited_amount
                   FROM supplier_payables
                  WHERE id = ? AND tenant_id = ? AND branch_id = ? FOR UPDATE',
                [$payableId, $ctx['tenant_id'], $ctx['branch_id']]);
            if ($payable === null) {
                throw new \RuntimeException('Tagihan pemasok tidak ditemukan.');
            }
            $balance = round((float) $payable['billed_amount'] - (float) $payable['paid_amount'] - (float) $payable['credited_amount'], 2);
            if ($balance <= 0 || $amount > $balance) {
                throw new \RuntimeException('Nominal pembayaran melebihi saldo tagihan pemasok.');
            }

            $shiftId = null;
            if ($method === 'cash') {
                $shift = $this->one($pdo,
                    'SELECT id FROM cashier_shifts
                      WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
                      ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    [$ctx['tenant_id'], $ctx['branch_id']]);
                if ($shift === null) {
                    throw new \RuntimeException('Buka shift kas sebelum mencatat pembayaran pemasok tunai.');
                }
                $shiftId = (int) $shift['id'];
            }
            $pdo->prepare(
                'INSERT INTO supplier_payments
                 (tenant_id, branch_id, payable_id, amount, payment_method, reference_no, note, paid_by, cashier_shift_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx['tenant_id'], $ctx['branch_id'], $payableId, number_format($amount, 2, '.', ''),
                $method, $reference ?: null, $note ?: null, $ctx['user_id'], $shiftId,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            $pdo->prepare('UPDATE supplier_payables SET paid_amount = paid_amount + ? WHERE id = ? AND tenant_id = ?')
                ->execute([number_format($amount, 2, '.', ''), $payableId, $ctx['tenant_id']]);
            if ($shiftId !== null) {
                $pdo->prepare(
                    'INSERT INTO cashier_shift_movements
                     (tenant_id, branch_id, shift_id, user_id, movement_type, amount, reason)
                     VALUES (?, ?, ?, ?, \'cash_out\', ?, ?)'
                )->execute([
                    $ctx['tenant_id'], $ctx['branch_id'], $shiftId, $ctx['user_id'],
                    number_format($amount, 2, '.', ''), 'Pembayaran pemasok #' . $paymentId,
                ]);
            }
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'supplier_payment.created', 'supplier_payment', $paymentId,
                ['payable_id' => $payableId, 'amount' => number_format($amount, 2, '.', ''), 'payment_method' => $method]);
            $pdo->commit();
            $this->flash('success', 'Pembayaran pemasok berhasil dicatat.');
        });
        Response::redirect(Router::base() . '/operations#payables');
    }

    public function returnPurchase(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $purchaseItemId = $this->id(Request::post('purchase_item_id'));
            $quantity = $this->decimal(Request::post('quantity'));
            $note = trim((string) Request::post('note'));
            if ($purchaseItemId === null || $quantity === null || $quantity <= 0 || $quantity > 999999.999
                || $this->length($note) > 255) {
                throw new \RuntimeException('Data retur pembelian tidak valid.');
            }

            $pdo->beginTransaction();
            $item = $this->one($pdo,
                'SELECT poi.id, poi.purchase_order_id, poi.variant_id, poi.qty_received, poi.unit_cost,
                        poi.received_batch_id, po.supplier_id, po.status, p.name AS product_name, p.track_batch
                   FROM purchase_order_items poi
                   JOIN purchase_orders po ON po.id = poi.purchase_order_id AND po.tenant_id = poi.tenant_id
                   JOIN product_variants v ON v.id = poi.variant_id AND v.tenant_id = poi.tenant_id
                   JOIN products p ON p.id = v.product_id AND p.tenant_id = poi.tenant_id
                  WHERE poi.id = ? AND poi.tenant_id = ? AND po.branch_id = ? FOR UPDATE',
                [$purchaseItemId, $ctx['tenant_id'], $ctx['branch_id']]);
            if ($item === null || $item['status'] !== 'received') {
                throw new \RuntimeException('Item pembelian belum diterima atau tidak ditemukan.');
            }
            $payable = $this->one($pdo,
                'SELECT id FROM supplier_payables
                  WHERE purchase_order_id = ? AND tenant_id = ? AND branch_id = ? FOR UPDATE',
                [$item['purchase_order_id'], $ctx['tenant_id'], $ctx['branch_id']]);
            if ($payable === null) {
                throw new \RuntimeException('Tagihan pembelian tidak ditemukan; retur lama tidak dapat dicatat otomatis.');
            }
            $returned = $this->one($pdo,
                'SELECT COALESCE(SUM(pri.qty), 0) AS qty
                   FROM purchase_return_items pri
                   JOIN purchase_returns pr ON pr.id = pri.return_id
                  WHERE pri.purchase_order_item_id = ? AND pr.tenant_id = ?',
                [$purchaseItemId, $ctx['tenant_id']]);
            $remaining = round((float) $item['qty_received'] - (float) ($returned['qty'] ?? 0), 3);
            if ($quantity > $remaining) {
                throw new \RuntimeException('Jumlah retur melebihi sisa jumlah barang yang belum dikembalikan.');
            }
            $stock = $this->one($pdo,
                'SELECT qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                [$ctx['tenant_id'], $ctx['branch_id'], $item['variant_id']]);
            if ($stock === null || (float) $stock['qty'] < $quantity) {
                throw new \RuntimeException('Stok saat ini tidak cukup untuk mengirim jumlah retur tersebut ke pemasok.');
            }
            $batchId = $item['received_batch_id'] === null ? null : (int) $item['received_batch_id'];
            if ((int) $item['track_batch'] === 1 && $batchId === null) {
                throw new \RuntimeException('Batch penerimaan pesanan ini tidak tercatat. Rekonsiliasi batch sebelum memproses retur.');
            }
            if ($batchId !== null) {
                $batch = $this->one($pdo,
                    'SELECT qty_remaining FROM product_batches
                      WHERE id = ? AND tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                    [$batchId, $ctx['tenant_id'], $ctx['branch_id'], $item['variant_id']]);
                if ($batch === null || (float) $batch['qty_remaining'] < $quantity) {
                    throw new \RuntimeException('Stok batch yang diterima dari pesanan tidak cukup untuk retur.');
                }
                $pdo->prepare('UPDATE product_batches SET qty_remaining = qty_remaining - ? WHERE id = ?')
                    ->execute([number_format($quantity, 3, '.', ''), $batchId]);
            }

            $amount = round($quantity * (float) $item['unit_cost'], 2);
            $pdo->prepare(
                'INSERT INTO purchase_returns
                 (tenant_id, branch_id, supplier_id, purchase_order_id, payable_id, total_amount, note, returned_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx['tenant_id'], $ctx['branch_id'], $item['supplier_id'], $item['purchase_order_id'],
                $payable['id'], number_format($amount, 2, '.', ''), $note ?: null, $ctx['user_id'],
            ]);
            $returnId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO purchase_return_items (return_id, purchase_order_item_id, qty, unit_cost, amount)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $returnId, $purchaseItemId, number_format($quantity, 3, '.', ''),
                $item['unit_cost'], number_format($amount, 2, '.', ''),
            ]);
            $pdo->prepare('UPDATE supplier_payables SET credited_amount = credited_amount + ? WHERE id = ? AND tenant_id = ?')
                ->execute([number_format($amount, 2, '.', ''), $payable['id'], $ctx['tenant_id']]);
            $after = round((float) $stock['qty'] - $quantity, 3);
            $pdo->prepare('UPDATE stock_levels SET qty = ? WHERE tenant_id = ? AND branch_id = ? AND variant_id = ?')
                ->execute([number_format($after, 3, '.', ''), $ctx['tenant_id'], $ctx['branch_id'], $item['variant_id']]);
            $this->stockMovement(
                $pdo, $ctx, (int) $item['variant_id'], $batchId, 'return', -$quantity, $after,
                'purchase_return', $returnId, 'Retur pembelian ' . $item['product_name']
            );
            AuditLogger::record($pdo, $ctx['tenant_id'], $ctx['branch_id'], $ctx['user_id'],
                'purchase_return.created', 'purchase_return', $returnId,
                ['purchase_order_id' => (int) $item['purchase_order_id'], 'quantity' => number_format($quantity, 3, '.', ''), 'amount' => number_format($amount, 2, '.', '')]);
            $pdo->commit();
            $this->flash('success', 'Retur pembelian dicatat. Stok berkurang dan kredit pemasok bertambah Rp' . number_format($amount, 2, ',', '.') . '.');
        });
        Response::redirect(Router::base() . '/operations#payables');
    }

    private function receiveBatchIntoStock(PDO $pdo, array $ctx, array $item, float $qty): int
    {
        $batch = $this->one($pdo,
            'SELECT id, expired_at FROM product_batches
              WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? AND batch_number = ? FOR UPDATE',
            [$ctx['tenant_id'], $ctx['branch_id'], $item['variant_id'], $item['batch_number']]);
        if ($batch !== null && (string) $batch['expired_at'] !== (string) $item['expired_at']) {
            throw new \RuntimeException('Nomor batch ' . $item['batch_number'] . ' sudah tersimpan dengan tanggal kedaluwarsa berbeda.');
        }
        if ($batch === null) {
            $pdo->prepare(
                'INSERT INTO product_batches
                 (tenant_id, branch_id, variant_id, batch_number, expired_at, qty_initial, qty_remaining, cost_price, received_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
            )->execute([
                $ctx['tenant_id'], $ctx['branch_id'], $item['variant_id'], $item['batch_number'],
                $item['expired_at'], $qty, $qty, $item['unit_cost'],
            ]);
            return (int) $pdo->lastInsertId();
        }
        $pdo->prepare(
            'UPDATE product_batches SET qty_initial = qty_initial + ?, qty_remaining = qty_remaining + ?, cost_price = ?
              WHERE id = ?'
        )->execute([$qty, $qty, $item['unit_cost'], $batch['id']]);
        return (int) $batch['id'];
    }

    public function createStockCount(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $notes = trim((string) Request::post('notes'));
            if ($this->length($notes) > 255) {
                throw new \RuntimeException('Catatan stok opname maksimal 255 karakter.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO stock_counts (tenant_id, branch_id, status, notes, created_by) VALUES (?, ?, \'open\', ?, ?)')
                ->execute([$ctx['tenant_id'], $ctx['branch_id'], $notes ?: null, $ctx['user_id']]);
            $countId = (int) $pdo->lastInsertId();
            $variants = $this->all($pdo,
                'SELECT p.name, p.track_batch, v.id AS variant_id, COALESCE(sl.qty, 0) AS stock_qty
                   FROM products p
                   JOIN product_variants v ON v.product_id = p.id AND v.tenant_id = p.tenant_id
                        AND v.is_default = 1 AND v.is_active = 1 AND v.deleted_at IS NULL
                   LEFT JOIN stock_levels sl ON sl.variant_id = v.id AND sl.branch_id = ? AND sl.tenant_id = p.tenant_id
                  WHERE p.tenant_id = ? AND p.is_active = 1 AND p.deleted_at IS NULL AND p.track_stock = 1
                  ORDER BY v.id',
                [$ctx['branch_id'], $ctx['tenant_id']]);
            $insert = $pdo->prepare(
                'INSERT INTO stock_count_items (tenant_id, stock_count_id, variant_id, batch_id, expected_qty)
                 VALUES (?, ?, ?, ?, ?)'
            );
            foreach ($variants as $variant) {
                $variantId = (int) $variant['variant_id'];
                if ((int) $variant['track_batch'] !== 1) {
                    $insert->execute([$ctx['tenant_id'], $countId, $variantId, null, $variant['stock_qty']]);
                    continue;
                }
                $batches = $this->all($pdo,
                    'SELECT id, qty_remaining FROM product_batches
                      WHERE tenant_id = ? AND branch_id = ? AND variant_id = ?
                      ORDER BY id FOR UPDATE',
                    [$ctx['tenant_id'], $ctx['branch_id'], $variantId]);
                $batchTotal = array_sum(array_map(static fn(array $row): float => (float) $row['qty_remaining'], $batches));
                if (abs($batchTotal - (float) $variant['stock_qty']) > 0.001) {
                    throw new \RuntimeException('Stok dan total batch ' . $variant['name'] . ' tidak cocok. Rekonsiliasi batch terlebih dahulu.');
                }
                if (!$batches) {
                    $insert->execute([$ctx['tenant_id'], $countId, $variantId, null, 0]);
                } else {
                    foreach ($batches as $batch) {
                        $insert->execute([$ctx['tenant_id'], $countId, $variantId, $batch['id'], $batch['qty_remaining']]);
                    }
                }
            }
            if (!$variants) {
                throw new \RuntimeException('Tidak ada produk stok aktif untuk dihitung.');
            }
            $pdo->commit();
            $this->flash('success', 'Stok opname #' . $countId . ' dibuat dengan snapshot stok saat ini.');
        });
        Response::redirect(Router::base() . '/operations#counts');
    }

    public function postStockCount(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $countId = $this->id(Request::post('count_id'));
            $rawCounts = $_POST['counted'] ?? null;
            if ($countId === null || !is_array($rawCounts)) {
                throw new \RuntimeException('Data stok opname tidak valid.');
            }
            $pdo->beginTransaction();
            $count = $this->one($pdo,
                'SELECT id, status FROM stock_counts WHERE id = ? AND tenant_id = ? AND branch_id = ? FOR UPDATE',
                [$countId, $ctx['tenant_id'], $ctx['branch_id']]);
            if ($count === null || $count['status'] !== 'open') {
                throw new \RuntimeException('Stok opname tidak ditemukan atau sudah diposting.');
            }
            $items = $this->all($pdo,
                'SELECT sci.id, sci.variant_id, sci.batch_id, sci.expected_qty, sci.counted_qty,
                        p.name, p.track_batch
                   FROM stock_count_items sci
                   JOIN product_variants v ON v.id = sci.variant_id AND v.tenant_id = sci.tenant_id
                   JOIN products p ON p.id = v.product_id AND p.tenant_id = sci.tenant_id
                  WHERE sci.stock_count_id = ? AND sci.tenant_id = ? ORDER BY sci.variant_id, sci.batch_id FOR UPDATE',
                [$countId, $ctx['tenant_id']]);
            if (!$items) {
                throw new \RuntimeException('Stok opname ini tidak memiliki baris untuk dihitung.');
            }
            $countsByVariant = [];
            foreach ($items as $item) {
                $postedValue = $rawCounts[(string) $item['id']] ?? null;
                $counted = $this->decimal(is_string($postedValue) || is_numeric($postedValue) ? (string) $postedValue : null);
                if ($counted === null || $counted < 0 || $counted > 999999999.999) {
                    throw new \RuntimeException('Isi jumlah fisik dengan angka nol atau lebih untuk setiap item.');
                }
                $item['counted_qty'] = $counted;
                $countsByVariant[(int) $item['variant_id']][] = $item;
            }
            foreach ($countsByVariant as $variantId => $variantItems) {
                $this->ensureStockRow($pdo, $ctx, $variantId);
                $stock = $this->one($pdo,
                    'SELECT qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                    [$ctx['tenant_id'], $ctx['branch_id'], $variantId]);
                $currentStock = (float) ($stock['qty'] ?? 0);
                $isBatch = (int) $variantItems[0]['track_batch'] === 1;
                if ($isBatch && $variantItems[0]['batch_id'] === null
                    && (float) $variantItems[0]['counted_qty'] > 0) {
                    throw new \RuntimeException('Produk batch ' . $variantItems[0]['name'] . ' belum memiliki batch. Terima stok dengan nomor batch terlebih dahulu.');
                }
                if ($isBatch && $variantItems[0]['batch_id'] !== null) {
                    $snapshotBatchIds = array_map(static fn(array $row): int => (int) $row['batch_id'], $variantItems);
                    $currentBatches = $this->all($pdo,
                        'SELECT id, qty_remaining FROM product_batches
                          WHERE tenant_id = ? AND branch_id = ? AND variant_id = ?
                          ORDER BY id FOR UPDATE',
                        [$ctx['tenant_id'], $ctx['branch_id'], $variantId]);
                    $currentBatchIds = array_map(static fn(array $row): int => (int) $row['id'], $currentBatches);
                    sort($snapshotBatchIds);
                    sort($currentBatchIds);
                    if ($snapshotBatchIds !== $currentBatchIds) {
                        throw new \RuntimeException('Batch ' . $variantItems[0]['name'] . ' berubah sejak snapshot dibuat. Buat stok opname baru.');
                    }
                }

                $countedTotal = 0.0;
                foreach ($variantItems as $item) {
                    $counted = (float) $item['counted_qty'];
                    $countedTotal += $counted;
                    $snapshotVariance = round($counted - (float) $item['expected_qty'], 3);
                    $pdo->prepare('UPDATE stock_count_items SET counted_qty = ?, variance_qty = ? WHERE id = ? AND tenant_id = ?')
                        ->execute([$counted, $snapshotVariance, $item['id'], $ctx['tenant_id']]);
                    if ($item['batch_id'] !== null) {
                        $batch = $this->one($pdo,
                            'SELECT qty_remaining FROM product_batches WHERE id = ? AND tenant_id = ? AND branch_id = ? FOR UPDATE',
                            [$item['batch_id'], $ctx['tenant_id'], $ctx['branch_id']]);
                        if ($batch === null) {
                            throw new \RuntimeException('Batch item stok opname tidak ditemukan.');
                        }
                        $batchDelta = round($counted - (float) $batch['qty_remaining'], 3);
                        $pdo->prepare('UPDATE product_batches SET qty_remaining = ? WHERE id = ? AND tenant_id = ?')
                            ->execute([$counted, $item['batch_id'], $ctx['tenant_id']]);
                        $this->stockMovement($pdo, $ctx, $variantId, (int) $item['batch_id'], 'adjustment', $batchDelta, $currentStock + $batchDelta, 'stock_count', $countId, 'Stok opname #' . $countId);
                        $currentStock += $batchDelta;
                    }
                }
                if (!$isBatch || $variantItems[0]['batch_id'] === null) {
                    $delta = round($countedTotal - $currentStock, 3);
                    $this->setStock($pdo, $ctx, $variantId, $countedTotal);
                    if (abs($delta) >= 0.001) {
                        $this->stockMovement($pdo, $ctx, $variantId, null, 'adjustment', $delta, $countedTotal, 'stock_count', $countId, 'Stok opname #' . $countId);
                    }
                } else {
                    $this->setStock($pdo, $ctx, $variantId, $countedTotal);
                }
            }
            $pdo->prepare('UPDATE stock_counts SET status = \'posted\', posted_by = ?, posted_at = NOW() WHERE id = ? AND tenant_id = ?')
                ->execute([$ctx['user_id'], $countId, $ctx['tenant_id']]);
            $pdo->commit();
            $this->flash('success', 'Stok opname #' . $countId . ' diposting. Selisih dan perubahan stok tercatat di ledger.');
        });
        Response::redirect(Router::base() . '/operations#counts');
    }

    public function shipTransfer(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $toBranch = $this->id(Request::post('to_branch_id'));
            $variantId = $this->id(Request::post('variant_id'));
            $quantity = $this->decimal(Request::post('quantity'));
            $batchId = $this->id(Request::post('batch_id'));
            $notes = trim((string) Request::post('notes'));
            if ($toBranch === null || $toBranch === $ctx['branch_id'] || $variantId === null
                || $quantity === null || $quantity <= 0 || $quantity > 999999.999 || $this->length($notes) > 255) {
                throw new \RuntimeException('Cabang tujuan, produk, jumlah, atau catatan transfer tidak valid.');
            }
            $pdo->beginTransaction();
            $destination = $this->one($pdo,
                'SELECT id FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
                [$toBranch, $ctx['tenant_id']]);
            $product = $this->one($pdo,
                'SELECT p.name, p.track_batch, p.track_stock, v.cost_price
                   FROM product_variants v JOIN products p ON p.id = v.product_id AND p.tenant_id = v.tenant_id
                  WHERE v.id = ? AND v.tenant_id = ? AND v.is_active = 1 AND v.deleted_at IS NULL
                    AND p.is_active = 1 AND p.deleted_at IS NULL',
                [$variantId, $ctx['tenant_id']]);
            if ($destination === null || $product === null || (int) $product['track_stock'] !== 1) {
                throw new \RuntimeException('Cabang atau produk stok tidak ditemukan pada usaha ini.');
            }
            if ((int) $product['track_batch'] === 1 && $batchId === null) {
                throw new \RuntimeException('Pilih batch untuk transfer obat agar tanggal kedaluwarsa tetap terlacak.');
            }
            if ((int) $product['track_batch'] !== 1 && $batchId !== null) {
                throw new \RuntimeException('Produk ini tidak menggunakan pelacakan batch.');
            }
            $this->ensureStockRow($pdo, $ctx, $variantId);
            $stock = $this->one($pdo,
                'SELECT qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                [$ctx['tenant_id'], $ctx['branch_id'], $variantId]);
            $before = (float) ($stock['qty'] ?? 0);
            if ($before < $quantity) {
                throw new \RuntimeException('Stok cabang asal tidak mencukupi.');
            }
            $batch = null;
            if ($batchId !== null) {
                $batch = $this->one($pdo,
                    'SELECT id, batch_number, expired_at, qty_remaining, cost_price FROM product_batches
                      WHERE id = ? AND tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                    [$batchId, $ctx['tenant_id'], $ctx['branch_id'], $variantId]);
                if ($batch === null || (float) $batch['qty_remaining'] < $quantity) {
                    throw new \RuntimeException('Stok batch cabang asal tidak mencukupi.');
                }
                if ((string) $batch['expired_at'] < date('Y-m-d')) {
                    throw new \RuntimeException('Batch kedaluwarsa tidak dapat dikirim ke cabang lain.');
                }
                $pdo->prepare('UPDATE product_batches SET qty_remaining = qty_remaining - ? WHERE id = ? AND tenant_id = ?')
                    ->execute([$quantity, $batchId, $ctx['tenant_id']]);
            }
            $after = round($before - $quantity, 3);
            $this->setStock($pdo, $ctx, $variantId, $after);
            $pdo->prepare(
                'INSERT INTO stock_transfers (tenant_id, from_branch_id, to_branch_id, status, notes, created_by)
                 VALUES (?, ?, ?, \'in_transit\', ?, ?)'
            )->execute([$ctx['tenant_id'], $ctx['branch_id'], $toBranch, $notes ?: null, $ctx['user_id']]);
            $transferId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'INSERT INTO stock_transfer_items
                 (tenant_id, transfer_id, variant_id, source_batch_id, batch_number, expired_at, qty, unit_cost)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx['tenant_id'], $transferId, $variantId, $batchId,
                $batch['batch_number'] ?? null, $batch['expired_at'] ?? null, $quantity,
                $batch['cost_price'] ?? $product['cost_price'] ?? 0,
            ]);
            $this->stockMovement($pdo, $ctx, $variantId, $batchId, 'transfer_out', -$quantity, $after, 'stock_transfer', $transferId, 'Transfer ke cabang #' . $toBranch);
            $pdo->commit();
            $this->flash('success', 'Transfer stok #' . $transferId . ' dikirim. Cabang tujuan harus mengonfirmasi penerimaan.');
        });
        Response::redirect(Router::base() . '/operations#transfers');
    }

    public function receiveTransfer(): void
    {
        $this->action(function (PDO $pdo, array $ctx): void {
            $this->manager($ctx);
            $transferId = $this->id(Request::post('transfer_id'));
            if ($transferId === null) {
                throw new \RuntimeException('Transfer tidak valid.');
            }
            $pdo->beginTransaction();
            $transfer = $this->one($pdo,
                'SELECT id, from_branch_id, to_branch_id, status FROM stock_transfers
                  WHERE id = ? AND tenant_id = ? AND to_branch_id = ? FOR UPDATE',
                [$transferId, $ctx['tenant_id'], $ctx['branch_id']]);
            if ($transfer === null || $transfer['status'] !== 'in_transit') {
                throw new \RuntimeException('Transfer tidak ditemukan pada cabang tujuan atau sudah diterima.');
            }
            $items = $this->all($pdo,
                'SELECT sti.variant_id, sti.batch_number, sti.expired_at, sti.qty, sti.unit_cost,
                        p.name, p.track_batch
                   FROM stock_transfer_items sti
                   JOIN product_variants v ON v.id = sti.variant_id AND v.tenant_id = sti.tenant_id
                   JOIN products p ON p.id = v.product_id AND p.tenant_id = sti.tenant_id
                  WHERE sti.transfer_id = ? AND sti.tenant_id = ? ORDER BY sti.variant_id FOR UPDATE',
                [$transferId, $ctx['tenant_id']]);
            if (!$items) {
                throw new \RuntimeException('Transfer tidak memiliki item.');
            }
            foreach ($items as $item) {
                $variantId = (int) $item['variant_id'];
                $qty = (float) $item['qty'];
                $batchId = null;
                if ((int) $item['track_batch'] === 1) {
                    if (!$item['batch_number'] || !$this->validDate((string) $item['expired_at'])) {
                        throw new \RuntimeException('Informasi batch transfer tidak lengkap.');
                    }
                    $batch = $this->one($pdo,
                        'SELECT id, expired_at FROM product_batches
                          WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? AND batch_number = ? FOR UPDATE',
                        [$ctx['tenant_id'], $ctx['branch_id'], $variantId, $item['batch_number']]);
                    if ($batch !== null && (string) $batch['expired_at'] !== (string) $item['expired_at']) {
                        throw new \RuntimeException('Nomor batch tujuan memiliki tanggal kedaluwarsa berbeda.');
                    }
                    if ($batch === null) {
                        $pdo->prepare(
                            'INSERT INTO product_batches
                             (tenant_id, branch_id, variant_id, batch_number, expired_at, qty_initial, qty_remaining, cost_price, received_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE())'
                        )->execute([
                            $ctx['tenant_id'], $ctx['branch_id'], $variantId, $item['batch_number'],
                            $item['expired_at'], $qty, $qty, $item['unit_cost'],
                        ]);
                        $batchId = (int) $pdo->lastInsertId();
                    } else {
                        $batchId = (int) $batch['id'];
                        $pdo->prepare('UPDATE product_batches SET qty_initial = qty_initial + ?, qty_remaining = qty_remaining + ?, cost_price = ? WHERE id = ?')
                            ->execute([$qty, $qty, $item['unit_cost'], $batchId]);
                    }
                }
                $this->ensureStockRow($pdo, $ctx, $variantId);
                $stock = $this->one($pdo,
                    'SELECT qty FROM stock_levels WHERE tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                    [$ctx['tenant_id'], $ctx['branch_id'], $variantId]);
                $after = round((float) ($stock['qty'] ?? 0) + $qty, 3);
                $this->setStock($pdo, $ctx, $variantId, $after);
                $this->stockMovement($pdo, $ctx, $variantId, $batchId, 'transfer_in', $qty, $after, 'stock_transfer', $transferId, 'Penerimaan transfer dari cabang #' . $transfer['from_branch_id']);
            }
            $pdo->prepare('UPDATE stock_transfers SET status = \'received\', received_by = ?, received_at = NOW() WHERE id = ? AND tenant_id = ?')
                ->execute([$ctx['user_id'], $transferId, $ctx['tenant_id']]);
            $pdo->commit();
            $this->flash('success', 'Transfer #' . $transferId . ' diterima. Stok cabang tujuan diperbarui.');
        });
        Response::redirect(Router::base() . '/operations#transfers');
    }

    private function context(PDO $pdo): array
    {
        if (!Auth::check()) {
            throw new \RuntimeException('Sesi berakhir. Silakan login kembali.');
        }
        $tenantId = (int) Auth::tenantId();
        $branchId = (int) Auth::branchId();
        $userId = Auth::userId();
        $role = Auth::role();
        if ($tenantId <= 0 || $branchId <= 0 || $userId <= 0
            || !in_array($role, ['owner', 'manager', 'cashier'], true)) {
            throw new \RuntimeException('Akun belum terhubung ke usaha/cabang yang berwenang.');
        }
        $user = $this->one($pdo,
            'SELECT id, tenant_id, branch_id, role, is_active FROM users WHERE id = ? AND deleted_at IS NULL',
            [$userId]);
        if ($user === null || (int) $user['is_active'] !== 1 || $user['role'] !== $role) {
            throw new \RuntimeException('Akun tidak aktif atau sesi tidak valid.');
        }
        if ($role === 'owner') {
            $ownerAccountTables = $this->one($pdo,
                'SELECT COUNT(*) AS total FROM information_schema.tables
                  WHERE table_schema = DATABASE() AND table_name IN (\'owner_accounts\', \'owner_account_tenants\')',
                []);
            if ((int) ($ownerAccountTables['total'] ?? 0) === 2) {
                $membership = $this->one($pdo,
                    'SELECT oa.status, oa.subscription_ends_at
                       FROM owner_accounts oa
                       JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id
                      WHERE oa.owner_user_id = ? AND oat.tenant_id = ?',
                    [$userId, $tenantId]);
                if ($membership === null) {
                    throw new \RuntimeException('Usaha ini bukan bagian dari akun pemilik.');
                }
                $status = $membership['status'];
                $ends = $membership['subscription_ends_at'];
            } else {
                if ($user['tenant_id'] === null || (int) $user['tenant_id'] !== $tenantId) {
                    throw new \RuntimeException('Akun tidak terhubung ke usaha ini.');
                }
                $membership = null;
                $status = null;
                $ends = null;
            }
        } else {
            if ($user['tenant_id'] === null || (int) $user['tenant_id'] !== $tenantId
                || ($user['branch_id'] !== null && (int) $user['branch_id'] !== $branchId)) {
                throw new \RuntimeException('Akun tidak terdaftar pada usaha/cabang ini.');
            }
            $membership = null;
            $status = null;
            $ends = null;
        }
        $tenant = $this->one($pdo,
            'SELECT t.name AS tenant_name, t.status AS tenant_status, t.subscription_ends_at AS tenant_ends,
                    t.timezone, b.id AS branch_id, b.name AS branch_name
               FROM tenants t JOIN branches b ON b.tenant_id = t.id
              WHERE t.id = ? AND b.id = ? AND t.deleted_at IS NULL AND b.is_active = 1 AND b.deleted_at IS NULL',
            [$tenantId, $branchId]);
        if ($tenant === null) {
            throw new \RuntimeException('Usaha atau cabang aktif tidak ditemukan.');
        }
        if ($status === null) {
            $status = $tenant['tenant_status'];
            $ends = $tenant['tenant_ends'];
        }
        if (!in_array($status, ['trial', 'active'], true)) {
            throw new \RuntimeException('Langganan usaha tidak aktif.');
        }
        if ($ends !== null && strtotime((string) $ends) <= time()) {
            throw new \RuntimeException('Masa langganan usaha telah berakhir.');
        }
        return [
            'tenant_id' => $tenantId, 'branch_id' => $branchId, 'user_id' => $userId,
            'role' => $role, 'tenant_name' => $tenant['tenant_name'], 'branch_name' => $tenant['branch_name'],
        ];
    }

    private function openShift(PDO $pdo, array $ctx, bool $lock = false): ?array
    {
        return $this->one($pdo,
            'SELECT id, cashier_id, opening_cash, opened_at FROM cashier_shifts
              WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
              ORDER BY id DESC LIMIT 1' . ($lock ? ' FOR UPDATE' : ''),
            [$ctx['tenant_id'], $ctx['branch_id']]);
    }

    private function expectedCash(PDO $pdo, int $shiftId): float
    {
        $row = $this->one($pdo,
            'SELECT s.opening_cash
                    + COALESCE((SELECT SUM(CASE WHEN sm.movement_type = \'cash_in\' THEN sm.amount ELSE -sm.amount END)
                                  FROM cashier_shift_movements sm WHERE sm.shift_id = s.id), 0)
                    + COALESCE((SELECT SUM(tp.amount) FROM transaction_payments tp
                                  JOIN transactions t ON t.id = tp.transaction_id AND t.tenant_id = tp.tenant_id
                                 WHERE COALESCE(tp.cashier_shift_id, t.cashier_shift_id) = s.id AND tp.method = \'cash\'), 0)
                    - COALESCE((SELECT SUM(COALESCE(tr.settled_refund_amount, tr.refund_amount)) FROM transaction_returns tr
                                  WHERE tr.cashier_shift_id = s.id AND tr.refund_method = \'cash\'), 0) AS expected_cash
               FROM cashier_shifts s WHERE s.id = ?',
            [$shiftId]);
        return round((float) ($row['expected_cash'] ?? 0), 2);
    }

    private function ensureStockRow(PDO $pdo, array $ctx, int $variantId): void
    {
        $pdo->prepare(
            'INSERT INTO stock_levels (tenant_id, branch_id, variant_id, qty) VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE qty = qty'
        )->execute([$ctx['tenant_id'], $ctx['branch_id'], $variantId]);
    }

    private function setStock(PDO $pdo, array $ctx, int $variantId, float $qty): void
    {
        $pdo->prepare(
            'INSERT INTO stock_levels (tenant_id, branch_id, variant_id, qty) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE qty = VALUES(qty)'
        )->execute([$ctx['tenant_id'], $ctx['branch_id'], $variantId, number_format($qty, 3, '.', '')]);
    }

    private function stockMovement(
        PDO $pdo,
        array $ctx,
        int $variantId,
        ?int $batchId,
        string $movementType,
        float $change,
        float $after,
        string $referenceType,
        int $referenceId,
        string $note
    ): void {
        if (abs($change) < 0.001) {
            return;
        }
        $pdo->prepare(
            'INSERT INTO stock_movements
             (tenant_id, branch_id, variant_id, batch_id, movement_type, qty_change, qty_after, reference_type, reference_id, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ctx['tenant_id'], $ctx['branch_id'], $variantId, $batchId, $movementType,
            number_format($change, 3, '.', ''), number_format($after, 3, '.', ''),
            $referenceType, $referenceId, $note, $ctx['user_id'],
        ]);
    }

    private function manager(array $ctx): void
    {
        if (!in_array($ctx['role'], ['owner', 'manager'], true)) {
            throw new \RuntimeException('Fitur ini hanya dapat dikelola pemilik atau manajer.');
        }
    }

    private function action(callable $callback): void
    {
        $pdo = Database::pdo();
        try {
            self::ensureSchema($pdo);
            $ctx = $this->context($pdo);
            $callback($pdo, $ctx);
        } catch (\RuntimeException $e) {
            $this->rollback($pdo);
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[operations] aksi gagal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $this->flash('danger', 'Aksi gagal disimpan. Silakan periksa data lalu coba lagi.');
        }
    }

    private function id($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        return is_string($value) && ctype_digit($value) && strlen($value) < 19 && (int) $value > 0
            ? (int) $value : null;
    }

    private function decimal($value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/', $text)) {
            return null;
        }
        $number = (float) $text;
        return is_finite($number) ? $number : null;
    }

    private function money($value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '' || !preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $text)) {
            return null;
        }
        $number = (float) $text;
        return is_finite($number) ? round($number, 2) : null;
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        return $date !== false && ($errors === false || (!$errors['warning_count'] && !$errors['error_count']))
            && $date->format('Y-m-d') === $value;
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : (int) preg_match_all('/./us', $value);
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
        $_SESSION['operations_flash'] = ['type' => $type, 'message' => $message];
    }
}
