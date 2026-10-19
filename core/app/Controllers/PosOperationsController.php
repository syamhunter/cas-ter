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
use App\Core\RefundCalculator;
use App\Core\ReportProfitCalculator;
use DateTimeImmutable;
use PDO;
use Throwable;

final class PosReturnException extends \RuntimeException
{
}

final class PosOperationsController
{
    private const RETURN_METHODS = ['cash', 'qris', 'transfer', 'debit', 'credit', 'other'];

    public function history(): void
    {
        try {
            $pdo = Database::pdo();
            $scope = $this->scope($pdo);
            OperationsController::ensureSchema($pdo);
            $this->ensureReturnTables($pdo);
            $id = $this->positiveInt(Request::query('id'));

            if ($id !== null) {
                $transaction = $this->one($pdo,
                    'SELECT t.id, t.invoice_no, t.order_type, t.status, t.payment_status, t.due_at,
                            t.grand_total, t.paid_total, t.change_amount, t.notes, t.created_at, c.name AS customer_name,
                            GREATEST(0, t.grand_total - COALESCE(r.refunded, 0)
                                     - (t.paid_total - t.change_amount - COALESCE(r.settled_refund, 0))) AS receivable_balance
                       FROM transactions t
                       LEFT JOIN customers c ON c.id = t.customer_id AND c.tenant_id = t.tenant_id
                       LEFT JOIN (
                         SELECT transaction_id, SUM(refund_amount) AS refunded,
                                SUM(COALESCE(settled_refund_amount, refund_amount)) AS settled_refund
                           FROM transaction_returns WHERE tenant_id = ? GROUP BY transaction_id
                       ) r ON r.transaction_id = t.id
                      WHERE t.id = ? AND t.tenant_id = ? AND t.branch_id = ?',
                    [$scope['tenant_id'], $id, $scope['tenant_id'], $scope['branch_id']]);
                if ($transaction === null) {
                    Response::send(404, false, 'Transaksi tidak ditemukan.');
                }
                $transaction['items'] = $this->all($pdo,
                    'SELECT ti.id, ti.item_name, ti.item_type, ti.qty, ti.unit, ti.unit_price, ti.line_total,
                            ti.variant_id, ti.batch_id, COALESCE(p.track_stock, 0) AS track_stock,
                            COALESCE(p.track_batch, 0) AS track_batch,
                            COALESCE(tri.returned_qty, 0) AS returned_qty,
                            COALESCE(tri.refunded_amount, 0) AS refunded_amount
                       FROM transaction_items ti
                       LEFT JOIN products p ON p.id = ti.product_id
                       LEFT JOIN (
                         SELECT transaction_item_id, SUM(qty) AS returned_qty, SUM(refund_amount) AS refunded_amount
                           FROM transaction_return_items GROUP BY transaction_item_id
                       ) tri ON tri.transaction_item_id = ti.id
                      WHERE ti.transaction_id = ? AND ti.tenant_id = ? ORDER BY ti.id',
                    [$id, $scope['tenant_id']]);
                Response::send(200, true, 'OK', ['transaction' => $transaction]);
            }

            [$from, $to] = $this->dateRange();
            $search = trim((string) (Request::query('q') ?? ''));
            if (strlen($search) > 80) {
                Response::send(422, false, 'Pencarian maksimal 80 karakter.');
            }
            $params = [$scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59'];
            $where = 't.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at <= ?';
            if ($search !== '') {
                $where .= ' AND (t.invoice_no LIKE ? OR CAST(t.id AS CHAR) = ?)';
                $params[] = '%' . $search . '%';
                $params[] = $search;
            }
            $transactions = $this->all($pdo,
                "SELECT t.id, t.invoice_no, t.order_type, t.status, t.payment_status, t.due_at,
                        t.grand_total, t.paid_total, t.change_amount,
                        t.created_at, u.name AS cashier_name,
                        COALESCE(r.refunded, 0) AS refunded_total, c.name AS customer_name,
                        GREATEST(0, t.grand_total - COALESCE(r.refunded, 0)
                                 - (t.paid_total - t.change_amount - COALESCE(r.settled_refund, 0))) AS receivable_balance
                   FROM transactions t
                   JOIN users u ON u.id = t.cashier_id
                   LEFT JOIN customers c ON c.id = t.customer_id AND c.tenant_id = t.tenant_id
                   LEFT JOIN (
                     SELECT transaction_id, SUM(refund_amount) AS refunded,
                            SUM(COALESCE(settled_refund_amount, refund_amount)) AS settled_refund
                       FROM transaction_returns WHERE tenant_id = ? GROUP BY transaction_id
                   ) r ON r.transaction_id = t.id
                  WHERE $where ORDER BY t.created_at DESC, t.id DESC LIMIT 100",
                array_merge([$scope['tenant_id']], $params));
            Response::send(200, true, 'OK', ['items' => $transactions, 'from' => $from, 'to' => $to]);
        } catch (Throwable $e) {
            error_log('[pos-history] gagal: ' . $e->getMessage());
            Response::send(500, false, 'Gagal memuat riwayat transaksi.');
        }
    }

    public function transactionsPage(): void
    {
        try {
            $pdo = Database::pdo();
            $scope = $this->scope($pdo);
            $names = $this->one($pdo,
                'SELECT t.name AS tenant_name, b.name AS branch_name
                   FROM tenants t JOIN branches b ON b.tenant_id = t.id
                  WHERE t.id = ? AND b.id = ?',
                [$scope['tenant_id'], $scope['branch_id']]);
            if ($names === null) {
                Response::page(404, 'Cabang tidak ditemukan', 'Cabang usaha aktif tidak ditemukan.');
                return;
            }
            View::render('pos/transactions', [
                'base' => Router::base(), 'csrf' => Csrf::token(),
                'context' => $scope + $names,
                'canReturn' => in_array($scope['role'], ['owner', 'manager'], true),
            ]);
        } catch (Throwable $e) {
            error_log('[transactions-page] gagal: ' . $e->getMessage());
            Response::page(500, 'Transaksi tidak tersedia', 'Data transaksi gagal dimuat. Silakan coba lagi.');
        }
    }

    public function reportsPage(): void
    {
        try {
            $pdo = Database::pdo();
            $scope = $this->scope($pdo);
            OperationsController::ensureSchema($pdo);
            $names = $this->one($pdo,
                'SELECT t.name AS tenant_name, b.name AS branch_name
                   FROM tenants t JOIN branches b ON b.tenant_id = t.id
                  WHERE t.id = ? AND b.id = ?',
                [$scope['tenant_id'], $scope['branch_id']]);
            if ($names === null) {
                Response::page(404, 'Cabang tidak ditemukan', 'Cabang usaha aktif tidak ditemukan.');
                return;
            }
            View::render('pos/reports', [
                'base' => \App\Core\Router::base(), 'csrf' => Csrf::token(),
                'context' => $scope + $names,
            ]);
        } catch (Throwable $e) {
            error_log('[reports-page] gagal: ' . $e->getMessage());
            Response::page(500, 'Laporan tidak tersedia', 'Data laporan gagal dimuat. Silakan coba lagi.');
        }
    }

    public function report(): void
    {
        try {
            $pdo = Database::pdo();
            $scope = $this->scope($pdo);
            OperationsController::ensureSchema($pdo);
            $this->ensureReturnTables($pdo);
            $canViewProfit = in_array($scope['role'], ['owner', 'manager'], true);
            [$from, $to] = $this->dateRange();
            $params = [$scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59'];

            if (Request::query('format') === 'csv') {
                $this->exportReportCsv($pdo, $scope, $from, $to);
            }
            if (Request::query('format') === 'purchase_csv') {
                if (!in_array($scope['role'], ['owner', 'manager'], true)) {
                    Response::send(403, false, 'Ekspor pembelian hanya tersedia untuk pemilik atau manajer.');
                }
                $this->exportPurchaseCsv($pdo, $scope, $from, $to);
            }

            $summary = $this->one($pdo,
                'SELECT COUNT(*) AS transactions,
                        COALESCE(SUM(t.subtotal), 0) AS subtotal,
                        COALESCE(SUM(t.discount_amount), 0) AS discounts,
                        COALESCE(SUM(t.tax_amount), 0) AS tax,
                        COALESCE(SUM(t.service_charge), 0) AS service_charge,
                        COALESCE(SUM(t.paid_total), 0) AS tender_received,
                        COALESCE(SUM(t.change_amount), 0) AS change_given,
                        COALESCE(SUM(t.grand_total), 0) AS gross_sales
                   FROM transactions t
                  WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at <= ?
                    AND t.status IN (\'paid\', \'refunded\')',
                $params) ?? [];
            $periodRefunds = $this->one($pdo,
                'SELECT COALESCE(SUM(refund_amount), 0) AS refunds
                   FROM transaction_returns
                  WHERE tenant_id = ? AND branch_id = ? AND returned_at >= ? AND returned_at <= ?',
                $params);
            $summary['refunds'] = $periodRefunds['refunds'] ?? 0;
            $summary['net_sales'] = (float) ($summary['gross_sales'] ?? 0) - (float) $summary['refunds'];
            $profitData = $this->reportProfitData($pdo, $scope, $from, $to);
            $profit = ReportProfitCalculator::calculate(
                $profitData['transactions'],
                $profitData['items'],
                $profitData['returns'],
                $from,
                $to
            );
            $summary['net_revenue'] = $profit['net_revenue_cents'] / 100;
            $periodDays = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
            $previousFrom = (new DateTimeImmutable($from))->modify('-' . $periodDays . ' days')->format('Y-m-d');
            $previousTo = (new DateTimeImmutable($from))->modify('-1 day')->format('Y-m-d');
            $previous = $this->one($pdo,
                'SELECT COUNT(*) AS transactions, COALESCE(SUM(grand_total), 0) AS gross_sales,
                        COALESCE(SUM(subtotal), 0) AS subtotal,
                        COALESCE(SUM(discount_amount), 0) AS discounts,
                        COALESCE(SUM(tax_amount), 0) AS tax
                   FROM transactions
                  WHERE tenant_id = ? AND branch_id = ? AND created_at >= ? AND created_at <= ?
                    AND status IN (\'paid\', \'refunded\')',
                [$scope['tenant_id'], $scope['branch_id'], $previousFrom . ' 00:00:00', $previousTo . ' 23:59:59']) ?? [];
            $previousRefunds = $this->one($pdo,
                'SELECT COALESCE(SUM(refund_amount), 0) AS refunds
                   FROM transaction_returns
                  WHERE tenant_id = ? AND branch_id = ? AND returned_at >= ? AND returned_at <= ?',
                [$scope['tenant_id'], $scope['branch_id'], $previousFrom . ' 00:00:00', $previousTo . ' 23:59:59']);
            $previous['net_sales'] = (float) ($previous['gross_sales'] ?? 0) - (float) ($previousRefunds['refunds'] ?? 0);
            $previousProfitData = $this->reportProfitData($pdo, $scope, $previousFrom, $previousTo);
            $previousProfit = ReportProfitCalculator::calculate(
                $previousProfitData['transactions'],
                $previousProfitData['items'],
                $previousProfitData['returns'],
                $previousFrom,
                $previousTo
            );
            $previous['net_revenue'] = $previousProfit['net_revenue_cents'] / 100;
            $summary['previous_period'] = $previous;
            $taxByDay = $this->all($pdo,
                'SELECT DATE(created_at) AS day, COUNT(*) AS transactions,
                        SUM(subtotal) AS subtotal, SUM(discount_amount) AS discounts,
                        SUM(service_charge) AS service_charge, SUM(tax_amount) AS tax,
                        SUM(grand_total) AS gross_sales
                   FROM transactions
                  WHERE tenant_id = ? AND branch_id = ? AND created_at >= ? AND created_at <= ?
                    AND status IN (\'paid\', \'refunded\')
                  GROUP BY DATE(created_at) ORDER BY day DESC LIMIT 366',
                $params);
            $purchaseSummary = $this->one($pdo,
                'SELECT
                    SUM(status = \'received\') AS received_orders,
                    COALESCE(SUM(CASE WHEN status = \'received\' THEN total_amount ELSE 0 END), 0) AS received_total,
                    SUM(status = \'ordered\') AS pending_orders,
                    COALESCE(SUM(CASE WHEN status = \'ordered\' THEN total_amount ELSE 0 END), 0) AS pending_total,
                    SUM(status = \'cancelled\') AS cancelled_orders
                   FROM purchase_orders
                  WHERE tenant_id = ? AND branch_id = ? AND ordered_at >= ? AND ordered_at <= ?',
                $params) ?? [];
            $receivedPurchases = $this->one($pdo,
                'SELECT COUNT(*) AS received_orders, COALESCE(SUM(total_amount), 0) AS received_total
                   FROM purchase_orders
                  WHERE tenant_id = ? AND branch_id = ? AND status = \'received\'
                    AND received_at >= ? AND received_at <= ?',
                $params) ?? [];
            $purchaseSummary['received_in_period_orders'] = $receivedPurchases['received_orders'] ?? 0;
            $purchaseSummary['received_in_period_total'] = $receivedPurchases['received_total'] ?? 0;
            $supplierPayableSummary = [];
            $supplierAging = [];
            $supplierPaymentsPeriod = [];
            $supplierReturnsPeriod = [];
            $receivableSummary = [];
            $receivableAging = [];
            if ($canViewProfit) {
                $supplierPayableSummary = $this->one($pdo,
                    'SELECT COALESCE(SUM(GREATEST(0, billed_amount - paid_amount - credited_amount)), 0) AS outstanding,
                            COALESCE(SUM(CASE WHEN due_at < CURDATE()
                                THEN GREATEST(0, billed_amount - paid_amount - credited_amount) ELSE 0 END), 0) AS overdue,
                            COALESCE(SUM(GREATEST(0, paid_amount + credited_amount - billed_amount)), 0) AS available_credit
                       FROM supplier_payables WHERE tenant_id = ? AND branch_id = ?',
                    [$scope['tenant_id'], $scope['branch_id']]) ?? [];
                $supplierAging = $this->all($pdo,
                    'SELECT s.name AS supplier_name,
                            SUM(GREATEST(0, sp.billed_amount - sp.paid_amount - sp.credited_amount)) AS outstanding,
                            SUM(CASE WHEN sp.due_at IS NULL OR sp.due_at >= CURDATE()
                                THEN GREATEST(0, sp.billed_amount - sp.paid_amount - sp.credited_amount) ELSE 0 END) AS not_due,
                            SUM(CASE WHEN sp.due_at < CURDATE() AND sp.due_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                THEN GREATEST(0, sp.billed_amount - sp.paid_amount - sp.credited_amount) ELSE 0 END) AS days_1_30,
                            SUM(CASE WHEN sp.due_at < DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND sp.due_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                                THEN GREATEST(0, sp.billed_amount - sp.paid_amount - sp.credited_amount) ELSE 0 END) AS days_31_60,
                            SUM(CASE WHEN sp.due_at < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND sp.due_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                THEN GREATEST(0, sp.billed_amount - sp.paid_amount - sp.credited_amount) ELSE 0 END) AS days_61_90,
                            SUM(CASE WHEN sp.due_at < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                                THEN GREATEST(0, sp.billed_amount - sp.paid_amount - sp.credited_amount) ELSE 0 END) AS days_over_90
                       FROM supplier_payables sp
                       JOIN suppliers s ON s.id = sp.supplier_id AND s.tenant_id = sp.tenant_id
                      WHERE sp.tenant_id = ? AND sp.branch_id = ?
                      GROUP BY s.id, s.name HAVING outstanding > 0
                      ORDER BY outstanding DESC LIMIT 100',
                    [$scope['tenant_id'], $scope['branch_id']]);
                $supplierPaymentsPeriod = $this->all($pdo,
                    'SELECT SUM(amount) AS amount, payment_method AS method, COUNT(*) AS payments
                       FROM supplier_payments
                      WHERE tenant_id = ? AND branch_id = ? AND paid_at >= ? AND paid_at <= ?
                      GROUP BY payment_method ORDER BY amount DESC',
                    [$scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59']);
                $supplierReturnsPeriod = $this->one($pdo,
                    'SELECT COUNT(*) AS returns_count, COALESCE(SUM(total_amount), 0) AS return_value
                       FROM purchase_returns
                      WHERE tenant_id = ? AND branch_id = ? AND returned_at >= ? AND returned_at <= ?',
                    [$scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59']) ?? [];
                $receivableBalance = 'GREATEST(0, t.grand_total - COALESCE(r.return_value, 0) -
                    (t.paid_total - t.change_amount - COALESCE(r.settled_refund, 0)))';
                $receivableJoin = 'LEFT JOIN (
                    SELECT tenant_id, transaction_id, SUM(refund_amount) AS return_value,
                           SUM(COALESCE(settled_refund_amount, refund_amount)) AS settled_refund
                      FROM transaction_returns WHERE tenant_id = ' . (int) $scope['tenant_id'] . '
                     GROUP BY tenant_id, transaction_id
                ) r ON r.tenant_id = t.tenant_id AND r.transaction_id = t.id';
                $receivableFilter = 't.tenant_id = ? AND t.branch_id = ? AND t.status IN (\'paid\', \'refunded\')
                    AND t.payment_status IN (\'unpaid\', \'partial\')';
                $receivableSummary = $this->one($pdo,
                    'SELECT COALESCE(SUM(CASE WHEN ' . $receivableBalance . ' > 0 THEN 1 ELSE 0 END), 0) AS invoices,
                            COALESCE(SUM(' . $receivableBalance . '), 0) AS outstanding,
                            COALESCE(SUM(CASE WHEN t.due_at < NOW() THEN ' . $receivableBalance . ' ELSE 0 END), 0) AS overdue
                       FROM transactions t ' . $receivableJoin . '
                      WHERE ' . $receivableFilter,
                    [$scope['tenant_id'], $scope['branch_id']]) ?? [];
                $receivableAging = $this->all($pdo,
                    'SELECT c.name AS customer_name,
                            SUM(' . $receivableBalance . ') AS outstanding,
                            SUM(CASE WHEN t.due_at IS NULL OR DATE(t.due_at) >= CURDATE()
                                THEN ' . $receivableBalance . ' ELSE 0 END) AS not_due,
                            SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.due_at)) BETWEEN 1 AND 30
                                THEN ' . $receivableBalance . ' ELSE 0 END) AS days_1_30,
                            SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.due_at)) BETWEEN 31 AND 60
                                THEN ' . $receivableBalance . ' ELSE 0 END) AS days_31_60,
                            SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.due_at)) BETWEEN 61 AND 90
                                THEN ' . $receivableBalance . ' ELSE 0 END) AS days_61_90,
                            SUM(CASE WHEN DATEDIFF(CURDATE(), DATE(t.due_at)) > 90
                                THEN ' . $receivableBalance . ' ELSE 0 END) AS days_over_90
                       FROM transactions t ' . $receivableJoin . '
                       JOIN customers c ON c.id = t.customer_id AND c.tenant_id = t.tenant_id
                      WHERE ' . $receivableFilter . '
                      GROUP BY c.id, c.name HAVING outstanding > 0
                      ORDER BY outstanding DESC LIMIT 100',
                    [$scope['tenant_id'], $scope['branch_id']]);
            }
            $purchaseBySupplier = $this->all($pdo,
                'SELECT s.name AS supplier_name, COUNT(*) AS orders_count,
                        SUM(po.total_amount) AS received_total
                   FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id AND s.tenant_id = po.tenant_id
                  WHERE po.tenant_id = ? AND po.branch_id = ? AND po.status = \'received\'
                    AND po.received_at >= ? AND po.received_at <= ?
                  GROUP BY s.id, s.name ORDER BY received_total DESC LIMIT 100',
                $params);
            $purchaseByProduct = $this->all($pdo,
                'SELECT p.name AS product_name, v.name AS variant_name, v.sku,
                        SUM(poi.qty_received) AS qty_received,
                        SUM(poi.qty_received * poi.unit_cost) AS received_cost
                   FROM purchase_orders po
                   JOIN purchase_order_items poi ON poi.purchase_order_id = po.id AND poi.tenant_id = po.tenant_id
                   JOIN product_variants v ON v.id = poi.variant_id AND v.tenant_id = po.tenant_id
                   JOIN products p ON p.id = v.product_id AND p.tenant_id = po.tenant_id
                  WHERE po.tenant_id = ? AND po.branch_id = ? AND po.status = \'received\'
                    AND po.received_at >= ? AND po.received_at <= ?
                  GROUP BY p.id, p.name, v.id, v.name, v.sku
                  ORDER BY received_cost DESC LIMIT 100',
                $params);
            $purchaseByDay = $this->all($pdo,
                'SELECT DATE(po.received_at) AS day, COUNT(*) AS orders_count,
                        SUM(po.total_amount) AS received_total
                   FROM purchase_orders po
                  WHERE po.tenant_id = ? AND po.branch_id = ? AND po.status = \'received\'
                    AND po.received_at >= ? AND po.received_at <= ?
                  GROUP BY DATE(po.received_at) ORDER BY day DESC LIMIT 366',
                $params);
            $purchases = $this->all($pdo,
                'SELECT po.order_no, s.name AS supplier_name, po.status, po.total_amount, po.notes,
                        po.ordered_at, po.received_at
                   FROM purchase_orders po JOIN suppliers s ON s.id = po.supplier_id AND s.tenant_id = po.tenant_id
                  WHERE po.tenant_id = ? AND po.branch_id = ? AND po.ordered_at >= ? AND po.ordered_at <= ?
                  ORDER BY po.ordered_at DESC LIMIT 200',
                $params);
            $shiftSummary = $this->one($pdo,
                'SELECT COUNT(*) AS shifts, COALESCE(SUM(opening_cash), 0) AS opening_cash,
                        COALESCE(SUM(expected_cash), 0) AS expected_cash,
                        COALESCE(SUM(counted_cash), 0) AS counted_cash,
                        COALESCE(SUM(cash_difference), 0) AS cash_difference,
                        SUM(status = \'open\') AS open_shifts
                   FROM cashier_shifts
                  WHERE tenant_id = ? AND branch_id = ? AND opened_at >= ? AND opened_at <= ?',
                $params) ?? [];
            $stockValue = $this->one($pdo,
                'SELECT COALESCE(SUM(sl.qty * v.cost_price), 0) AS inventory_cost_value,
                        COALESCE(SUM(sl.qty), 0) AS inventory_units,
                        COUNT(DISTINCT CASE WHEN sl.qty <= p.min_stock THEN v.id END) AS low_stock_variants
                   FROM stock_levels sl
                   JOIN product_variants v ON v.id = sl.variant_id AND v.tenant_id = sl.tenant_id
                   JOIN products p ON p.id = v.product_id AND p.tenant_id = sl.tenant_id
                  WHERE sl.tenant_id = ? AND sl.branch_id = ? AND p.deleted_at IS NULL',
                [$scope['tenant_id'], $scope['branch_id']]) ?? [];
            $expenseSummary = $this->one($pdo,
                'SELECT COALESCE(SUM(amount), 0) AS expenses
                   FROM operating_expenses
                  WHERE tenant_id = ? AND branch_id = ? AND expense_date >= ? AND expense_date <= ?',
                [$scope['tenant_id'], $scope['branch_id'], $from, $to]);
            $summary['expenses'] = $expenseSummary['expenses'] ?? 0;
            $expensesByCategory = $this->all($pdo,
                'SELECT category, SUM(amount) AS amount, COUNT(*) AS entries
                   FROM operating_expenses
                  WHERE tenant_id = ? AND branch_id = ? AND expense_date >= ? AND expense_date <= ?
                  GROUP BY category ORDER BY amount DESC',
                [$scope['tenant_id'], $scope['branch_id'], $from, $to]);
            $payments = $this->all($pdo,
                'SELECT tp.method, SUM(tp.amount) AS amount, COUNT(*) AS payments
                   FROM transaction_payments tp
                   JOIN transactions t ON t.id = tp.transaction_id
                  WHERE tp.tenant_id = ? AND t.branch_id = ? AND tp.paid_at >= ? AND tp.paid_at <= ?
                    AND t.status IN (\'paid\', \'refunded\')
                  GROUP BY tp.method ORDER BY amount DESC',
                [$scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59']);
            $refundsByMethod = $this->all($pdo,
                'SELECT refund_method AS method,
                        SUM(COALESCE(settled_refund_amount, refund_amount)) AS amount,
                        SUM(refund_amount) AS return_value, COUNT(*) AS returns_count
                   FROM transaction_returns
                  WHERE tenant_id = ? AND branch_id = ? AND returned_at >= ? AND returned_at <= ?
                  GROUP BY refund_method ORDER BY amount DESC',
                [$scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59']);
            $daily = $this->all($pdo,
                'SELECT activity.day, SUM(activity.transactions) AS transactions,
                        SUM(activity.gross_sales) AS gross_sales, SUM(activity.refunds) AS refunds,
                        SUM(activity.gross_sales) - SUM(activity.refunds) AS net_sales
                   FROM (
                     SELECT DATE(t.created_at) AS day, COUNT(*) AS transactions,
                            SUM(t.grand_total) AS gross_sales, 0 AS refunds
                       FROM transactions t
                      WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at <= ?
                        AND t.status IN (\'paid\', \'refunded\')
                      GROUP BY DATE(t.created_at)
                     UNION ALL
                     SELECT DATE(tr.returned_at) AS day, 0 AS transactions,
                            0 AS gross_sales, SUM(tr.refund_amount) AS refunds
                       FROM transaction_returns tr
                      WHERE tr.tenant_id = ? AND tr.branch_id = ? AND tr.returned_at >= ? AND tr.returned_at <= ?
                      GROUP BY DATE(tr.returned_at)
                   ) activity
                  GROUP BY activity.day ORDER BY activity.day DESC LIMIT 366',
                array_merge($params, $params));
            $byOrderType = $this->all($pdo,
                'SELECT t.order_type, COUNT(*) AS transactions, SUM(t.grand_total) AS sales,
                        AVG(t.grand_total) AS average_ticket
                   FROM transactions t
                  WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at <= ?
                    AND t.status IN (\'paid\', \'refunded\')
                  GROUP BY t.order_type ORDER BY sales DESC',
                $params);
            $byCashier = $this->all($pdo,
                'SELECT u.id AS cashier_id, u.name AS cashier_name, COUNT(*) AS transactions,
                        SUM(t.grand_total) AS sales, AVG(t.grand_total) AS average_ticket
                   FROM transactions t JOIN users u ON u.id = t.cashier_id
                  WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at <= ?
                    AND t.status IN (\'paid\', \'refunded\')
                  GROUP BY u.id, u.name ORDER BY sales DESC',
                $params);
            $byHour = $this->all($pdo,
                'SELECT HOUR(t.created_at) AS hour, COUNT(*) AS transactions,
                        SUM(t.grand_total) AS sales
                   FROM transactions t
                  WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at <= ?
                    AND t.status IN (\'paid\', \'refunded\')
                  GROUP BY HOUR(t.created_at) ORDER BY hour',
                $params);
            $topProducts = array_map(static fn(array $row): array => [
                'item_name' => $row['item_name'],
                'item_type' => $row['item_type'],
                'quantity_sold' => $row['quantity_sold'],
                'quantity_returned' => $row['quantity_returned'],
                'sales' => $row['sales_cents'] / 100,
                'cost' => $row['cost_cents'] / 100,
                'gross_profit' => $row['gross_profit_cents'] / 100,
            ], array_slice($profit['top_products'], 0, 100));
            $summary['gross_profit'] = $profit['gross_profit_cents'] / 100;
            $summary['net_profit'] = (float) $summary['gross_profit'] - (float) $summary['expenses'];
            if (!$canViewProfit) {
                unset($summary['gross_profit'], $summary['net_profit'], $summary['expenses']);
                $expensesByCategory = [];
                $purchaseSummary = [];
                $purchaseBySupplier = [];
                $purchaseByProduct = [];
                $purchaseByDay = [];
                $purchases = [];
                $shiftSummary = [];
                $stockValue = [];
            }
            $lowStock = $this->all($pdo,
                'SELECT p.name AS product_name, v.name AS variant_name, v.sku, p.unit,
                        p.min_stock, COALESCE(sl.qty, 0) AS stock_qty
                   FROM products p
                   JOIN product_variants v ON v.product_id = p.id AND v.tenant_id = p.tenant_id
                        AND v.is_default = 1 AND v.is_active = 1 AND v.deleted_at IS NULL
                   LEFT JOIN stock_levels sl ON sl.variant_id = v.id AND sl.branch_id = ? AND sl.tenant_id = p.tenant_id
                  WHERE p.tenant_id = ? AND p.deleted_at IS NULL AND p.is_active = 1 AND p.track_stock = 1
                    AND COALESCE(sl.qty, 0) <= p.min_stock
                  ORDER BY (p.min_stock - COALESCE(sl.qty, 0)) DESC, p.name LIMIT 100',
                [$scope['branch_id'], $scope['tenant_id']]);
            $stockMovements = $this->all($pdo,
                'SELECT movement_type, COUNT(*) AS movements, SUM(qty_change) AS quantity_change
                   FROM stock_movements
                  WHERE tenant_id = ? AND branch_id = ? AND created_at >= ? AND created_at <= ?
                  GROUP BY movement_type ORDER BY movements DESC',
                $params);
            $returns = $this->all($pdo,
                'SELECT tr.id, t.invoice_no, u.name AS cashier_name, tr.refund_method,
                        tr.refund_amount, COALESCE(tr.settled_refund_amount, tr.refund_amount) AS settled_refund_amount,
                        tr.note, tr.returned_at
                   FROM transaction_returns tr
                   JOIN transactions t ON t.id = tr.transaction_id AND t.tenant_id = tr.tenant_id
                   JOIN users u ON u.id = tr.returned_by
                  WHERE tr.tenant_id = ? AND tr.branch_id = ? AND tr.returned_at >= ? AND tr.returned_at <= ?
                  ORDER BY tr.returned_at DESC LIMIT 200',
                $params);
            $branchSummary = [];
            if ($scope['role'] === 'owner') {
                $branchSummary = $this->all($pdo,
                    'SELECT b.id AS branch_id, b.name AS branch_name,
                            COALESCE(s.gross_sales, 0) AS gross_sales,
                            COALESCE(r.refunds, 0) AS refunds,
                            COALESCE(s.gross_sales, 0) - COALESCE(r.refunds, 0) AS net_sales,
                            COALESCE(p.received_purchases, 0) AS received_purchases,
                            COALESCE(e.expenses, 0) AS expenses,
                            COALESCE(a.outstanding, 0) AS supplier_payables,
                            COALESCE(ar.outstanding, 0) AS customer_receivables
                       FROM branches b
                       LEFT JOIN (
                           SELECT branch_id, SUM(grand_total) AS gross_sales
                             FROM transactions
                            WHERE tenant_id = ? AND created_at >= ? AND created_at <= ?
                              AND status IN (\'paid\', \'refunded\')
                            GROUP BY branch_id
                       ) s ON s.branch_id = b.id
                       LEFT JOIN (
                           SELECT branch_id, SUM(refund_amount) AS refunds
                             FROM transaction_returns
                            WHERE tenant_id = ? AND returned_at >= ? AND returned_at <= ?
                            GROUP BY branch_id
                       ) r ON r.branch_id = b.id
                       LEFT JOIN (
                           SELECT branch_id, SUM(total_amount) AS received_purchases
                             FROM purchase_orders
                            WHERE tenant_id = ? AND status = \'received\' AND received_at >= ? AND received_at <= ?
                            GROUP BY branch_id
                       ) p ON p.branch_id = b.id
                       LEFT JOIN (
                           SELECT branch_id, SUM(amount) AS expenses
                             FROM operating_expenses
                            WHERE tenant_id = ? AND expense_date >= ? AND expense_date <= ?
                            GROUP BY branch_id
                       ) e ON e.branch_id = b.id
                       LEFT JOIN (
                           SELECT branch_id,
                                  SUM(GREATEST(0, billed_amount - paid_amount - credited_amount)) AS outstanding
                             FROM supplier_payables
                            WHERE tenant_id = ?
                            GROUP BY branch_id
                       ) a ON a.branch_id = b.id
                       LEFT JOIN (
                           SELECT t.branch_id, SUM(' . $receivableBalance . ') AS outstanding
                             FROM transactions t ' . $receivableJoin . '
                            WHERE t.tenant_id = ? AND t.status IN (\'paid\', \'refunded\')
                              AND t.payment_status IN (\'unpaid\', \'partial\')
                            GROUP BY t.branch_id
                       ) ar ON ar.branch_id = b.id
                      WHERE b.tenant_id = ? AND b.is_active = 1 AND b.deleted_at IS NULL
                      ORDER BY b.name',
                    [
                        $scope['tenant_id'], $from . ' 00:00:00', $to . ' 23:59:59',
                        $scope['tenant_id'], $from . ' 00:00:00', $to . ' 23:59:59',
                        $scope['tenant_id'], $from . ' 00:00:00', $to . ' 23:59:59',
                        $scope['tenant_id'], $from, $to,
                        $scope['tenant_id'], $scope['tenant_id'], $scope['tenant_id'],
                    ]);
            }
            $auditActivity = [];
            if ($scope['role'] === 'owner') {
                $auditRows = $this->all($pdo,
                    'SELECT al.action_code, al.entity_type, al.entity_id, al.details, al.created_at,
                            u.name AS user_name, b.name AS branch_name
                       FROM audit_logs al
                       JOIN users u ON u.id = al.user_id AND u.tenant_id = al.tenant_id
                       JOIN branches b ON b.id = al.branch_id AND b.tenant_id = al.tenant_id
                      WHERE al.tenant_id = ? AND al.created_at >= ? AND al.created_at <= ?
                      ORDER BY al.created_at DESC, al.id DESC LIMIT 200',
                    [$scope['tenant_id'], $from . ' 00:00:00', $to . ' 23:59:59']);
                $auditActivity = array_map(static function (array $row): array {
                    $details = json_decode((string) ($row['details'] ?? ''), true);
                    $row['details'] = is_array($details) ? $details : [];
                    return $row;
                }, $auditRows);
            }
            Response::send(200, true, 'OK', [
                'from' => $from,
                'to' => $to,
                'summary' => $summary,
                'tax_by_day' => $taxByDay,
                'expenses_by_category' => $expensesByCategory,
                'purchase_summary' => $purchaseSummary,
                'supplier_payable_summary' => $supplierPayableSummary,
                'supplier_aging' => $supplierAging,
                'supplier_payments_period' => $supplierPaymentsPeriod,
                'supplier_returns_period' => $supplierReturnsPeriod,
                'receivable_summary' => $receivableSummary,
                'receivable_aging' => $receivableAging,
                'purchases_by_supplier' => $purchaseBySupplier,
                'purchases_by_product' => $purchaseByProduct,
                'purchases_by_day' => $purchaseByDay,
                'purchases' => $purchases,
                'shift_summary' => $shiftSummary,
                'stock_value' => $stockValue,
                'payments' => $payments,
                'refunds_by_method' => $refundsByMethod,
                'daily' => $daily,
                'by_order_type' => $byOrderType,
                'by_cashier' => $byCashier,
                'by_hour' => $byHour,
                'top_products' => $topProducts,
                'low_stock' => $lowStock,
                'stock_movements' => $stockMovements,
                'returns' => $returns,
                'branch_summary' => $branchSummary,
                'audit_activity' => $auditActivity,
            ]);
        } catch (Throwable $e) {
            error_log('[pos-report] gagal: ' . $e->getMessage());
            Response::send(500, false, 'Gagal memuat laporan penjualan.');
        }
    }

    /**
     * @return array{
     *   transactions:array<int,array{created_at:string,discount_cents:int,service_cents:int,tax_cents:int,tax_inclusive:bool}>,
     *   items:list<array{id:int,transaction_id:int,item_name:string,item_type:string,qty_milli:int,line_cents:int,item_discount_cents:int,cost_cents_per_unit:int}>,
     *   returns:list<array{id:int,item_id:int,qty_milli:int,returned_at:string,restocked:bool}>
     * }
     */
    private function reportProfitData(PDO $pdo, array $scope, string $from, string $to): array
    {
        $fromAt = $from . ' 00:00:00';
        $toAt = $to . ' 23:59:59';
        $transactionRows = $this->all($pdo,
            'SELECT t.id, t.created_at, t.discount_amount, t.service_charge, t.tax_amount, t.meta
               FROM transactions t
              WHERE t.tenant_id = ? AND t.branch_id = ?
                AND t.status IN (\'paid\', \'refunded\')
                AND (
                    (t.created_at >= ? AND t.created_at <= ?)
                    OR EXISTS (
                        SELECT 1 FROM transaction_returns tr
                         WHERE tr.transaction_id = t.id AND tr.tenant_id = t.tenant_id
                           AND tr.branch_id = t.branch_id
                           AND tr.returned_at >= ? AND tr.returned_at <= ?
                    )
                )',
            [$scope['tenant_id'], $scope['branch_id'], $fromAt, $toAt, $fromAt, $toAt]);

        $transactions = [];
        $transactionIds = [];
        foreach ($transactionRows as $row) {
            $id = (int) $row['id'];
            $meta = json_decode((string) ($row['meta'] ?? ''), true);
            $transactions[$id] = [
                'created_at' => (string) $row['created_at'],
                'discount_cents' => (int) round((float) $row['discount_amount'] * 100),
                'service_cents' => (int) round((float) $row['service_charge'] * 100),
                'tax_cents' => (int) round((float) $row['tax_amount'] * 100),
                'tax_inclusive' => is_array($meta) && ($meta['tax_mode'] ?? '') === 'inclusive',
            ];
            $transactionIds[] = $id;
        }

        $items = [];
        foreach (array_chunk($transactionIds, 500) as $ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $itemRows = $this->all($pdo,
                "SELECT ti.id, ti.transaction_id, ti.item_name, ti.item_type, ti.qty, ti.line_total,
                        ti.discount_amount, ti.cost_price
                   FROM transaction_items ti
                  WHERE ti.tenant_id = ? AND ti.transaction_id IN ($placeholders)
                  ORDER BY ti.transaction_id, ti.id",
                array_merge([$scope['tenant_id']], $ids));
            foreach ($itemRows as $item) {
                $items[] = [
                    'id' => (int) $item['id'],
                    'transaction_id' => (int) $item['transaction_id'],
                    'item_name' => (string) $item['item_name'],
                    'item_type' => (string) $item['item_type'],
                    'qty_milli' => (int) round((float) $item['qty'] * 1000),
                    'line_cents' => (int) round((float) $item['line_total'] * 100),
                    'item_discount_cents' => (int) round((float) $item['discount_amount'] * 100),
                    'cost_cents_per_unit' => (int) round((float) $item['cost_price'] * 100),
                ];
            }
        }

        $periodReturnItems = $this->all($pdo,
            'SELECT DISTINCT tri.transaction_item_id
               FROM transaction_return_items tri
               JOIN transaction_returns tr ON tr.id = tri.return_id
              WHERE tr.tenant_id = ? AND tr.branch_id = ?
                AND tr.returned_at >= ? AND tr.returned_at <= ?',
            [$scope['tenant_id'], $scope['branch_id'], $fromAt, $toAt]);
        $returnItemIds = array_map(static fn(array $row): int => (int) $row['transaction_item_id'], $periodReturnItems);

        $returns = [];
        foreach (array_chunk($returnItemIds, 500) as $ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $returnRows = $this->all($pdo,
                "SELECT tri.id, tri.transaction_item_id, tri.qty, tr.returned_at,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM stock_movements sm
                             WHERE sm.tenant_id = tr.tenant_id AND sm.branch_id = tr.branch_id
                               AND sm.reference_type = 'transaction_return' AND sm.reference_id = tr.id
                               AND sm.movement_type = 'return' AND sm.variant_id = ti.variant_id
                        ) THEN 1 ELSE 0 END AS restocked
                   FROM transaction_return_items tri
                   JOIN transaction_returns tr ON tr.id = tri.return_id
                   JOIN transaction_items ti ON ti.id = tri.transaction_item_id AND ti.tenant_id = tr.tenant_id
                  WHERE tr.tenant_id = ? AND tr.branch_id = ? AND tr.returned_at <= ?
                    AND tri.transaction_item_id IN ($placeholders)
                  ORDER BY tri.transaction_item_id, tr.returned_at, tr.id, tri.id",
                array_merge([$scope['tenant_id'], $scope['branch_id'], $toAt], $ids));
            foreach ($returnRows as $row) {
                $returns[] = [
                    'id' => (int) $row['id'],
                    'item_id' => (int) $row['transaction_item_id'],
                    'qty_milli' => (int) round((float) $row['qty'] * 1000),
                    'returned_at' => (string) $row['returned_at'],
                    'restocked' => (int) $row['restocked'] === 1,
                ];
            }
        }

        return ['transactions' => $transactions, 'items' => $items, 'returns' => $returns];
    }

    private function exportReportCsv(PDO $pdo, array $scope, string $from, string $to): void
    {
        $statement = $pdo->prepare(
            'SELECT t.created_at, t.invoice_no, u.name AS cashier_name, t.order_type, t.status,
                    t.subtotal, t.discount_amount, t.service_charge, t.tax_amount, t.grand_total,
                    t.paid_total, t.change_amount, 0 AS refunded,
                    t.grand_total AS net_sales,
                    COALESCE(p.payments, \'\') AS payment_methods
               FROM transactions t
               JOIN users u ON u.id = t.cashier_id
               LEFT JOIN (
                 SELECT transaction_id, GROUP_CONCAT(CONCAT(method, \': \', FORMAT(amount, 2)) SEPARATOR \'; \') AS payments
                   FROM transaction_payments
                  WHERE tenant_id = ? AND paid_at >= ? AND paid_at <= ?
                  GROUP BY transaction_id
               ) p ON p.transaction_id = t.id
              WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at <= ?
                AND t.status IN (\'paid\', \'refunded\')
              ORDER BY t.created_at, t.id'
        );
        $statement->execute([
            $scope['tenant_id'], $from . ' 00:00:00', $to . ' 23:59:59',
            $scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59',
        ]);
        $returnStatement = $pdo->prepare(
            'SELECT tr.returned_at, t.invoice_no, u.name AS cashier_name,
                    tr.refund_method, tr.refund_amount
               FROM transaction_returns tr
               JOIN transactions t ON t.id = tr.transaction_id AND t.tenant_id = tr.tenant_id
               JOIN users u ON u.id = tr.returned_by
              WHERE tr.tenant_id = ? AND tr.branch_id = ? AND tr.returned_at >= ? AND tr.returned_at <= ?
              ORDER BY tr.returned_at, tr.id'
        );
        $returnStatement->execute([
            $scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59',
        ]);

        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="laporan-transaksi-' . $from . '-sampai-' . $to . '.csv"');
            header('Cache-Control: no-store');
        }
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new \RuntimeException('Tidak dapat menyiapkan file laporan.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, [
            'Jenis catatan', 'Waktu', 'Invoice', 'Kasir', 'Jenis pesanan', 'Status', 'Subtotal', 'Diskon',
            'Biaya layanan', 'Pajak', 'Total', 'Uang diterima', 'Kembalian', 'Retur',
            'Dampak penjualan bersih', 'Rincian pembayaran',
        ]);
        while ($row = $statement->fetch(PDO::FETCH_NUM)) {
            foreach ([1, 2, 3, 4, 14] as $index) {
                $value = $row[$index] ?? null;
                if (is_string($value) && preg_match('/^[\s]*[=+\-@]/u', $value)) {
                    $row[$index] = "'" . $value;
                }
            }
            fputcsv($output, array_merge(['Penjualan'], $row));
        }
        while ($row = $returnStatement->fetch(PDO::FETCH_ASSOC)) {
            $invoice = (string) $row['invoice_no'];
            $cashier = (string) $row['cashier_name'];
            if (preg_match('/^[\s]*[=+\-@]/u', $invoice)) {
                $invoice = "'" . $invoice;
            }
            if (preg_match('/^[\s]*[=+\-@]/u', $cashier)) {
                $cashier = "'" . $cashier;
            }
            fputcsv($output, [
                'Retur', $row['returned_at'], $invoice, $cashier, '', 'Retur',
                '', '', '', '', '', '', '',
                $row['refund_amount'], '-' . $row['refund_amount'], $row['refund_method'],
            ]);
        }
        fclose($output);
        exit;
    }

    private function exportPurchaseCsv(PDO $pdo, array $scope, string $from, string $to): void
    {
        $statement = $pdo->prepare(
            'SELECT po.order_no, s.name AS supplier_name, po.status, po.ordered_at,
                    po.received_at, po.total_amount, po.notes
               FROM purchase_orders po
               JOIN suppliers s ON s.id = po.supplier_id AND s.tenant_id = po.tenant_id
              WHERE po.tenant_id = ? AND po.branch_id = ? AND po.ordered_at >= ? AND po.ordered_at <= ?
              ORDER BY po.ordered_at, po.id'
        );
        $statement->execute([
            $scope['tenant_id'], $scope['branch_id'], $from . ' 00:00:00', $to . ' 23:59:59',
        ]);
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="laporan-pembelian-' . $from . '-sampai-' . $to . '.csv"');
            header('Cache-Control: no-store');
        }
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new \RuntimeException('Tidak dapat menyiapkan file laporan pembelian.');
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['No. pesanan', 'Pemasok', 'Status', 'Tanggal pesan', 'Tanggal terima', 'Nilai pesanan', 'Catatan']);
        while ($row = $statement->fetch(PDO::FETCH_NUM)) {
            foreach ([0, 1, 2, 6] as $index) {
                $value = $row[$index] ?? null;
                if (is_string($value) && preg_match('/^[\s]*[=+\-@]/u', $value)) {
                    $row[$index] = "'" . $value;
                }
            }
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    public function collectReceivable(): void
    {
        $pdo = Database::pdo();
        try {
            OperationsController::ensureSchema($pdo);
            $scope = $this->scope($pdo);
            if (Request::method() !== 'POST' || !Csrf::verify(Request::header('X-CSRF-Token'))) {
                Response::send(403, false, 'Permintaan tidak valid atau sesi formulir kedaluwarsa.');
            }
            $data = Request::json();
            if (!is_array($data)) {
                Response::send(400, false, 'Body harus berupa JSON yang valid.');
            }
            $transactionId = $this->positiveInt($data['transaction_id'] ?? null);
            $amountCents = $this->moneyCents($data['amount'] ?? null);
            $method = $data['method'] ?? null;
            $reference = $data['reference_no'] ?? '';
            if ($transactionId === null || $amountCents === null || $amountCents <= 0
                || !is_string($method) || !in_array($method, ['cash', 'qris', 'transfer', 'debit', 'credit', 'other'], true)
                || !is_string($reference) || strlen($reference) > 80) {
                Response::send(422, false, 'Data pelunasan piutang tidak valid.');
            }

            $pdo->beginTransaction();
            $transaction = $this->one($pdo,
                'SELECT id, invoice_no, grand_total, paid_total, change_amount, payment_status
                   FROM transactions
                  WHERE id = ? AND tenant_id = ? AND branch_id = ? AND status = \'paid\' FOR UPDATE',
                [$transactionId, $scope['tenant_id'], $scope['branch_id']]);
            if ($transaction === null || $transaction['payment_status'] === 'paid') {
                throw new PosReturnException('Transaksi tidak ditemukan atau sudah lunas.');
            }
            $refund = $this->one($pdo,
                'SELECT COALESCE(SUM(refund_amount), 0) AS total,
                        COALESCE(SUM(COALESCE(settled_refund_amount, refund_amount)), 0) AS settled
                   FROM transaction_returns
                  WHERE tenant_id = ? AND branch_id = ? AND transaction_id = ?',
                [$scope['tenant_id'], $scope['branch_id'], $transactionId]);
            $netDueCents = max(0,
                (int) round(((float) $transaction['grand_total'] - (float) ($refund['total'] ?? 0)) * 100)
                - (int) round(((float) $transaction['paid_total'] - (float) $transaction['change_amount']
                    - (float) ($refund['settled'] ?? 0)) * 100)
            );
            if ($amountCents > $netDueCents) {
                throw new PosReturnException('Nominal pelunasan melebihi sisa piutang.');
            }

            $shiftId = null;
            if ($method === 'cash') {
                $shift = $this->one($pdo,
                    'SELECT id FROM cashier_shifts
                      WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
                      ORDER BY id DESC LIMIT 1 FOR UPDATE',
                    [$scope['tenant_id'], $scope['branch_id']]);
                if ($shift === null) {
                    throw new PosReturnException('Buka shift kas sebelum menerima pelunasan tunai.');
                }
                $shiftId = (int) $shift['id'];
            }
            $pdo->prepare(
                'INSERT INTO transaction_payments
                 (tenant_id, transaction_id, method, amount, reference_no, cashier_shift_id, paid_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            )->execute([
                $scope['tenant_id'], $transactionId, $method, number_format($amountCents / 100, 2, '.', ''),
                $reference ?: null, $shiftId,
            ]);
            $newPaid = (float) $transaction['paid_total'] + $amountCents / 100;
            $newStatus = $netDueCents === $amountCents ? 'paid' : 'partial';
            $pdo->prepare(
                'UPDATE transactions
                    SET paid_total = ?, payment_status = ?, paid_at = CASE WHEN ? = \'paid\' THEN NOW() ELSE paid_at END
                  WHERE id = ? AND tenant_id = ? AND branch_id = ?'
            )->execute([
                number_format($newPaid, 2, '.', ''), $newStatus, $newStatus,
                $transactionId, $scope['tenant_id'], $scope['branch_id'],
            ]);
            AuditLogger::record($pdo, $scope['tenant_id'], $scope['branch_id'], $scope['user_id'],
                'receivable.payment_received', 'transaction', $transactionId,
                ['amount' => number_format($amountCents / 100, 2, '.', ''), 'payment_method' => $method]);
            $pdo->commit();
            Response::send(200, true, 'Pelunasan piutang berhasil dicatat.', [
                'invoice_no' => $transaction['invoice_no'],
                'payment_status' => $newStatus,
                'remaining' => number_format(($netDueCents - $amountCents) / 100, 2, '.', ''),
            ]);
        } catch (PosReturnException $e) {
            $this->rollback($pdo);
            Response::send(422, false, $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[receivable-payment] gagal: ' . $e->getMessage());
            Response::send(500, false, 'Pelunasan piutang gagal diproses. Tidak ada perubahan yang disimpan.');
        }
    }

    public function returnItems(): void
    {
        $pdo = Database::pdo();
        try {
            $scope = $this->scope($pdo);
            if (!in_array($scope['role'], ['owner', 'manager'], true)) {
                Response::send(403, false, 'Retur hanya dapat diproses pemilik atau manajer.');
            }
            OperationsController::ensureSchema($pdo);
            if (Request::method() !== 'POST' || !Csrf::verify(Request::header('X-CSRF-Token'))) {
                Response::send(403, false, 'Permintaan tidak valid atau sesi formulir kedaluwarsa.');
            }
            $data = Request::json();
            if (!is_array($data)) {
                Response::send(400, false, 'Body harus berupa JSON yang valid.');
            }
            $transactionId = $this->positiveInt($data['transaction_id'] ?? null);
            $method = $data['refund_method'] ?? 'cash';
            $items = $data['items'] ?? null;
            if ($transactionId === null || !is_string($method) || !in_array($method, self::RETURN_METHODS, true)
                || !is_array($items) || $items === [] || count($items) > 100) {
                Response::send(422, false, 'Data retur tidak valid.');
            }
            $normalized = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    Response::send(422, false, 'Item retur tidak valid.');
                }
                $itemId = $this->positiveInt($item['item_id'] ?? null);
                $qtyMilli = $this->qtyMilli($item['qty'] ?? null);
                if ($itemId === null || $qtyMilli === null || isset($normalized[$itemId])) {
                    Response::send(422, false, 'ID item atau jumlah retur tidak valid.');
                }
                $normalized[$itemId] = $qtyMilli;
            }
            $note = $data['note'] ?? '';
            if (!is_string($note) || strlen($note) > 255) {
                Response::send(422, false, 'Catatan retur maksimal 255 karakter.');
            }

            $this->ensureReturnTables($pdo);
            $pdo->beginTransaction();
            $transaction = $this->one($pdo,
                'SELECT id, invoice_no, status, payment_status, discount_amount, service_charge, tax_amount,
                        grand_total, paid_total, change_amount, meta
                   FROM transactions
                  WHERE id = ? AND tenant_id = ? AND branch_id = ? FOR UPDATE',
                [$transactionId, $scope['tenant_id'], $scope['branch_id']]);
            if ($transaction === null || !in_array($transaction['status'], ['paid', 'refunded'], true)) {
                throw new \RuntimeException('Transaksi tidak ditemukan atau tidak dapat diretur.');
            }

            $allItems = $this->all($pdo,
                'SELECT id, line_total, discount_amount FROM transaction_items
                  WHERE transaction_id = ? AND tenant_id = ? ORDER BY id',
                [$transactionId, $scope['tenant_id']]);
            $lineAmounts = [];
            foreach ($allItems as $line) {
                $lineAmounts[] = [
                    'id' => (int) $line['id'],
                    'line_cents' => (int) round((float) $line['line_total'] * 100),
                    'item_discount_cents' => (int) round((float) $line['discount_amount'] * 100),
                ];
            }
            $transactionMeta = json_decode((string) ($transaction['meta'] ?? ''), true);
            $taxInclusive = is_array($transactionMeta) && ($transactionMeta['tax_mode'] ?? '') === 'inclusive';
            $refundableAmounts = RefundCalculator::allocate(
                $lineAmounts,
                (int) round((float) $transaction['discount_amount'] * 100),
                (int) round((float) $transaction['service_charge'] * 100),
                (int) round((float) $transaction['tax_amount'] * 100),
                $taxInclusive
            );
            if (array_sum($refundableAmounts) !== (int) round((float) $transaction['grand_total'] * 100)) {
                throw new \RuntimeException('Nilai item dan total transaksi tidak konsisten.');
            }

            $refundCents = 0;
            $prepared = [];
            foreach ($normalized as $itemId => $requestedMilli) {
                $item = $this->one($pdo,
                    'SELECT ti.id, ti.qty, ti.line_total, ti.variant_id, ti.batch_id, ti.product_id,
                            COALESCE(p.track_stock, 0) AS track_stock,
                            COALESCE(p.track_batch, 0) AS track_batch
                       FROM transaction_items ti
                       LEFT JOIN products p ON p.id = ti.product_id
                      WHERE ti.id = ? AND ti.transaction_id = ? AND ti.tenant_id = ?',
                    [$itemId, $transactionId, $scope['tenant_id']]);
                if ($item === null) {
                    throw new PosReturnException('Item retur tidak termasuk dalam transaksi.');
                }
                $soldMilli = $this->qtyMilli($item['qty']);
                $returned = $this->one($pdo,
                    'SELECT COALESCE(SUM(qty), 0) AS qty, COALESCE(SUM(refund_amount), 0) AS amount
                       FROM transaction_return_items WHERE transaction_item_id = ?',
                    [$itemId]) ?? [];
                $returnedMilli = (int) round((float) ($returned['qty'] ?? 0) * 1000);
                $remainingMilli = $soldMilli - $returnedMilli;
                if ($requestedMilli > $remainingMilli) {
                    throw new PosReturnException('Jumlah retur melebihi sisa item yang dapat diretur.');
                }
                $alreadyCents = (int) round((float) ($returned['amount'] ?? 0) * 100);
                $lineRefundCents = $refundableAmounts[$itemId] ?? null;
                if ($lineRefundCents === null || $alreadyCents > $lineRefundCents) {
                    throw new \RuntimeException('Nilai retur sebelumnya melebihi nilai item yang dapat dikembalikan.');
                }
                $amountCents = RefundCalculator::forQuantity(
                    $lineRefundCents,
                    $soldMilli,
                    $returnedMilli,
                    $requestedMilli,
                    $alreadyCents
                );
                $refundCents += $amountCents;
                $prepared[] = [
                    'item_id' => $itemId,
                    'qty_milli' => $requestedMilli,
                    'refund_cents' => $amountCents,
                    'variant_id' => $item['variant_id'] !== null ? (int) $item['variant_id'] : null,
                    'batch_id' => $item['batch_id'] !== null ? (int) $item['batch_id'] : null,
                    'track_stock' => (int) $item['track_stock'] === 1 || (int) $item['track_batch'] === 1,
                ];
            }

            $priorReturns = $this->one($pdo,
                'SELECT COALESCE(SUM(refund_amount), 0) AS returned_value,
                        COALESCE(SUM(COALESCE(settled_refund_amount, refund_amount)), 0) AS settled_value
                   FROM transaction_returns
                  WHERE tenant_id = ? AND branch_id = ? AND transaction_id = ?',
                [$scope['tenant_id'], $scope['branch_id'], $transactionId]) ?? [];
            $netPaidCents = max(0, (int) round(((float) $transaction['paid_total'] - (float) $transaction['change_amount']
                - (float) ($priorReturns['settled_value'] ?? 0)) * 100));
            $dueBeforeReturnCents = max(0,
                (int) round(((float) $transaction['grand_total'] - (float) ($priorReturns['returned_value'] ?? 0)) * 100)
                - $netPaidCents
            );
            $creditAppliedCents = min($refundCents, $dueBeforeReturnCents);
            $settledRefundCents = $refundCents - $creditAppliedCents;

            $now = date('Y-m-d H:i:s');
            $shift = $this->one($pdo,
                'SELECT id FROM cashier_shifts
                  WHERE tenant_id = ? AND branch_id = ? AND status = \'open\'
                  ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$scope['tenant_id'], $scope['branch_id']]);
            if ($method === 'cash' && $settledRefundCents > 0 && $shift === null) {
                throw new PosReturnException('Buka shift kas sebelum mengembalikan uang tunai kepada pelanggan.');
            }
            $pdo->prepare(
                'INSERT INTO transaction_returns
                 (tenant_id, branch_id, transaction_id, cashier_shift_id, refund_method, refund_amount, settled_refund_amount, note, returned_by, returned_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $scope['tenant_id'], $scope['branch_id'], $transactionId, $shift !== null ? (int) $shift['id'] : null, $method,
                number_format($refundCents / 100, 2, '.', ''), number_format($settledRefundCents / 100, 2, '.', ''),
                trim($note) ?: null, $scope['user_id'], $now,
            ]);
            $returnId = (int) $pdo->lastInsertId();
            $insertItem = $pdo->prepare(
                'INSERT INTO transaction_return_items (return_id, transaction_item_id, qty, refund_amount)
                 VALUES (?, ?, ?, ?)'
            );
            foreach ($prepared as $item) {
                $qty = number_format($item['qty_milli'] / 1000, 3, '.', '');
                $insertItem->execute([
                    $returnId, $item['item_id'], $qty,
                    number_format($item['refund_cents'] / 100, 2, '.', ''),
                ]);
                if (!$item['track_stock'] || $item['variant_id'] === null) {
                    continue;
                }
                $batchId = $item['batch_id'];
                if ($batchId !== null) {
                    $batch = $this->one($pdo,
                        'SELECT id FROM product_batches WHERE id = ? AND tenant_id = ? AND branch_id = ? AND variant_id = ? FOR UPDATE',
                        [$batchId, $scope['tenant_id'], $scope['branch_id'], $item['variant_id']]);
                    if ($batch !== null) {
                        $pdo->prepare('UPDATE product_batches SET qty_remaining = qty_remaining + ? WHERE id = ?')
                            ->execute([$qty, $batchId]);
                    } else {
                        $batchId = null;
                    }
                }
                $pdo->prepare(
                    'INSERT INTO stock_levels (branch_id, variant_id, tenant_id, qty) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)'
                )->execute([$scope['branch_id'], $item['variant_id'], $scope['tenant_id'], $qty]);
                $stock = $this->one($pdo,
                    'SELECT qty FROM stock_levels WHERE branch_id = ? AND variant_id = ? AND tenant_id = ?',
                    [$scope['branch_id'], $item['variant_id'], $scope['tenant_id']]);
                $pdo->prepare(
                    'INSERT INTO stock_movements
                     (tenant_id, branch_id, variant_id, batch_id, movement_type, qty_change, qty_after, reference_type, reference_id, note, created_by)
                     VALUES (?, ?, ?, ?, \'return\', ?, ?, \'transaction_return\', ?, ?, ?)'
                )->execute([
                    $scope['tenant_id'], $scope['branch_id'], $item['variant_id'], $batchId, $qty,
                    (string) ($stock['qty'] ?? $qty), $returnId, (string) $transaction['invoice_no'], $scope['user_id'],
                ]);
            }

            $remaining = $this->one($pdo,
                'SELECT COUNT(*) AS remaining FROM (
                    SELECT ti.id, ti.qty, COALESCE(SUM(tri.qty), 0) AS returned_qty
                      FROM transaction_items ti
                      LEFT JOIN transaction_return_items tri ON tri.transaction_item_id = ti.id
                     WHERE ti.transaction_id = ? AND ti.tenant_id = ?
                     GROUP BY ti.id HAVING returned_qty < ti.qty
                 ) unreturned',
                [$transactionId, $scope['tenant_id']]);
            if ((int) ($remaining['remaining'] ?? 0) === 0) {
                $pdo->prepare("UPDATE transactions SET status = 'refunded' WHERE id = ? AND tenant_id = ?")
                    ->execute([$transactionId, $scope['tenant_id']]);
            }
            $dueAfterReturnCents = max(0, $dueBeforeReturnCents - $creditAppliedCents);
            $paymentStatus = $dueAfterReturnCents === 0
                ? 'paid'
                : ($netPaidCents > 0 ? 'partial' : 'unpaid');
            $pdo->prepare('UPDATE transactions SET payment_status = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$paymentStatus, $transactionId, $scope['tenant_id']]);
            $pdo->prepare(
                'INSERT INTO transaction_status_logs (tenant_id, transaction_id, status_from, status_to, changed_by, note)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $scope['tenant_id'], $transactionId, $transaction['status'],
                (int) ($remaining['remaining'] ?? 0) === 0 ? 'refunded' : $transaction['status'],
                $scope['user_id'], 'Retur ' . $returnId . ' (' . number_format($refundCents / 100, 2, '.', '') . ')',
            ]);
            AuditLogger::record($pdo, $scope['tenant_id'], $scope['branch_id'], $scope['user_id'],
                'sale.returned', 'transaction_return', $returnId, [
                    'transaction_id' => $transactionId,
                    'refund_amount' => number_format($refundCents / 100, 2, '.', ''),
                    'settled_refund_amount' => number_format($settledRefundCents / 100, 2, '.', ''),
                    'credit_applied' => number_format($creditAppliedCents / 100, 2, '.', ''),
                ]);
            $pdo->commit();
            Response::send(201, true, 'Retur berhasil dicatat dan stok diperbarui.', [
                'return_id' => $returnId,
                'return_value' => number_format($refundCents / 100, 2, '.', ''),
                'settled_refund_amount' => number_format($settledRefundCents / 100, 2, '.', ''),
                'credit_applied' => number_format($creditAppliedCents / 100, 2, '.', ''),
            ]);
        } catch (PosReturnException $e) {
            $this->rollback($pdo);
            Response::send(422, false, $e->getMessage());
        } catch (Throwable $e) {
            $this->rollback($pdo);
            error_log('[pos-return] gagal: ' . $e->getMessage());
            Response::send(500, false, 'Retur gagal diproses. Tidak ada perubahan yang disimpan.');
        }
    }

    private function scope(PDO $pdo): array
    {
        $tenantId = (int) Auth::tenantId();
        $branchId = (int) Auth::branchId();
        $userId = (int) Auth::userId();
        if (!Auth::check() || $tenantId <= 0 || $branchId <= 0 || $userId <= 0) {
            Response::send(403, false, 'Akun belum terhubung ke tenant/cabang aktif.');
        }
        $ownerAccountSchema = Auth::role() === 'owner'
            && (int) $pdo->query(
                "SELECT COUNT(*) FROM information_schema.tables
                  WHERE table_schema = DATABASE()
                    AND table_name IN ('owner_accounts', 'owner_account_tenants')"
            )->fetchColumn() === 2;
        $access = $ownerAccountSchema
            ? $this->one($pdo,
                'SELECT u.role, u.is_active AS user_active, u.branch_id AS user_branch_id,
                        oa.status, oa.subscription_ends_at, t.timezone
                   FROM users u
                   JOIN owner_accounts oa ON oa.owner_user_id = u.id
                   JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id AND oat.tenant_id = ?
                   JOIN tenants t ON t.id = oat.tenant_id
                   JOIN branches b ON b.id = ? AND b.tenant_id = t.id
                  WHERE u.id = ? AND u.role = \'owner\' AND t.deleted_at IS NULL
                    AND u.deleted_at IS NULL AND b.is_active = 1 AND b.deleted_at IS NULL',
                [$tenantId, $branchId, $userId])
            : null;
        $access = $access ?? $this->one($pdo,
                'SELECT u.role, u.is_active AS user_active, u.branch_id AS user_branch_id,
                        t.status, t.subscription_ends_at, t.timezone
                   FROM users u
                   JOIN tenants t ON t.id = u.tenant_id
                   JOIN branches b ON b.id = ?
                  WHERE u.id = ? AND u.tenant_id = ? AND t.deleted_at IS NULL
                    AND u.deleted_at IS NULL AND b.tenant_id = t.id AND b.is_active = 1 AND b.deleted_at IS NULL',
                [$branchId, $userId, $tenantId]);
        if ($access === null || (int) $access['user_active'] !== 1
            || !in_array($access['role'], ['owner', 'manager', 'cashier'], true)
            || $access['role'] !== Auth::role()
            || ($access['user_branch_id'] !== null && (int) $access['user_branch_id'] !== $branchId)
            || !in_array($access['status'], ['trial', 'active'], true)) {
            Response::send(403, false, 'Akun, cabang, atau toko tidak aktif.');
        }
        if ($access['subscription_ends_at'] !== null) {
            try {
                $timezone = new \DateTimeZone((string) $access['timezone']);
            } catch (Throwable $e) {
                $timezone = new \DateTimeZone('Asia/Jakarta');
            }
            if (new DateTimeImmutable((string) $access['subscription_ends_at'], $timezone) <= new DateTimeImmutable('now', $timezone)) {
                Response::send(403, false, 'Masa langganan toko telah berakhir.');
            }
        }
        return ['tenant_id' => $tenantId, 'branch_id' => $branchId, 'user_id' => $userId, 'role' => (string) $access['role']];
    }

    private function dateRange(): array
    {
        $from = Request::query('from') ?? date('Y-m-d');
        $to = Request::query('to') ?? $from;
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        $startErrors = DateTimeImmutable::getLastErrors();
        $endErrors = DateTimeImmutable::getLastErrors();
        if ($start === false || $end === false
            || ($startErrors !== false && ($startErrors['warning_count'] || $startErrors['error_count']))
            || ($endErrors !== false && ($endErrors['warning_count'] || $endErrors['error_count']))
            || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to
            || $end < $start || $start->diff($end)->days > 365) {
            Response::send(422, false, 'Rentang tanggal tidak valid (maksimal 366 hari).');
        }
        return [$from, $to];
    }

    private function ensureReturnTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS transaction_returns (
               id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               tenant_id INT UNSIGNED NOT NULL,
               branch_id INT UNSIGNED NOT NULL,
               transaction_id BIGINT UNSIGNED NOT NULL,
               refund_method ENUM(\'cash\',\'qris\',\'transfer\',\'debit\',\'credit\',\'other\') NOT NULL DEFAULT \'cash\',
               refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
               note VARCHAR(255) NULL,
               returned_by INT UNSIGNED NOT NULL,
               returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               KEY idx_return_transaction (tenant_id, transaction_id, returned_at),
               CONSTRAINT fk_return_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
               CONSTRAINT fk_return_branch FOREIGN KEY (branch_id) REFERENCES branches (id),
               CONSTRAINT fk_return_transaction FOREIGN KEY (transaction_id) REFERENCES transactions (id),
               CONSTRAINT fk_return_user FOREIGN KEY (returned_by) REFERENCES users (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS transaction_return_items (
               id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
               return_id BIGINT UNSIGNED NOT NULL,
               transaction_item_id BIGINT UNSIGNED NOT NULL,
               qty DECIMAL(12,3) NOT NULL,
               refund_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY uq_return_item (return_id, transaction_item_id),
               KEY idx_return_item_transaction (transaction_item_id),
               CONSTRAINT fk_return_item_header FOREIGN KEY (return_id) REFERENCES transaction_returns (id),
               CONSTRAINT fk_return_item_line FOREIGN KEY (transaction_item_id) REFERENCES transaction_items (id)
             ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function positiveInt($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        return is_string($value) && ctype_digit($value) && strlen($value) < 19 && (int) $value > 0
            ? (int) $value : null;
    }

    private function qtyMilli($value): ?int
    {
        if (is_int($value)) {
            return $value > 0 && $value <= 999999 ? $value * 1000 : null;
        }
        if (is_float($value)) {
            $milli = $value * 1000;
            return $value > 0 && $value <= 999999 && abs($milli - round($milli)) < 0.000001
                ? (int) round($milli) : null;
        }
        if (is_string($value) && preg_match('/^\d{1,6}(?:\.\d{1,3})?$/', $value)) {
            $milli = (int) round((float) $value * 1000);
            return $milli > 0 ? $milli : null;
        }
        return null;
    }

    private function moneyCents($value): ?int
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        if (!preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', (string) $value)) {
            return null;
        }
        $cents = round((float) $value * 100);
        return $cents >= 0 && $cents <= 100000000000000 ? (int) $cents : null;
    }

    private function one(PDO $pdo, string $sql, array $params): ?array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function all(PDO $pdo, string $sql, array $params): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
