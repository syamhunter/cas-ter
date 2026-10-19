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

final class OwnerController
{
    private const MODULES = [
        'retail' => ['name' => 'Retail / Toko', 'summary' => 'Produk, barcode, dan stok'],
        'fnb' => ['name' => 'Cafe & Resto', 'summary' => 'Meja, menu, dan pesanan'],
        'pharmacy' => ['name' => 'Apotek', 'summary' => 'Batch, resep, dan kedaluwarsa'],
        'laundry' => ['name' => 'Laundry', 'summary' => 'Berat, layanan, dan status pengerjaan'],
        'printing' => ['name' => 'Percetakan', 'summary' => 'Ukuran, bahan, dan finishing'],
        'service' => ['name' => 'Jasa', 'summary' => 'Durasi, petugas, dan layanan'],
    ];

    public function index(): void
    {
        $context = $this->ownerContext();
        $pdo = $context['pdo'];
        $tenant = $context['tenant'];
        $branch = $context['branch'];
        $now = new DateTimeImmutable('now', $context['timezone']);
        $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $nextMonth = $now->modify('first day of next month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $tomorrowStart = $now->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
        $summary = $this->one($pdo,
            'SELECT SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS transactions_today,
                    COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status IN (\'paid\', \'refunded\') THEN grand_total ELSE 0 END), 0) AS sales_today,
                    SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS transactions_month,
                    COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status IN (\'paid\', \'refunded\') THEN grand_total ELSE 0 END), 0) AS sales_month
               FROM transactions WHERE tenant_id = ? AND branch_id = ? AND created_at >= ? AND created_at < ?',
            [$todayStart, $tomorrowStart, $todayStart, $tomorrowStart, $monthStart, $nextMonth, $monthStart, $nextMonth,
                $tenant['id'], $branch['id'], $monthStart, $nextMonth]) ?? [];
        $pooledTransactions = $this->one($pdo,
            'SELECT COUNT(*) AS transactions_month FROM transactions WHERE created_at >= ? AND created_at < ?
              AND tenant_id IN (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
            [$monthStart, $nextMonth, $context['accountId']]);
        $accountUsage = $this->one($pdo,
            'SELECT COUNT(*) AS active_users FROM users WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
              (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
            [$context['accountId']]);
        $branches = $this->one($pdo,
            'SELECT COUNT(*) AS active_branches FROM branches WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
              (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
            [$context['accountId']]);
        $products = $this->one($pdo,
            'SELECT COUNT(*) AS active_products FROM products WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$tenant['id']]);
        $recent = $this->all($pdo,
            'SELECT t.invoice_no, t.order_type, t.status, t.grand_total, t.created_at, u.name AS cashier_name
               FROM transactions t JOIN users u ON u.id = t.cashier_id
              WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at < ?
              ORDER BY t.created_at DESC, t.id DESC LIMIT 8',
            [$tenant['id'], $branch['id'], $todayStart, $tomorrowStart]);
        $maxTransactions = $tenant['max_transactions_month'] !== null ? (int) $tenant['max_transactions_month'] : null;
        $accountMonthCount = (int) ($pooledTransactions['transactions_month'] ?? 0);
        $usagePct = $maxTransactions !== null && $maxTransactions > 0
            ? min(100, (int) ceil($accountMonthCount * 100 / $maxTransactions)) : null;
        $daysLeft = null;
        if ($tenant['subscription_ends_at'] !== null) {
            $daysLeft = max(0, (int) $now->diff(new DateTimeImmutable((string) $tenant['subscription_ends_at'], $context['timezone']))->format('%r%a'));
        }
        $this->renderOwnerPage('owner/dashboard', 'dashboard', 'Ringkasan usaha', $context + [
            'summary' => $summary, 'recent' => $recent,
            'activeUsers' => (int) ($accountUsage['active_users'] ?? 0),
            'activeBranches' => (int) ($branches['active_branches'] ?? 0),
            'activeProducts' => (int) ($products['active_products'] ?? 0),
            'daysLeft' => $daysLeft, 'monthLimit' => $maxTransactions,
            'accountMonthCount' => $accountMonthCount, 'usagePct' => $usagePct,
            'monthCount' => (int) ($summary['transactions_month'] ?? 0), 'today' => $now->format('d F Y'),
        ]);
    }

    private function ownerContext(): array
    {
        if (!Auth::check()) Response::redirect(Router::base() . '/login');
        $tenantId = (int) Auth::tenantId();
        $branchId = (int) Auth::branchId();
        if ($tenantId <= 0 || $branchId <= 0) Response::page(403, 'Akses ditolak', 'Akun pemilik belum terhubung ke usaha dan cabang aktif.');
        $pdo = Database::pdo();
        $accountId = $this->ensureOwnerAccount($pdo, Auth::userId());
        $tenant = $this->one($pdo,
            'SELECT t.id, t.name, t.owner_name, t.email, t.phone, t.timezone,
                    oa.status, oa.subscription_ends_at, oa.plan_id, p.name AS plan_name, p.code AS plan_code,
                    p.price_monthly, p.max_branches, p.max_users, p.max_transactions_month, p.allowed_modules
               FROM tenants t JOIN owner_account_tenants oat ON oat.tenant_id = t.id AND oat.owner_account_id = ?
               JOIN owner_accounts oa ON oa.id = oat.owner_account_id JOIN plans p ON p.id = oa.plan_id
              WHERE t.id = ? AND t.deleted_at IS NULL
                AND EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = \'owner\' AND u.is_active = 1 AND u.deleted_at IS NULL)',
            [$accountId, $tenantId, Auth::userId()]);
        if ($tenant === null) Response::page(403, 'Usaha tidak ditemukan', 'Data usaha tidak tersedia. Hubungi administrator.');
        try { $timezone = new DateTimeZone((string) $tenant['timezone']); }
        catch (Throwable $e) { $timezone = new DateTimeZone('Asia/Jakarta'); }
        $branch = $this->one($pdo,
            'SELECT id, name, address, settings FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
            [$branchId, $tenantId]);
        if ($branch === null) Response::page(403, 'Cabang tidak aktif', 'Cabang usaha tidak tersedia. Hubungi administrator.');
        $settings = json_decode((string) ($branch['settings'] ?? ''), true);
        $settings = is_array($settings) ? $settings : [];
        $branch['tax_pct'] = max(0.0, min(100.0, (float) ($settings['tax_pct'] ?? 0)));
        $branch['tax_mode'] = in_array($settings['tax_mode'] ?? '', ['none', 'inclusive', 'exclusive'], true) ? $settings['tax_mode'] : 'exclusive';
        $businesses = $this->all($pdo,
            'SELECT t.id, t.name, b.id AS branch_id, b.name AS branch_name FROM owner_account_tenants oat
               JOIN tenants t ON t.id = oat.tenant_id AND t.deleted_at IS NULL
               LEFT JOIN branches b ON b.tenant_id = t.id AND b.is_active = 1 AND b.deleted_at IS NULL
                 AND b.id = (SELECT MIN(b2.id) FROM branches b2 WHERE b2.tenant_id = t.id AND b2.is_active = 1 AND b2.deleted_at IS NULL)
              WHERE oat.owner_account_id = ? ORDER BY t.name, t.id', [$accountId]);
        $allowed = $this->decode($tenant['allowed_modules']);
        $availableModules = [];
        foreach (self::MODULES as $code => $details) {
            if ($allowed === [] || in_array($code, $allowed, true)) $availableModules[] = ['code' => $code, 'active' => false] + $details;
        }
        $moduleRows = $this->all($pdo,
            'SELECT module_code FROM tenant_modules WHERE tenant_id = ? AND is_active = 1 ORDER BY module_code', [$tenantId]);
        $activeCodes = array_column($moduleRows, 'module_code');
        $modules = [];
        foreach ($availableModules as &$module) {
            $module['active'] = in_array($module['code'], $activeCodes, true);
            if ($module['active']) $modules[] = ['code' => $module['code']] + self::MODULES[$module['code']];
        }
        unset($module);
        return [
            'pdo' => $pdo, 'accountId' => $accountId, 'tenant' => $tenant, 'branch' => $branch,
            'timezone' => $timezone, 'businesses' => $businesses, 'modules' => $modules,
            'availableModules' => $availableModules,
        ];
    }

    private function renderOwnerPage(string $view, string $activePage, string $title, array $data): void
    {
        $data = array_merge(['base' => Router::base(), 'csrf' => Csrf::token(), 'ownerName' => Auth::name()], $data);
        $ticketTable = $data['pdo']->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'support_tickets'");
        $ticketTable->execute();
        $data['supportUnread'] = 0;
        if ((int) $ticketTable->fetchColumn() === 1) {
            $unread = $data['pdo']->prepare('SELECT COUNT(*) FROM support_tickets WHERE owner_account_id = ? AND last_message_at > COALESCE(owner_read_at, \'1970-01-01 00:00:00\')');
            $unread->execute([$data['accountId']]);
            $data['supportUnread'] = (int) $unread->fetchColumn();
        }
        ob_start();
        try { View::render($view, $data); $body = (string) ob_get_contents(); }
        finally { ob_end_clean(); }
        $flash = $_SESSION['owner_flash'] ?? null;
        unset($_SESSION['owner_flash']);
        View::render('owner/layout', $data + [
            'activePage' => $activePage, 'pageTitle' => $title, 'body' => $body,
            'ownerFlash' => is_array($flash) ? $flash : null,
        ]);
    }

    public function businessesPage(): void
    {
        $context = $this->ownerContext();
        $pdo = $context['pdo'];
        $usage = $this->one($pdo,
            'SELECT COUNT(*) AS active_branches FROM branches WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
              (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
            [$context['accountId']]);
        $this->renderOwnerPage('owner/businesses', 'businesses', 'Usaha & Profil', $context + [
            'activeBranches' => (int) ($usage['active_branches'] ?? 0),
        ]);
    }

    public function teamPage(): void
    {
        $context = $this->ownerContext();
        $pdo = $context['pdo'];
        $tenantId = (int) $context['tenant']['id'];
        $staffUsers = $this->all($pdo,
            'SELECT u.id, u.name, u.email, u.is_active, u.last_login_at, u.created_at, b.name AS branch_name
               FROM users u LEFT JOIN branches b ON b.id = u.branch_id AND b.tenant_id = u.tenant_id
              WHERE u.tenant_id = ? AND u.role = \'cashier\' AND u.deleted_at IS NULL
              ORDER BY u.is_active DESC, u.name ASC, u.id ASC', [$tenantId]);
        $activeBranches = $this->all($pdo,
            'SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY name', [$tenantId]);
        $usage = $this->one($pdo,
            'SELECT COUNT(*) AS active_users FROM users WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
              (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)', [$context['accountId']]);
        $this->renderOwnerPage('owner/team', 'team', 'Tim & Kasir', $context + [
            'staffUsers' => $staffUsers, 'activeBranchesList' => $activeBranches,
            'activeUsers' => (int) ($usage['active_users'] ?? 0),
        ]);
    }

    public function billingPage(): void
    {
        $context = $this->ownerContext();
        $pdo = $context['pdo'];
        $billingKey = 'owner-' . (int) $context['accountId'];
        $invoices = [];
        $proofsByInvoice = [];
        $summary = ['total' => 0, 'unpaid_count' => 0, 'overdue_count' => 0, 'paid_count' => 0, 'outstanding' => 0];
        if ($this->tableExists($pdo, 'subscription_invoices')) {
            (new BillingController())->ensureProofSchema($pdo);
            $invoices = $this->all($pdo,
                "SELECT i.*, CASE WHEN i.status = 'unpaid' AND i.due_at < CURRENT_DATE THEN 'overdue' ELSE i.status END AS display_status,
                        ip.paid_at, ip.payment_method, ip.reference
                   FROM subscription_invoices i LEFT JOIN invoice_payments ip ON ip.invoice_id = i.id
                  WHERE i.billing_key = ? ORDER BY i.id DESC LIMIT 50", [$billingKey]);
            $summary = $this->one($pdo,
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                        SUM(CASE WHEN status = 'unpaid' AND due_at >= CURRENT_DATE THEN 1 ELSE 0 END) AS unpaid_count,
                        SUM(CASE WHEN status = 'unpaid' AND due_at < CURRENT_DATE THEN 1 ELSE 0 END) AS overdue_count,
                        COALESCE(SUM(CASE WHEN status IN ('unpaid','overdue') THEN amount ELSE 0 END), 0) AS outstanding
                   FROM subscription_invoices WHERE billing_key = ? AND status <> 'void'", [$billingKey]) ?? $summary;
                        $proofs = $this->all($pdo,
                                'SELECT pp.*, i.invoice_no FROM invoice_payment_proofs pp
                                     JOIN subscription_invoices i ON i.id = pp.invoice_id
                                    WHERE pp.owner_account_id = ? AND pp.id IN
                                        (SELECT MAX(id) FROM invoice_payment_proofs WHERE owner_account_id = ? GROUP BY invoice_id)
                                    ORDER BY pp.id DESC',
                                [$context['accountId'], $context['accountId']]);
                        foreach ($proofs as $proof) $proofsByInvoice[(int) $proof['invoice_id']] = $proof;
        }
        $legacyPayments = [];
        if ($this->tableExists($pdo, 'subscription_payments')) {
            $legacyPayments = $this->all($pdo,
                'SELECT sp.*, t.name AS tenant_name FROM subscription_payments sp
                   JOIN tenants t ON t.id = sp.tenant_id
                  WHERE sp.owner_account_id = ? ORDER BY sp.id DESC LIMIT 20',
                [$context['accountId']]);
        }
        $this->renderOwnerPage('owner/billing', 'billing', 'Paket & Billing', $context + [
            'invoices' => $invoices, 'summary' => $summary, 'legacyPayments' => $legacyPayments,
            'proofsByInvoice' => $proofsByInvoice,
            'invoiceSchemaAvailable' => $this->tableExists($pdo, 'subscription_invoices'),
        ]);
    }

    public function invoiceDetail(): void
    {
        $invoiceId = Request::query('id');
        $invoiceId = $invoiceId === null ? false : filter_var($invoiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($invoiceId === false) Response::page(404, '404', 'Invoice tidak ditemukan.');
        $context = $this->ownerContext();
        if (!$this->tableExists($context['pdo'], 'subscription_invoices')) Response::page(404, '404', 'Invoice tidak ditemukan.');
        $statement = $context['pdo']->prepare(
            "SELECT i.*, CASE WHEN i.status = 'unpaid' AND i.due_at < CURRENT_DATE THEN 'overdue' ELSE i.status END AS display_status,
                    t.name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone,
                    ip.paid_at, ip.payment_method, ip.reference
               FROM subscription_invoices i JOIN tenants t ON t.id = i.tenant_id
               LEFT JOIN invoice_payments ip ON ip.invoice_id = i.id
              WHERE i.id = ? AND i.billing_key = ?"
        );
        $statement->execute([(int) $invoiceId, 'owner-' . (int) $context['accountId']]);
        $invoice = $statement->fetch();
        if (!$invoice) Response::page(404, '404', 'Invoice tidak ditemukan pada akun Anda.');
        $this->renderOwnerPage('owner/invoice', 'billing', 'Invoice ' . $invoice['invoice_no'], $context + ['invoice' => $invoice]);
    }

    public function legacyIndex(): void
    {
        if (!Auth::check()) {
            Response::redirect(Router::base() . '/login');
        }
        $tenantId = (int) Auth::tenantId();
        $branchId = (int) Auth::branchId();
        if ($tenantId <= 0 || $branchId <= 0) {
            Response::page(403, 'Akses ditolak', 'Akun pemilik belum terhubung ke toko dan cabang aktif.');
            return;
        }

        try {
            $pdo = Database::pdo();
            $accountId = $this->ensureOwnerAccount($pdo, Auth::userId());
            $tenant = $this->one($pdo,
                'SELECT t.id, t.name, t.owner_name, t.email, t.phone, oa.status, oa.subscription_ends_at, t.timezone,
                        oa.plan_id, p.name AS plan_name, p.code AS plan_code, p.price_monthly,
                        p.max_branches, p.max_users, p.max_transactions_month, p.allowed_modules
                   FROM tenants t
                   JOIN owner_account_tenants oat ON oat.tenant_id = t.id AND oat.owner_account_id = ?
                   JOIN owner_accounts oa ON oa.id = oat.owner_account_id
                   JOIN plans p ON p.id = oa.plan_id
                  WHERE t.id = ? AND t.deleted_at IS NULL
                    AND EXISTS (SELECT 1 FROM users u WHERE u.id = ? AND u.role = \'owner\' AND u.is_active = 1 AND u.deleted_at IS NULL)',
                [$accountId, $tenantId, Auth::userId()]);
            if ($tenant === null) {
                Response::page(403, 'Toko tidak ditemukan', 'Data toko tidak tersedia. Hubungi administrator.');
                return;
            }

            try {
                $timezone = new DateTimeZone((string) $tenant['timezone']);
            } catch (Throwable $e) {
                $timezone = new DateTimeZone('Asia/Jakarta');
            }
            $now = new DateTimeImmutable('now', $timezone);
            $monthStart = $now->modify('first day of this month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
            $nextMonth = $now->modify('first day of next month')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
            $todayStart = $now->setTime(0, 0, 0)->format('Y-m-d H:i:s');
            $tomorrowStart = $now->modify('+1 day')->setTime(0, 0, 0)->format('Y-m-d H:i:s');

            $branch = $this->one($pdo,
                'SELECT id, name, address, settings FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
                [$branchId, $tenantId]);
            if ($branch === null) {
                Response::page(403, 'Cabang tidak aktif', 'Cabang toko tidak tersedia. Hubungi administrator.');
                return;
            }
            $branchSettings = json_decode((string) ($branch['settings'] ?? ''), true);
            $branchSettings = is_array($branchSettings) ? $branchSettings : [];
            $branch['tax_pct'] = max(0.0, min(100.0, (float) ($branchSettings['tax_pct'] ?? 0)));
            $branch['tax_mode'] = in_array($branchSettings['tax_mode'] ?? '', ['none', 'inclusive', 'exclusive'], true)
                ? $branchSettings['tax_mode'] : 'exclusive';

            $planModules = $this->decode($tenant['allowed_modules']);
            $moduleRows = $this->all($pdo,
                'SELECT module_code FROM tenant_modules WHERE tenant_id = ? AND is_active = 1 ORDER BY module_code',
                [$tenantId]);
            $activeModules = [];
            $availableModules = [];
            foreach (self::MODULES as $code => $details) {
                if ($planModules === [] || in_array($code, $planModules, true)) {
                    $availableModules[] = ['code' => $code, 'active' => false] + $details;
                }
            }
            foreach ($moduleRows as $row) {
                $code = (string) $row['module_code'];
                if (isset(self::MODULES[$code]) && ($planModules === [] || in_array($code, $planModules, true))) {
                    $activeModules[] = ['code' => $code] + self::MODULES[$code];
                }
            }
            $activeCodes = array_column($activeModules, 'code');
            foreach ($availableModules as &$module) {
                $module['active'] = in_array($module['code'], $activeCodes, true);
            }
            unset($module);

            $businesses = $this->all($pdo,
                'SELECT t.id, t.name, b.id AS branch_id, b.name AS branch_name
                   FROM owner_account_tenants oat
                   JOIN tenants t ON t.id = oat.tenant_id AND t.deleted_at IS NULL
                   LEFT JOIN branches b ON b.tenant_id = t.id AND b.is_active = 1 AND b.deleted_at IS NULL
                     AND b.id = (SELECT MIN(b2.id) FROM branches b2 WHERE b2.tenant_id = t.id AND b2.is_active = 1 AND b2.deleted_at IS NULL)
                  WHERE oat.owner_account_id = ? ORDER BY t.name, t.id',
                [$accountId]);

            $summary = $this->one($pdo,
                'SELECT
                    SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS transactions_today,
                    COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status IN (\'paid\', \'refunded\') THEN grand_total ELSE 0 END), 0) AS sales_today,
                    SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) AS transactions_month,
                    COALESCE(SUM(CASE WHEN created_at >= ? AND created_at < ? AND status IN (\'paid\', \'refunded\') THEN grand_total ELSE 0 END), 0) AS sales_month
                   FROM transactions WHERE tenant_id = ? AND branch_id = ? AND created_at >= ? AND created_at < ?',
                [
                    $todayStart, $tomorrowStart, $todayStart, $tomorrowStart,
                    $monthStart, $nextMonth, $monthStart, $nextMonth,
                    $tenantId, $branchId, $monthStart, $nextMonth,
                ]) ?? [];

            $users = $this->one($pdo,
                'SELECT COUNT(*) AS active_users FROM users u
                  WHERE u.is_active = 1 AND u.deleted_at IS NULL AND u.tenant_id IN
                    (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
                [$accountId]);
            $branches = $this->one($pdo,
                'SELECT COUNT(*) AS active_branches FROM branches
                  WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
                    (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
                [$accountId]);
            $pooledTransactions = $this->one($pdo,
                'SELECT COUNT(*) AS transactions_month FROM transactions
                  WHERE created_at >= ? AND created_at < ? AND tenant_id IN
                    (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
                [$monthStart, $nextMonth, $accountId]);
            $staffUsers = $this->all($pdo,
                'SELECT u.id, u.name, u.email, u.is_active, u.last_login_at, u.created_at, b.name AS branch_name
                   FROM users u
                   LEFT JOIN branches b ON b.id = u.branch_id AND b.tenant_id = u.tenant_id
                  WHERE u.tenant_id = ? AND u.role = \'cashier\' AND u.deleted_at IS NULL
                  ORDER BY u.is_active DESC, u.name ASC, u.id ASC',
                [$tenantId]);
            $activeBranchesList = $this->all($pdo,
                'SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY name',
                [$tenantId]);
            $products = $this->one($pdo,
                'SELECT COUNT(*) AS active_products FROM products WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
                [$tenantId]);
            $recent = $this->all($pdo,
                'SELECT t.invoice_no, t.order_type, t.status, t.grand_total, t.created_at, u.name AS cashier_name
                   FROM transactions t JOIN users u ON u.id = t.cashier_id
                  WHERE t.tenant_id = ? AND t.branch_id = ? AND t.created_at >= ? AND t.created_at < ?
                  ORDER BY t.created_at DESC, t.id DESC LIMIT 8',
                [$tenantId, $branchId, $todayStart, $tomorrowStart]);

            $daysLeft = null;
            if ($tenant['subscription_ends_at'] !== null) {
                $end = new DateTimeImmutable((string) $tenant['subscription_ends_at'], $timezone);
                $daysLeft = max(0, (int) $now->diff($end)->format('%r%a'));
            }
            $maxTransactions = $tenant['max_transactions_month'] !== null
                ? (int) $tenant['max_transactions_month'] : null;
            $monthCount = (int) ($summary['transactions_month'] ?? 0);
            $usagePct = $maxTransactions !== null && $maxTransactions > 0
                ? min(100, (int) ceil($monthCount * 100 / $maxTransactions)) : null;
            $flash = $_SESSION['owner_flash'] ?? null;
            unset($_SESSION['owner_flash']);

            View::render('owner/index', [
                'csrf' => Csrf::token(),
                'base' => Router::base(),
                'ownerName' => Auth::name(),
                'tenant' => $tenant,
                'branch' => $branch,
                'modules' => $activeModules,
                'summary' => $summary,
                'recent' => $recent,
                'activeUsers' => (int) ($users['active_users'] ?? 0),
                'activeBranches' => (int) ($branches['active_branches'] ?? 0),
                'staffUsers' => $staffUsers,
                'activeBranchesList' => $activeBranchesList,
                'businesses' => $businesses,
                'accountId' => $accountId,
                'availableModules' => $availableModules,
                'ownerFlash' => is_array($flash) ? $flash : null,
                'activeProducts' => (int) ($products['active_products'] ?? 0),
                'daysLeft' => $daysLeft,
                'monthLimit' => $maxTransactions,
                'usagePct' => $maxTransactions !== null && $maxTransactions > 0
                    ? min(100, (int) ceil((int) ($pooledTransactions['transactions_month'] ?? 0) * 100 / $maxTransactions))
                    : null,
                'monthCount' => (int) ($summary['transactions_month'] ?? 0),
                'accountMonthCount' => (int) ($pooledTransactions['transactions_month'] ?? 0),
                'today' => $now->format('d F Y'),
            ]);
        } catch (Throwable $e) {
            error_log('[owner-dashboard] gagal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            Response::page(500, 'Dashboard tidak tersedia', 'Ringkasan usaha gagal dimuat. Silakan coba lagi nanti.');
        }
    }

    public function switchBusiness(): void
    {
        $tenantId = (int) Request::post('tenant_id');
        $ownerId = Auth::userId();
        try {
            $pdo = Database::pdo();
            $accountId = $this->ensureOwnerAccount($pdo, $ownerId);
            $business = $this->one($pdo,
                'SELECT t.id, b.id AS branch_id
                   FROM owner_account_tenants oat
                   JOIN tenants t ON t.id = oat.tenant_id AND t.deleted_at IS NULL
                   JOIN branches b ON b.tenant_id = t.id AND b.is_active = 1 AND b.deleted_at IS NULL
                  WHERE oat.owner_account_id = ? AND t.id = ?
                  ORDER BY b.id LIMIT 1',
                [$accountId, $tenantId]);
            if ($business === null) {
                throw new \RuntimeException('Usaha tidak ditemukan pada akun pemilik ini.');
            }
            Auth::selectBusiness((int) $business['id'], (int) $business['branch_id']);
            Response::redirect(Router::base() . '/owner');
        } catch (\RuntimeException $e) {
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[owner] switch business gagal: ' . $e->getMessage());
            $this->flash('danger', 'Usaha gagal dipilih. Silakan coba lagi.');
        }
        Response::redirect(Router::base() . '/owner');
    }

    public function createBusiness(): void
    {
        $ownerId = Auth::userId();
        $name = trim((string) Request::post('business_name'));
        $branchName = trim((string) Request::post('branch_name')) ?: 'Cabang Utama';
        $address = trim((string) Request::post('address'));
        $phone = trim((string) Request::post('phone'));
        $modules = array_values(array_unique(array_intersect(array_keys(self::MODULES), (array) ($_POST['modules'] ?? []))));
        if ($this->length($name) < 3 || $this->length($name) > 150
            || $this->length($branchName) > 150 || $this->length($address) > 1000
            || strlen($phone) > 30 || !$modules) {
            $this->flash('danger', 'Isi nama usaha, nama cabang, kontak yang valid, dan pilih minimal satu bidang usaha.');
            $this->back('/owner/businesses');
        }

        $pdo = Database::pdo();
        try {
            $accountId = $this->ensureOwnerAccount($pdo, $ownerId);
            $pdo->beginTransaction();
            $account = $this->one($pdo,
                'SELECT oa.plan_id, oa.status, oa.subscription_ends_at, p.allowed_modules, p.is_active, p.max_branches
                   FROM owner_accounts oa JOIN plans p ON p.id = oa.plan_id
                  WHERE oa.id = ? FOR UPDATE',
                [$accountId]);
            if ($account === null || (int) $account['is_active'] !== 1
                || !in_array($account['status'], ['trial', 'active'], true)) {
                throw new \RuntimeException('Paket atau langganan pemilik tidak aktif.');
            }
            if ($account['subscription_ends_at'] !== null
                && new DateTimeImmutable((string) $account['subscription_ends_at']) <= new DateTimeImmutable('now')) {
                throw new \RuntimeException('Langganan akun pemilik telah berakhir.');
            }
            $branchCount = $this->one($pdo,
                'SELECT COUNT(*) AS active_branches FROM branches
                  WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
                    (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
                [$accountId]);
            if ($account['max_branches'] !== null
                && (int) ($branchCount['active_branches'] ?? 0) >= (int) $account['max_branches']) {
                throw new \RuntimeException('Kuota cabang bersama sudah habis. Upgrade paket untuk menambah usaha atau cabang.');
            }
            $allowed = $this->decode($account['allowed_modules']);
            if ($allowed && array_diff($modules, $allowed)) {
                throw new \RuntimeException('Bidang usaha yang dipilih tidak termasuk dalam paket bersama.');
            }
            $this->requireActiveOwner($pdo, (int) Auth::tenantId(), $ownerId);
            $owner = $this->one($pdo, 'SELECT email FROM users WHERE id = ? AND is_active = 1 AND deleted_at IS NULL', [$ownerId]);
            if ($owner === null) {
                throw new \RuntimeException('Akun pemilik tidak tersedia.');
            }

            $slugBase = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            $slug = substr($slugBase !== '' ? $slugBase : 'usaha', 0, 80) . '-' . bin2hex(random_bytes(4));
            $pdo->prepare(
                'INSERT INTO tenants (plan_id, name, slug, owner_name, email, phone, status, subscription_ends_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $account['plan_id'], $name, $slug, Auth::name(),
                (string) $owner['email'],
                $phone !== '' ? $phone : null, $account['status'], $account['subscription_ends_at'],
            ]);
            $tenantId = (int) $pdo->lastInsertId();

            $moduleInsert = $pdo->prepare(
                'INSERT INTO tenant_modules (tenant_id, module_code, is_active, settings) VALUES (?, ?, 1, ?)'
            );
            foreach ($modules as $module) {
                $moduleInsert->execute([$tenantId, $module, $module === 'fnb' ? json_encode(['service_charge_pct' => 0]) : null]);
            }
            $pdo->prepare('INSERT INTO branches (tenant_id, code, name, address, settings) VALUES (?, ?, ?, ?, ?)')
                ->execute([$tenantId, 'PST', $branchName, $address !== '' ? $address : null, json_encode(['tax_pct' => 0, 'tax_mode' => 'exclusive'])]);
            $branchId = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO owner_account_tenants (owner_account_id, tenant_id) VALUES (?, ?)')
                ->execute([$accountId, $tenantId]);

            if (in_array('fnb', $modules, true)) {
                $table = $pdo->prepare('INSERT INTO dining_tables (tenant_id, branch_id, name, capacity) VALUES (?, ?, ?, 4)');
                for ($number = 1; $number <= 5; $number++) {
                    $table->execute([$tenantId, $branchId, 'Meja ' . $number]);
                }
            }
            $pdo->commit();
            Auth::selectBusiness($tenantId, $branchId);
            $this->flash('success', 'Usaha baru berhasil dibuat. Paket dan kuotanya berbagi dengan semua usaha Anda.');
            Response::redirect(Router::base() . '/owner/businesses');
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[owner] create business gagal: ' . $e->getMessage());
            $this->flash('danger', 'Usaha gagal dibuat. Silakan coba lagi.');
        }
        $this->back('/owner/businesses');
    }

    public function updateBusiness(): void
    {
        $ownerId = Auth::userId();
        $tenantId = (int) Auth::tenantId();
        $branchId = (int) Auth::branchId();
        $name = trim((string) Request::post('business_name'));
        $phone = trim((string) Request::post('phone'));
        $address = trim((string) Request::post('address'));
        $taxPctInput = Request::post('tax_pct');
        $taxMode = Request::post('tax_mode');
        $taxPct = is_numeric($taxPctInput) ? (float) $taxPctInput : -1.0;
        $modules = array_values(array_unique(array_intersect(array_keys(self::MODULES), (array) ($_POST['modules'] ?? []))));
        if ($tenantId <= 0 || $branchId <= 0 || $this->length($name) < 3 || $this->length($name) > 150
            || strlen($phone) > 30 || $this->length($address) > 1000 || !$modules
            || !is_finite($taxPct) || $taxPct < 0 || $taxPct > 100
            || !in_array($taxMode, ['none', 'exclusive', 'inclusive'], true)) {
            $this->flash('danger', 'Periksa nama usaha, alamat, kontak, pengaturan pajak, dan pilih minimal satu bidang usaha.');
            $this->back('/owner/businesses');
        }

        $pdo = Database::pdo();
        try {
            $accountId = $this->ensureOwnerAccount($pdo, $ownerId);
            $pdo->beginTransaction();
            $account = $this->one($pdo,
                'SELECT oa.plan_id, p.allowed_modules FROM owner_accounts oa JOIN plans p ON p.id = oa.plan_id
                  WHERE oa.id = ? FOR UPDATE',
                [$accountId]);
            if ($account === null) {
                throw new \RuntimeException('Paket akun pemilik tidak ditemukan.');
            }
            $this->requireActiveOwner($pdo, $tenantId, $ownerId);
            $branch = $this->one($pdo,
                'SELECT id, settings FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL FOR UPDATE',
                [$branchId, $tenantId]);
            if ($branch === null) {
                throw new \RuntimeException('Cabang usaha tidak aktif atau tidak ditemukan.');
            }
            $allowed = $this->decode($account['allowed_modules']);
            if ($allowed && array_diff($modules, $allowed)) {
                throw new \RuntimeException('Bidang usaha yang dipilih tidak termasuk dalam paket bersama.');
            }

            $pdo->prepare('UPDATE tenants SET name = ?, phone = ? WHERE id = ?')
                ->execute([$name, $phone !== '' ? $phone : null, $tenantId]);
            $branchSettings = json_decode((string) ($branch['settings'] ?? ''), true);
            $branchSettings = is_array($branchSettings) ? $branchSettings : [];
            $branchSettings['tax_pct'] = $taxPct;
            $branchSettings['tax_mode'] = $taxMode;
            $pdo->prepare('UPDATE branches SET address = ?, settings = ? WHERE id = ? AND tenant_id = ?')
                ->execute([$address !== '' ? $address : null, json_encode($branchSettings, JSON_THROW_ON_ERROR), $branchId, $tenantId]);
            $moduleUpsert = $pdo->prepare(
                'INSERT INTO tenant_modules (tenant_id, module_code, is_active, settings) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE is_active = VALUES(is_active)'
            );
            foreach (self::MODULES as $code => $_details) {
                if ($allowed && !in_array($code, $allowed, true)) {
                    continue;
                }
                $active = in_array($code, $modules, true) ? 1 : 0;
                $moduleUpsert->execute([$tenantId, $code, $active, $code === 'fnb' ? json_encode(['service_charge_pct' => 0]) : null]);
            }
            if (in_array('fnb', $modules, true)) {
                $branches = $this->all($pdo,
                    'SELECT id FROM branches WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
                    [$tenantId]);
                $tableCount = $pdo->prepare(
                    'SELECT COUNT(*) FROM dining_tables WHERE tenant_id = ? AND branch_id = ? AND deleted_at IS NULL'
                );
                $table = $pdo->prepare('INSERT INTO dining_tables (tenant_id, branch_id, name, capacity) VALUES (?, ?, ?, 4)');
                foreach ($branches as $businessBranch) {
                    $businessBranchId = (int) $businessBranch['id'];
                    $tableCount->execute([$tenantId, $businessBranchId]);
                    if ((int) $tableCount->fetchColumn() === 0) {
                        for ($number = 1; $number <= 5; $number++) {
                            $table->execute([$tenantId, $businessBranchId, 'Meja ' . $number]);
                        }
                    }
                }
            }
            $pdo->commit();
            $this->flash('success', 'Profil usaha, pengaturan pajak, alamat nota, dan bidang usaha berhasil diperbarui.');
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[owner] update business gagal: ' . $e->getMessage());
            $this->flash('danger', 'Profil usaha gagal diperbarui. Silakan coba lagi.');
        }
        Response::redirect(Router::base() . '/owner/businesses');
    }

    public function createCashier(): void
    {
        $tenantId = (int) Auth::tenantId();
        $ownerId = (int) Auth::userId();
        $branchId = (int) Request::post('branch_id');
        $name = trim((string) Request::post('name'));
        $email = strtolower(trim((string) Request::post('email')));
        $password = (string) Request::post('password');

        if ($tenantId <= 0 || $ownerId <= 0) {
            $this->flash('danger', 'Sesi pemilik tidak valid. Silakan masuk kembali.');
            $this->back();
        }
        if ($this->length($name) < 3 || $this->length($name) > 150
            || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190
            || strlen($password) < 10 || strlen($password) > 72 || $branchId <= 0) {
            $this->flash('danger', 'Periksa nama, email, kata sandi minimal 10 karakter, dan cabang kasir.');
            $this->back();
        }

        $pdo = Database::pdo();
        try {
            $accountId = $this->ensureOwnerAccount($pdo, $ownerId);
            $pdo->beginTransaction();
            $tenant = $this->one($pdo,
                'SELECT t.id, oa.plan_id, oa.status, oa.subscription_ends_at, p.max_users
                   FROM owner_accounts oa JOIN plans p ON p.id = oa.plan_id
                   JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id
                   JOIN tenants t ON t.id = oat.tenant_id
                  WHERE oa.id = ? AND t.id = ? AND t.deleted_at IS NULL FOR UPDATE',
                [$accountId, $tenantId]);
            if ($tenant === null || !in_array($tenant['status'], ['trial', 'active'], true)) {
                throw new \RuntimeException('Toko tidak aktif. Akun kasir baru tidak dapat dibuat.');
            }
            $this->requireActiveOwner($pdo, $tenantId, $ownerId);
            if ($tenant['subscription_ends_at'] !== null
                && new DateTimeImmutable((string) $tenant['subscription_ends_at']) <= new DateTimeImmutable('now')) {
                throw new \RuntimeException('Masa langganan toko telah berakhir.');
            }
            $branch = $this->one($pdo,
                'SELECT id FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL',
                [$branchId, $tenantId]);
            if ($branch === null) {
                throw new \RuntimeException('Cabang yang dipilih tidak aktif atau bukan milik toko ini.');
            }
            $count = $this->one($pdo,
                'SELECT COUNT(*) AS active_users FROM users
                  WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
                    (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
                [$accountId]);
            if ($tenant['max_users'] !== null && (int) ($count['active_users'] ?? 0) >= (int) $tenant['max_users']) {
                throw new \RuntimeException('Batas akun aktif paket sudah tercapai. Nonaktifkan akun lama atau upgrade paket.');
            }
            $existing = $this->one($pdo, 'SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
            if ($existing !== null) {
                throw new \RuntimeException('Email sudah digunakan oleh akun lain.');
            }
            $pdo->prepare(
                'INSERT INTO users (tenant_id, branch_id, name, email, password_hash, role)
                 VALUES (?, ?, ?, ?, ?, \'cashier\')'
            )->execute([$tenantId, $branchId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $pdo->commit();
            $this->flash('success', 'Akun kasir berhasil dibuat. Berikan email dan kata sandi kepada pegawai secara aman.');
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[owner] create cashier gagal: ' . $e->getMessage());
            $this->flash('danger', 'Akun kasir gagal dibuat. Silakan coba lagi.');
        }
        $this->back();
    }

    public function toggleCashier(): void
    {
        $tenantId = (int) Auth::tenantId();
        $ownerId = (int) Auth::userId();
        $cashierId = (int) Request::post('user_id');
        if ($tenantId <= 0 || $ownerId <= 0 || $cashierId <= 0) {
            $this->flash('danger', 'Permintaan akun tidak valid.');
            $this->back();
        }

        $pdo = Database::pdo();
        try {
            $accountId = $this->ensureOwnerAccount($pdo, $ownerId);
            $pdo->beginTransaction();
            $tenant = $this->one($pdo,
                'SELECT t.id, oa.status, oa.subscription_ends_at, p.max_users
                   FROM owner_accounts oa JOIN plans p ON p.id = oa.plan_id
                   JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id
                   JOIN tenants t ON t.id = oat.tenant_id
                  WHERE oa.id = ? AND t.id = ? AND t.deleted_at IS NULL FOR UPDATE',
                [$accountId, $tenantId]);
            if ($tenant === null) {
                throw new \RuntimeException('Toko tidak ditemukan.');
            }
            $this->requireActiveOwner($pdo, $tenantId, $ownerId);
            $cashier = $this->one($pdo,
                'SELECT id, is_active FROM users WHERE id = ? AND tenant_id = ? AND role = \'cashier\' AND deleted_at IS NULL FOR UPDATE',
                [$cashierId, $tenantId]);
            if ($cashier === null) {
                throw new \RuntimeException('Akun kasir tidak ditemukan di toko ini.');
            }
            $newActive = (int) $cashier['is_active'] === 1 ? 0 : 1;
            if ($newActive === 1) {
                if (!in_array($tenant['status'], ['trial', 'active'], true)) {
                    throw new \RuntimeException('Toko tidak aktif. Akun kasir tidak dapat diaktifkan.');
                }
                if ($tenant['subscription_ends_at'] !== null
                    && new DateTimeImmutable((string) $tenant['subscription_ends_at']) <= new DateTimeImmutable('now')) {
                    throw new \RuntimeException('Masa langganan toko telah berakhir.');
                }
                $count = $this->one($pdo,
                    'SELECT COUNT(*) AS active_users FROM users
                      WHERE is_active = 1 AND deleted_at IS NULL AND tenant_id IN
                        (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)',
                    [$accountId]);
                if ($tenant['max_users'] !== null && (int) ($count['active_users'] ?? 0) >= (int) $tenant['max_users']) {
                    throw new \RuntimeException('Batas akun aktif paket sudah tercapai. Upgrade paket untuk menambah akun.');
                }
            }
            $pdo->prepare('UPDATE users SET is_active = ? WHERE id = ? AND tenant_id = ? AND role = \'cashier\'')
                ->execute([$newActive, $cashierId, $tenantId]);
            $pdo->commit();
            $this->flash('success', $newActive === 1
                ? 'Akun kasir diaktifkan. Kasir dapat masuk kembali dengan email dan kata sandinya.'
                : 'Akun kasir dinonaktifkan. Akses kasirnya akan ditolak.');
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[owner] toggle cashier gagal: ' . $e->getMessage());
            $this->flash('danger', 'Status akun kasir gagal diubah. Silakan coba lagi.');
        }
        $this->back();
    }

    public function resetCashierPassword(): void
    {
        $tenantId = (int) Auth::tenantId();
        $ownerId = (int) Auth::userId();
        $cashierId = (int) Request::post('user_id');
        $password = (string) Request::post('password');
        $confirmation = (string) Request::post('password_confirmation');
        if ($tenantId <= 0 || $ownerId <= 0 || $cashierId <= 0
            || strlen($password) < 10 || strlen($password) > 72
            || !hash_equals($password, $confirmation)) {
            $this->flash('danger', 'Kata sandi baru wajib 10-72 karakter dan konfirmasinya harus cocok.');
            $this->back();
        }

        $pdo = Database::pdo();
        try {
            $this->ensureOwnerAccount($pdo, $ownerId);
            $pdo->beginTransaction();
            $tenant = $this->one($pdo, 'SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$tenantId]);
            if ($tenant === null) {
                throw new \RuntimeException('Toko tidak ditemukan.');
            }
            $this->requireActiveOwner($pdo, $tenantId, $ownerId);
            $cashier = $this->one($pdo,
                'SELECT id FROM users WHERE id = ? AND tenant_id = ? AND role = \'cashier\' AND deleted_at IS NULL FOR UPDATE',
                [$cashierId, $tenantId]);
            if ($cashier === null) {
                throw new \RuntimeException('Akun kasir tidak ditemukan di toko ini.');
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ? AND role = \'cashier\'')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $cashierId, $tenantId]);
            $pdo->commit();
            $this->flash('success', 'Kata sandi kasir diperbarui. Sesi yang sedang aktif akan diminta login kembali.');
        } catch (\RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $this->flash('danger', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[owner] reset cashier password gagal: ' . $e->getMessage());
            $this->flash('danger', 'Kata sandi kasir gagal diperbarui. Silakan coba lagi.');
        }
        $this->back();
    }

    private function flash(string $type, string $message): void
    {
        $_SESSION['owner_flash'] = ['type' => $type, 'message' => $message];
    }

    private function back(string $path = '/owner/team'): void
    {
        Response::redirect(Router::base() . $path);
    }

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : (int) preg_match_all('/./us', $value);
    }

    private function requireActiveOwner(PDO $pdo, int $tenantId, int $ownerId): void
    {
        $owner = $this->one($pdo,
            'SELECT u.id FROM users u
               JOIN owner_accounts oa ON oa.owner_user_id = u.id
               JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id
              WHERE u.id = ? AND oat.tenant_id = ? AND u.role = \'owner\' AND u.is_active = 1 AND u.deleted_at IS NULL',
            [$ownerId, $tenantId]);
        if ($owner === null) {
            throw new \RuntimeException('Akun pemilik tidak aktif atau tidak berwenang.');
        }
    }

    private function ensureOwnerAccount(PDO $pdo, int $ownerId): int
    {
        if (!$this->tableExists($pdo, 'owner_accounts')) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS owner_accounts (
               id INT UNSIGNED NOT NULL AUTO_INCREMENT,
               owner_user_id INT UNSIGNED NOT NULL,
               plan_id INT UNSIGNED NOT NULL,
               status ENUM(\'trial\',\'active\',\'suspended\',\'expired\') NOT NULL DEFAULT \'trial\',
               subscription_ends_at DATETIME NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
               PRIMARY KEY (id),
               UNIQUE KEY uq_owner_accounts_user (owner_user_id),
               CONSTRAINT fk_owner_accounts_user FOREIGN KEY (owner_user_id) REFERENCES users (id),
               CONSTRAINT fk_owner_accounts_plan FOREIGN KEY (plan_id) REFERENCES plans (id)
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }
        if (!$this->tableExists($pdo, 'owner_account_tenants')) {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS owner_account_tenants (
               owner_account_id INT UNSIGNED NOT NULL,
               tenant_id INT UNSIGNED NOT NULL,
               created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
               PRIMARY KEY (owner_account_id, tenant_id),
               UNIQUE KEY uq_owner_account_tenant (tenant_id),
               CONSTRAINT fk_owner_account_tenants_account FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id) ON DELETE CASCADE,
               CONSTRAINT fk_owner_account_tenants_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
                 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
            );
        }

        $seedAccounts = $pdo->prepare(
            'INSERT INTO owner_accounts (owner_user_id, plan_id, status, subscription_ends_at)
             SELECT u.id, t.plan_id, t.status, t.subscription_ends_at
               FROM users u JOIN tenants t ON t.id = u.tenant_id
              WHERE u.id = ? AND u.role = \'owner\' AND u.deleted_at IS NULL
             ON DUPLICATE KEY UPDATE owner_user_id = VALUES(owner_user_id)'
        );
        $seedAccounts->execute([$ownerId]);
        $seedMemberships = $pdo->prepare(
            'INSERT IGNORE INTO owner_account_tenants (owner_account_id, tenant_id)
             SELECT oa.id, u.tenant_id FROM owner_accounts oa
               JOIN users u ON u.id = oa.owner_user_id
              WHERE oa.owner_user_id = ? AND u.tenant_id IS NOT NULL'
        );
        $seedMemberships->execute([$ownerId]);

        $account = $this->one($pdo, 'SELECT id FROM owner_accounts WHERE owner_user_id = ?', [$ownerId]);
        if ($account === null) {
            throw new \RuntimeException('Akun bisnis pemilik tidak tersedia.');
        }
        return (int) $account['id'];
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $statement->execute([$table]);
        return $statement->fetchColumn() !== false;
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

    private function decode($json): array
    {
        if (is_array($json)) {
            return array_values(array_filter($json, 'is_string'));
        }
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
