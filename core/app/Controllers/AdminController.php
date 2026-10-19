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
use PDO;
use Throwable;

/** Panel Super Admin: pelanggan (tenant), paket, status & masa langganan. */
final class AdminController
{
    private const MODULES = ['retail' => 'Retail', 'fnb' => 'Cafe & Resto', 'pharmacy' => 'Apotek', 'laundry' => 'Laundry', 'printing' => 'Percetakan', 'service' => 'Jasa'];

    private function flash(string $type, string $msg): void
    {
        $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
    }

    private function back(string $path = '/admin'): void
    {
        Response::redirect(Router::base() . $path);
    }

    public function index(): void
    {
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $hasOwnerAccounts = $this->ownerAccountSchemaAvailable($pdo);
        $accountJoins = $hasOwnerAccounts
            ? 'LEFT JOIN owner_account_tenants oat ON oat.tenant_id = t.id
               LEFT JOIN owner_accounts oa ON oa.id = oat.owner_account_id'
            : '';
        $status = $hasOwnerAccounts ? 'COALESCE(oa.status, t.status)' : 't.status';
        $summaryStatement = $pdo->query(
            'SELECT ' . $status . ' AS status, COUNT(DISTINCT t.id) AS total
               FROM tenants t ' . $accountJoins . '
              WHERE t.deleted_at IS NULL GROUP BY ' . $status
        );
        $summary = ['trial' => 0, 'active' => 0, 'suspended' => 0, 'expired' => 0];
        foreach ($summaryStatement->fetchAll() as $row) {
            if (isset($summary[$row['status']])) {
                $summary[$row['status']] = (int) $row['total'];
            }
        }
        $summary['total'] = array_sum($summary);
        $summary['plans'] = (int) $pdo->query('SELECT COUNT(*) FROM plans WHERE is_active = 1')->fetchColumn();
                $effectiveEnd = $hasOwnerAccounts ? 'COALESCE(oa.subscription_ends_at, t.subscription_ends_at)' : 't.subscription_ends_at';
                $expiringStatement = $pdo->query(
                        'SELECT COUNT(DISTINCT t.id) FROM tenants t ' . $accountJoins . '
                            WHERE t.deleted_at IS NULL AND ' . $status . " IN ('trial','active')
                                AND " . $effectiveEnd . ' >= NOW() AND ' . $effectiveEnd . ' < DATE_ADD(NOW(), INTERVAL 14 DAY)'
                );
                $summary['expiring'] = (int) $expiringStatement->fetchColumn();
        $activity = $pdo->query(
            'SELECT al.action_code, al.entity_type, al.entity_id, al.details, al.created_at, u.name AS admin_name
               FROM admin_activity_logs al JOIN users u ON u.id = al.admin_user_id
              ORDER BY al.id DESC LIMIT 8'
        )->fetchAll();
        $this->renderPage('admin/dashboard', 'dashboard', 'Ringkasan', [
            'summary' => $summary,
            'activity' => $activity,
        ]);
    }

    public function tenantsPage(): void
    {
        $pdo = Database::pdo();
        $plans = $pdo->query('SELECT id, name, price_monthly, is_active FROM plans ORDER BY price_monthly')->fetchAll();
        $hasOwnerAccounts = $this->ownerAccountSchemaAvailable($pdo);
        $accountJoins = $hasOwnerAccounts
            ? 'LEFT JOIN owner_account_tenants oat ON oat.tenant_id = t.id
               LEFT JOIN owner_accounts oa ON oa.id = oat.owner_account_id'
            : '';
        $planId = $hasOwnerAccounts ? 'COALESCE(oa.plan_id, t.plan_id)' : 't.plan_id';
        $status = $hasOwnerAccounts ? 'COALESCE(oa.status, t.status)' : 't.status';
        $endsAt = $hasOwnerAccounts ? 'COALESCE(oa.subscription_ends_at, t.subscription_ends_at)' : 't.subscription_ends_at';
        $search = isset($_GET['q']) && is_string($_GET['q']) ? trim(substr($_GET['q'], 0, 120)) : '';
        $selectedStatus = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
        if (!in_array($selectedStatus, ['trial', 'active', 'suspended', 'expired'], true)) {
            $selectedStatus = '';
        }
        $selectedPlan = isset($_GET['plan_id']) && is_string($_GET['plan_id'])
            ? filter_var($_GET['plan_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        $selectedPlan = $selectedPlan === false ? 0 : (int) $selectedPlan;
        $conditions = ['t.deleted_at IS NULL'];
        $params = [];
        if ($search !== '') {
            $conditions[] = '(t.name LIKE ? OR t.owner_name LIKE ? OR t.email LIKE ?)';
            $term = '%' . $search . '%';
            array_push($params, $term, $term, $term);
        }
        if ($selectedStatus !== '') {
            $conditions[] = $status . ' = ?';
            $params[] = $selectedStatus;
        }
        if ($selectedPlan > 0) {
            $conditions[] = $planId . ' = ?';
            $params[] = $selectedPlan;
        }
        $where = implode(' AND ', $conditions);
        $countStatement = $pdo->prepare('SELECT COUNT(DISTINCT t.id) FROM tenants t ' . $accountJoins . ' WHERE ' . $where);
        $countStatement->execute($params);
        $tenantCount = (int) $countStatement->fetchColumn();
        $perPage = 25;
        $pageCount = max(1, (int) ceil($tenantCount / $perPage));
        $page = isset($_GET['page']) && is_string($_GET['page'])
            ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : 1;
        $page = min($page === false ? 1 : (int) $page, $pageCount);
        $offset = ($page - 1) * $perPage;
        $usage = $hasOwnerAccounts
            ? 'CASE WHEN oa.id IS NOT NULL THEN
                    (SELECT COUNT(*) FROM transactions x
                      JOIN owner_account_tenants xot ON xot.tenant_id = x.tenant_id
                     WHERE xot.owner_account_id = oa.id AND x.created_at >= ? AND x.created_at < ?)
                 ELSE
                    (SELECT COUNT(*) FROM transactions x
                     WHERE x.tenant_id = t.id AND x.created_at >= ? AND x.created_at < ?)
                 END'
            : '(SELECT COUNT(*) FROM transactions x WHERE x.tenant_id = t.id AND x.created_at >= ? AND x.created_at < ?)';
        $st = $pdo->prepare(
            'SELECT t.id, t.name, t.owner_name, t.email, t.created_at, ' . $status . ' AS status, ' . $endsAt . ' AS subscription_ends_at,
                    ' . $planId . ' AS current_plan_id, p.name AS plan_name,
                (SELECT COUNT(*) FROM branches b WHERE b.tenant_id = t.id AND b.is_active = 1 AND b.deleted_at IS NULL) AS branch_count,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.is_active = 1 AND u.deleted_at IS NULL) AS user_count,
                    (SELECT GROUP_CONCAT(m.module_code ORDER BY m.module_code SEPARATOR ", ") FROM tenant_modules m WHERE m.tenant_id = t.id AND m.is_active = 1) AS modules,
                    ' . $usage . ' AS trx_month
               FROM tenants t ' . $accountJoins . '
               JOIN plans p ON p.id = ' . $planId . '
              WHERE ' . $where . ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $monthStart = date('Y-m-01 00:00:00');
        $nextMonth = date('Y-m-01 00:00:00', strtotime('first day of next month'));
        $st->execute(array_merge($hasOwnerAccounts
            ? [$monthStart, $nextMonth, $monthStart, $nextMonth]
            : [$monthStart, $nextMonth], $params));
        $this->renderPage('admin/tenants', 'tenants', 'Pelanggan', [
            'plans' => $plans,
            'tenants' => $st->fetchAll(),
            'modules' => self::MODULES,
            'search' => $search,
            'selectedStatus' => $selectedStatus,
            'selectedPlan' => $selectedPlan,
            'page' => $page,
            'pageCount' => $pageCount,
            'tenantCount' => $tenantCount,
        ]);
    }

    public function plansPage(): void
    {
        $plans = Database::pdo()->query(
            'SELECT id, code, name, price_monthly, max_branches, max_users, max_transactions_month, allowed_modules, is_active
               FROM plans ORDER BY price_monthly, id'
        )->fetchAll();
        $this->renderPage('admin/plans', 'plans', 'Paket', ['plans' => $plans, 'modules' => self::MODULES]);
    }

    public function activityPage(): void
    {
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $action = isset($_GET['action']) && is_string($_GET['action']) ? substr(trim($_GET['action']), 0, 60) : '';
        $conditions = [];
        $params = [];
        if ($action !== '') {
            $conditions[] = 'al.action_code = ?';
            $params[] = $action;
        }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $totalStatement = $pdo->prepare('SELECT COUNT(*) FROM admin_activity_logs al' . $where);
        $totalStatement->execute($params);
        $total = (int) $totalStatement->fetchColumn();
        $perPage = 50;
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = isset($_GET['page']) && is_string($_GET['page'])
            ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : 1;
        $page = min($page === false ? 1 : (int) $page, $pageCount);
        $offset = ($page - 1) * $perPage;
        $statement = $pdo->prepare(
            'SELECT al.action_code, al.entity_type, al.entity_id, al.details, al.ip_address, al.created_at, u.name AS admin_name
               FROM admin_activity_logs al JOIN users u ON u.id = al.admin_user_id' . $where . '
              ORDER BY al.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($params);
        $this->renderPage('admin/activity', 'activity', 'Aktivitas Admin', [
            'activity' => $statement->fetchAll(), 'action' => $action, 'page' => $page,
            'pageCount' => $pageCount, 'total' => $total,
        ]);
    }

    public function billingPage(): void
    {
        $pdo = Database::pdo();
        $this->ensureInvoiceSchema($pdo);
        $this->ensureBillingSchema($pdo);
        (new BillingController())->ensureProofSchema($pdo);
        $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
        if (!in_array($status, ['unpaid', 'paid', 'overdue', 'void'], true)) $status = '';
        $statusSql = "CASE WHEN i.status = 'unpaid' AND i.due_at < CURRENT_DATE THEN 'overdue' ELSE i.status END";
        $where = $status !== '' ? ' WHERE ' . $statusSql . ' = ?' : '';
        $params = $status !== '' ? [$status] : [];
        $page = isset($_GET['page']) && is_string($_GET['page'])
            ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : 1;
        $page = $page === false ? 1 : (int) $page;
        $perPage = 50;
        $count = $pdo->prepare('SELECT COUNT(*) FROM subscription_invoices i' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pageCount);
        $offset = ($page - 1) * $perPage;
        $statement = $pdo->prepare(
            'SELECT i.*, ' . $statusSql . ' AS display_status, t.name AS tenant_name, t.email AS tenant_email,
                    u.name AS created_by_name, ip.paid_at, ip.payment_method, ip.reference, payuser.name AS paid_by_name
               FROM subscription_invoices i JOIN tenants t ON t.id = i.tenant_id
               JOIN users u ON u.id = i.created_by
               LEFT JOIN invoice_payments ip ON ip.invoice_id = i.id
               LEFT JOIN users payuser ON payuser.id = ip.recorded_by' . $where . '
              ORDER BY i.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $statement->execute($params);
        $invoices = $statement->fetchAll();
        $summary = $pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
                    SUM(CASE WHEN status = 'unpaid' AND due_at >= CURRENT_DATE THEN 1 ELSE 0 END) AS unpaid_count,
                    SUM(CASE WHEN status = 'unpaid' AND due_at < CURRENT_DATE THEN 1 ELSE 0 END) AS overdue_count,
                    COALESCE(SUM(CASE WHEN status IN ('unpaid','overdue') THEN amount ELSE 0 END), 0) AS outstanding
               FROM subscription_invoices WHERE status <> 'void'"
        )->fetch();
        $legacyPayments = $pdo->query(
            'SELECT sp.*, t.name AS tenant_name, u.name AS admin_name
               FROM subscription_payments sp JOIN tenants t ON t.id = sp.tenant_id
               JOIN users u ON u.id = sp.recorded_by ORDER BY sp.id DESC LIMIT 20'
        )->fetchAll();
        $proofs = $pdo->query(
            "SELECT pp.*, i.invoice_no, t.name AS tenant_name, u.name AS owner_name, u.email AS owner_email
               FROM invoice_payment_proofs pp JOIN subscription_invoices i ON i.id = pp.invoice_id
               JOIN owner_accounts oa ON oa.id = pp.owner_account_id JOIN users u ON u.id = oa.owner_user_id
               JOIN tenants t ON t.id = i.tenant_id
              WHERE pp.status = 'pending' ORDER BY pp.created_at ASC LIMIT 50"
        )->fetchAll();
        $this->renderPage('admin/billing', 'billing', 'Billing', [
            'invoices' => $invoices, 'total' => $total, 'summary' => $summary,
            'legacyPayments' => $legacyPayments, 'proofs' => $proofs,
            'page' => $page, 'pageCount' => $pageCount, 'selectedStatus' => $status,
        ]);
    }

    public function supportPage(): void
    {
        $pdo = Database::pdo();
        $search = isset($_GET['q']) && is_string($_GET['q']) ? trim(substr($_GET['q'], 0, 120)) : '';
        $where = "u.role = 'owner' AND u.deleted_at IS NULL";
        $params = [];
        if ($search !== '') {
            $where .= ' AND (u.name LIKE ? OR u.email LIKE ? OR t.name LIKE ?)';
            $term = '%' . $search . '%';
            $params = [$term, $term, $term];
        }
        $joinAccounts = $this->ownerAccountSchemaAvailable($pdo)
            ? 'LEFT JOIN owner_accounts oa ON oa.owner_user_id = u.id LEFT JOIN owner_account_tenants oat ON oat.owner_account_id = oa.id'
            : '';
        $joinTenant = $joinAccounts === ''
            ? 'LEFT JOIN tenants t ON t.id = u.tenant_id'
            : 'LEFT JOIN tenants t ON t.id = COALESCE(oat.tenant_id, u.tenant_id)';
        $from = ' FROM users u ' . $joinAccounts . ' ' . $joinTenant . ' WHERE ' . $where;
        $count = $pdo->prepare('SELECT COUNT(DISTINCT u.id)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $perPage = 50;
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = isset($_GET['page']) && is_string($_GET['page'])
            ? filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : 1;
        $page = min($page === false ? 1 : (int) $page, $pageCount);
        $offset = ($page - 1) * $perPage;
        $owners = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.is_active, MAX(t.name) AS tenant_name, COUNT(DISTINCT t.id) AS tenant_count' . $from .
            ' GROUP BY u.id, u.name, u.email, u.is_active ORDER BY u.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $owners->execute($params);
        $this->renderPage('admin/support', 'accounts', 'Akun Owner', [
            'owners' => $owners->fetchAll(), 'search' => $search, 'page' => $page,
            'pageCount' => $pageCount, 'total' => $total,
        ]);
    }

    public function tenantDetail(): void
    {
        $tenantId = Request::query('id');
        $tenantId = $tenantId === null ? false : filter_var($tenantId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tenantId === false) {
            Response::page(404, '404', 'Usaha tidak ditemukan.');
        }
        $pdo = Database::pdo();
        $hasOwnerAccounts = $this->ownerAccountSchemaAvailable($pdo);
        $accountJoin = $hasOwnerAccounts
            ? 'LEFT JOIN owner_account_tenants oat ON oat.tenant_id = t.id LEFT JOIN owner_accounts oa ON oa.id = oat.owner_account_id'
            : '';
        $effectiveStatus = $hasOwnerAccounts ? 'COALESCE(oa.status, t.status)' : 't.status';
        $effectiveEnd = $hasOwnerAccounts ? 'COALESCE(oa.subscription_ends_at, t.subscription_ends_at)' : 't.subscription_ends_at';
        $statement = $pdo->prepare(
            'SELECT t.*, ' . $effectiveStatus . ' AS effective_status, ' . $effectiveEnd . ' AS effective_ends_at,
                    p.name AS plan_name, p.price_monthly, p.max_branches, p.max_users, p.max_transactions_month,
                    (SELECT COUNT(*) FROM branches b WHERE b.tenant_id = t.id AND b.is_active = 1 AND b.deleted_at IS NULL) AS branch_count,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.is_active = 1 AND u.deleted_at IS NULL) AS user_count
               FROM tenants t ' . $accountJoin . ' JOIN plans p ON p.id = ' . ($hasOwnerAccounts ? 'COALESCE(oa.plan_id, t.plan_id)' : 't.plan_id') . '
              WHERE t.id = ? AND t.deleted_at IS NULL'
        );
        $statement->execute([(int) $tenantId]);
        $tenant = $statement->fetch();
        if (!$tenant) {
            Response::page(404, '404', 'Usaha tidak ditemukan.');
        }
        $branches = $pdo->prepare('SELECT id, code, name, is_active FROM branches WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY id');
        $branches->execute([(int) $tenantId]);
        $this->ensureInvoiceSchema($pdo);
        $billingKey = $this->billingKeyForTenant($pdo, (int) $tenantId);
        $history = $pdo->prepare(
            "SELECT i.*, CASE WHEN i.status = 'unpaid' AND i.due_at < CURRENT_DATE THEN 'overdue' ELSE i.status END AS display_status,
                    ip.paid_at, ip.payment_method, ip.reference
               FROM subscription_invoices i LEFT JOIN invoice_payments ip ON ip.invoice_id = i.id
              WHERE i.billing_key = ? ORDER BY i.id DESC LIMIT 20"
        );
        $history->execute([$billingKey]);
        $invoices = $history->fetchAll();
        $periodStart = date('Y-m-d');
        if (!empty($tenant['effective_ends_at']) && substr((string) $tenant['effective_ends_at'], 0, 10) >= $periodStart) {
            $periodStart = (new DateTimeImmutable((string) $tenant['effective_ends_at']))->modify('+1 day')->format('Y-m-d');
        }
        $periodEnd = (new DateTimeImmutable($periodStart))->modify('+1 month')->modify('-1 day')->format('Y-m-d');
        $this->renderPage('admin/tenant-detail', 'tenants', 'Detail Usaha', [
            'tenant' => $tenant, 'branches' => $branches->fetchAll(), 'invoices' => $invoices,
            'periodStart' => $periodStart, 'periodEnd' => $periodEnd, 'dueDate' => date('Y-m-d', strtotime('+7 days')),
        ]);
    }

    private function renderPage(string $bodyView, string $activePage, string $title, array $data = []): void
    {
        $data = array_merge(['base' => Router::base(), 'csrf' => Csrf::token()], $data);
        $ticketTable = Database::pdo()->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'support_tickets'");
        $ticketTable->execute();
        $ticketUnread = (int) $ticketTable->fetchColumn() === 1
            ? (int) Database::pdo()->query('SELECT COUNT(*) FROM support_tickets WHERE last_message_at > COALESCE(admin_read_at, \'1970-01-01 00:00:00\')')->fetchColumn()
            : 0;
        $body = '';
        ob_start();
        try {
            View::render($bodyView, $data);
            $body = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        View::render('admin/layout', array_merge($data, [
            'adminName' => Auth::name(), 'flash' => $flash,
            'activePage' => $activePage, 'pageTitle' => $title, 'body' => $body, 'ticketUnread' => $ticketUnread,
        ]));
    }

    public function createTenant(): void
    {
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $name = trim((string) Request::post('name'));
        $ownerName = trim((string) Request::post('owner_name'));
        $email = strtolower(trim((string) Request::post('email')));
        $password = (string) Request::post('password');
        $planId = (int) Request::post('plan_id');
        $status = Request::post('status') === 'active' ? 'active' : 'trial';
        $days = max(1, min(3650, (int) (Request::post('days') ?: 14)));
        $branchName = trim((string) Request::post('branch_name')) ?: 'Cabang Utama';
        $branchCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) Request::post('branch_code')) ?? '') ?: 'PST';
        $taxPct = max(0.0, min(100.0, (float) Request::post('tax_pct')));
        $taxMode = Request::post('tax_mode');
        $svcPct = max(0.0, min(100.0, (float) Request::post('service_pct')));
        $mods = array_values(array_intersect(array_keys(self::MODULES), (array) ($_POST['modules'] ?? [])));

        $e = [];
        if (!in_array($taxMode, ['none', 'exclusive', 'inclusive'], true)) $e[] = 'Cara hitung pajak tidak valid.';
        if (mb_strlen($name) < 3 || mb_strlen($name) > 150) $e[] = 'Nama usaha 3-150 karakter.';
        if (mb_strlen($ownerName) < 3) $e[] = 'Nama pemilik minimal 3 karakter.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $e[] = 'Email tidak valid.';
        if (strlen($password) < 8 || strlen($password) > 72) $e[] = 'Kata sandi 8-72 karakter.';
        if (!$mods) $e[] = 'Pilih minimal satu modul.';
        if (strlen($branchCode) > 10) $e[] = 'Kode cabang maks 10 karakter.';
        $plan = $pdo->prepare('SELECT id, allowed_modules FROM plans WHERE id = ? AND is_active = 1');
        $plan->execute([$planId]);
        $planRow = $plan->fetch();
        if (!$planRow) {
            $e[] = 'Paket tidak valid atau non-aktif.';
        } elseif ($planRow['allowed_modules'] !== null) {
            $allowed = json_decode((string) $planRow['allowed_modules'], true) ?: [];
            if (array_diff($mods, $allowed)) $e[] = 'Ada modul yang tidak termasuk dalam paket.';
        }
        $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
        $dup->execute([$email]);
        if ($dup->fetchColumn()) $e[] = 'Email sudah terdaftar sebagai pengguna.';
        if ($e) {
            $this->flash('danger', implode(' ', $e));
            $this->back('/admin/tenants');
        }

        try {
            $pdo->beginTransaction();
            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
            $slug = ($slug !== '' ? substr($slug, 0, 80) : 'toko') . '-' . bin2hex(random_bytes(2));
            $pdo->prepare('INSERT INTO tenants (plan_id, name, slug, owner_name, email, status, subscription_ends_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$planId, $name, $slug, $ownerName, $email, $status, date('Y-m-d H:i:s', strtotime("+{$days} days"))]);
            $tid = (int) $pdo->lastInsertId();

            $m = $pdo->prepare('INSERT INTO tenant_modules (tenant_id, module_code, is_active, settings) VALUES (?, ?, 1, ?)');
            foreach ($mods as $code) {
                $m->execute([$tid, $code, $code === 'fnb' ? json_encode(['service_charge_pct' => $svcPct]) : null]);
            }
            $pdo->prepare('INSERT INTO branches (tenant_id, code, name, settings) VALUES (?, ?, ?, ?)')
                ->execute([$tid, $branchCode, $branchName, json_encode(['tax_pct' => $taxPct, 'tax_mode' => $taxMode])]);
            $bid = (int) $pdo->lastInsertId();
            $pdo->prepare('INSERT INTO users (tenant_id, branch_id, name, email, password_hash, role) VALUES (?, NULL, ?, ?, ?, ?)')
                ->execute([$tid, $ownerName, $email, password_hash($password, PASSWORD_DEFAULT), 'owner']);
            if (in_array('fnb', $mods, true)) {
                $t = $pdo->prepare('INSERT INTO dining_tables (tenant_id, branch_id, name, capacity) VALUES (?, ?, ?, 4)');
                for ($i = 1; $i <= 5; $i++) {
                    $t->execute([$tid, $bid, 'Meja ' . $i]);
                }
            }
            $this->recordAdminAction($pdo, 'tenant.created', 'tenant', $tid, ['name' => $name, 'email' => $email, 'plan_id' => $planId, 'status' => $status]);
            $pdo->commit();
            $this->flash('success', "Tenant \"{$name}\" dibuat. Pemilik login dengan {$email}.");
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] createTenant: ' . $ex->getMessage());
            $this->flash('danger', 'Gagal membuat tenant. Periksa log server.');
        }
        $this->back('/admin/tenants');
    }

    public function toggleTenant(): void
    {
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $id = (int) Request::post('tenant_id');
        $st = $pdo->prepare('SELECT status FROM tenants WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            $this->flash('danger', 'Tenant tidak ditemukan.');
            $this->back('/admin/tenants');
        }
        $new = in_array($cur, ['active', 'trial'], true) ? 'suspended' : 'active';
        $pdo->beginTransaction();
        try {
            $accountId = $this->ownerAccountIdForTenant($pdo, $id);
            if ($accountId !== null) {
                $pdo->prepare('UPDATE owner_accounts SET status = ? WHERE id = ?')->execute([$new, $accountId]);
                $pdo->prepare(
                    'UPDATE tenants SET status = ? WHERE id IN
                      (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)'
                )->execute([$new, $accountId]);
            } else {
                $pdo->prepare('UPDATE tenants SET status = ? WHERE id = ?')->execute([$new, $id]);
            }
            $this->recordAdminAction($pdo, 'tenant.status_changed', 'tenant', $id, ['from' => $cur, 'to' => $new]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] toggleTenant gagal: ' . $e->getMessage());
            $this->flash('danger', 'Status langganan gagal diperbarui.');
            $this->back('/admin/tenants');
        }
        $this->flash('success', 'Status tenant diubah menjadi ' . $new . '.');
        $this->back('/admin/tenants');
    }

    public function changeTenantPlan(): void
    {
        $tenantId = filter_var(Request::post('tenant_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $planId = filter_var(Request::post('plan_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($tenantId === false || $planId === false) {
            $this->flash('danger', 'Usaha atau paket yang dipilih tidak valid.');
            $this->back('/admin/tenants');
        }

        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        try {
            $pdo->beginTransaction();
            $tenant = $pdo->prepare('SELECT id FROM tenants WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $tenant->execute([$tenantId]);
            if (!$tenant->fetchColumn()) {
                throw new \RuntimeException('Usaha tidak ditemukan.');
            }

            $plan = $pdo->prepare('SELECT id, name FROM plans WHERE id = ? AND is_active = 1 FOR UPDATE');
            $plan->execute([$planId]);
            $planRow = $plan->fetch();
            if (!$planRow) {
                throw new \RuntimeException('Paket tidak tersedia atau sudah nonaktif.');
            }

            $accountId = $this->ownerAccountIdForTenant($pdo, (int) $tenantId);
            if ($accountId !== null) {
                $account = $pdo->prepare('SELECT id FROM owner_accounts WHERE id = ? FOR UPDATE');
                $account->execute([$accountId]);
                if (!$account->fetchColumn()) {
                    throw new \RuntimeException('Akun pemilik tidak ditemukan; paket tidak diubah.');
                }
                $pdo->prepare('UPDATE owner_accounts SET plan_id = ? WHERE id = ?')
                    ->execute([$planId, $accountId]);
                $pdo->prepare(
                    'UPDATE tenants SET plan_id = ? WHERE id IN
                      (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)'
                )->execute([$planId, $accountId]);
            } else {
                $pdo->prepare('UPDATE tenants SET plan_id = ? WHERE id = ?')
                    ->execute([$planId, $tenantId]);
            }
            $this->recordAdminAction($pdo, 'tenant.plan_changed', 'tenant', (int) $tenantId, ['plan_id' => (int) $planId, 'plan_name' => $planRow['name']]);
            $pdo->commit();
            $this->flash('success', 'Paket usaha berhasil diubah menjadi ' . $planRow['name'] . '.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] changeTenantPlan gagal: ' . $e->getMessage());
            $this->flash('danger', $e instanceof \RuntimeException
                ? $e->getMessage()
                : 'Paket gagal diubah. Periksa log server.');
        }
        $this->back('/admin/tenants');
    }

    public function extendTenant(): void
    {
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $id = (int) Request::post('tenant_id');
        $days = max(1, min(3650, (int) Request::post('days')));
        $st = $pdo->prepare('SELECT subscription_ends_at FROM tenants WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            $this->flash('danger', 'Tenant tidak ditemukan.');
            $this->back('/admin/tenants/detail?id=' . $id);
        }
        $now = new DateTimeImmutable('now');
        $cur = $row['subscription_ends_at'] !== null ? new DateTimeImmutable((string) $row['subscription_ends_at']) : $now;
        $from = $cur > $now ? $cur : $now;
        $new = $from->modify("+{$days} days")->format('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $accountId = $this->ownerAccountIdForTenant($pdo, $id);
            if ($accountId !== null) {
                $pdo->prepare("UPDATE owner_accounts SET subscription_ends_at = ?, status = 'active' WHERE id = ?")
                    ->execute([$new, $accountId]);
                $pdo->prepare(
                    "UPDATE tenants SET subscription_ends_at = ?, status = 'active' WHERE id IN
                      (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)"
                )->execute([$new, $accountId]);
            } else {
                $pdo->prepare("UPDATE tenants SET subscription_ends_at = ?, status = 'active' WHERE id = ?")->execute([$new, $id]);
            }
            $this->recordAdminAction($pdo, 'tenant.subscription_extended', 'tenant', $id, ['days' => $days, 'subscription_ends_at' => $new]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] extendTenant gagal: ' . $e->getMessage());
            $this->flash('danger', 'Langganan gagal diperpanjang.');
            $this->back('/admin/tenants/detail?id=' . $id);
        }
        $this->flash('success', "Langganan diperpanjang {$days} hari (sampai {$new}).");
        $this->back('/admin/tenants/detail?id=' . $id);
    }

    public function savePlan(): void
    {
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $rawId = Request::post('plan_id');
        $planId = $rawId === null || $rawId === '' ? 0 : filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $code = strtolower(trim((string) Request::post('code')));
        $name = trim((string) Request::post('name'));
        $rawPrice = Request::post('price_monthly');
        $price = is_string($rawPrice) && is_numeric($rawPrice) ? (float) $rawPrice : -1;
        $requestedModules = is_array($_POST['allowed_modules'] ?? null) ? $_POST['allowed_modules'] : [];
        $modules = array_values(array_intersect(array_keys(self::MODULES), $requestedModules));
        $errors = [];

        if ($planId === false) $errors[] = 'Paket tidak valid.';
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $code)) $errors[] = 'Kode paket harus 2-40 karakter, berisi huruf kecil, angka, - atau _.';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) $errors[] = 'Nama paket harus 2-100 karakter.';
        if ($price < 0 || $price > 9999999999999.99) $errors[] = 'Harga bulanan tidak valid.';
        if (!$modules) $errors[] = 'Pilih minimal satu modul untuk paket.';

        $quotas = [];
        foreach ([
            'max_branches' => ['Cabang', 65535],
            'max_users' => ['Pengguna', 65535],
            'max_transactions_month' => ['Transaksi bulanan', 4294967295],
        ] as $field => [$label, $maximum]) {
            $raw = Request::post($field);
            if ($raw === null || $raw === '') {
                $quotas[$field] = null;
                continue;
            }
            $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $maximum]]);
            if ($value === false) {
                $errors[] = "Batas {$label} harus angka positif atau kosong untuk tanpa batas.";
            } else {
                $quotas[$field] = (int) $value;
            }
        }

        $duplicate = $pdo->prepare('SELECT id FROM plans WHERE code = ? AND id <> ?');
        $duplicate->execute([$code, $planId ?: 0]);
        if ($duplicate->fetchColumn()) $errors[] = 'Kode paket sudah digunakan.';
        if ($errors) {
            $this->flash('danger', implode(' ', $errors));
            $this->back('/admin/plans');
        }

        $values = [$code, $name, $price, $quotas['max_branches'], $quotas['max_users'], $quotas['max_transactions_month'], json_encode($modules)];
        try {
            $pdo->beginTransaction();
            if ($planId > 0) {
                $statement = $pdo->prepare('UPDATE plans SET code = ?, name = ?, price_monthly = ?, max_branches = ?, max_users = ?, max_transactions_month = ?, allowed_modules = ? WHERE id = ?');
                $statement->execute(array_merge($values, [$planId]));
                $entityId = (int) $planId;
                $message = 'Paket berhasil diperbarui.';
            } else {
                $statement = $pdo->prepare('INSERT INTO plans (code, name, price_monthly, max_branches, max_users, max_transactions_month, allowed_modules, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
                $statement->execute($values);
                $entityId = (int) $pdo->lastInsertId();
                $message = 'Paket berhasil ditambahkan.';
            }
            $this->recordAdminAction($pdo, $planId > 0 ? 'plan.updated' : 'plan.created', 'plan', $entityId, ['code' => $code, 'name' => $name, 'price_monthly' => $price, 'modules' => $modules]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] savePlan gagal: ' . $e->getMessage());
            $this->flash('danger', 'Paket gagal disimpan. Periksa log server.');
            $this->back('/admin/plans');
        }
        $this->flash('success', $message);
        $this->back('/admin/plans');
    }

    public function togglePlan(): void
    {
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $planId = filter_var(Request::post('plan_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($planId === false) {
            $this->flash('danger', 'Paket tidak valid.');
            $this->back('/admin/plans');
        }
        $current = $pdo->prepare('SELECT is_active, code FROM plans WHERE id = ?');
        $current->execute([(int) $planId]);
        $plan = $current->fetch();
        if (!$plan) {
            $this->flash('danger', 'Paket tidak ditemukan.');
            $this->back('/admin/plans');
        }
        $newStatus = (int) $plan['is_active'] === 1 ? 0 : 1;
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE plans SET is_active = ? WHERE id = ?')->execute([$newStatus, (int) $planId]);
            $this->recordAdminAction($pdo, 'plan.status_changed', 'plan', (int) $planId, ['code' => $plan['code'], 'is_active' => $newStatus]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] togglePlan gagal: ' . $e->getMessage());
            $this->flash('danger', 'Status paket gagal diperbarui.');
            $this->back('/admin/plans');
        }
        $this->flash('success', 'Status paket diperbarui.');
        $this->back('/admin/plans');
    }

    public function createInvoice(): void
    {
        $tenantId = filter_var(Request::post('tenant_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $periodStart = $this->validDate(Request::post('period_start'));
        $periodEnd = $this->validDate(Request::post('period_end'));
        $dueAt = $this->validDate(Request::post('due_at'));
        if ($tenantId === false || $periodStart === null || $periodEnd === null || $periodEnd < $periodStart
            || $dueAt === null || $dueAt < date('Y-m-d')) {
            $this->flash('danger', 'Pelanggan, periode invoice, atau tanggal jatuh tempo tidak valid.');
            $this->back('/admin/tenants');
        }

        $pdo = Database::pdo();
        $this->ensureInvoiceSchema($pdo);
        (new BillingController())->ensureProofSchema($pdo);
        $this->ensureAdminAuditSchema($pdo);
        $detailPath = '/admin/tenants/detail?id=' . (int) $tenantId;
        try {
            $pdo->beginTransaction();
            $tenant = $pdo->prepare('SELECT id, plan_id FROM tenants WHERE id = ? AND deleted_at IS NULL FOR UPDATE');
            $tenant->execute([(int) $tenantId]);
            $tenantRow = $tenant->fetch();
            if (!$tenantRow) throw new \RuntimeException('Pelanggan tidak ditemukan.');

            $accountId = $this->ownerAccountIdForTenant($pdo, (int) $tenantId);
            $billingKey = $this->billingKeyForTenant($pdo, (int) $tenantId, $accountId);
            if ($accountId !== null) {
                $account = $pdo->prepare('SELECT plan_id FROM owner_accounts WHERE id = ? FOR UPDATE');
                $account->execute([$accountId]);
                $planId = (int) $account->fetchColumn();
            } else {
                $planId = (int) $tenantRow['plan_id'];
            }
            $plan = $pdo->prepare('SELECT id, name, price_monthly FROM plans WHERE id = ?');
            $plan->execute([$planId]);
            $planRow = $plan->fetch();
            if (!$planRow || (float) $planRow['price_monthly'] <= 0) {
                throw new \RuntimeException('Paket gratis atau paket tidak ditemukan tidak memerlukan invoice.');
            }
            $duplicate = $pdo->prepare(
                                "SELECT id FROM subscription_invoices
                                    WHERE billing_key = ? AND period_start <= ? AND period_end >= ? AND status <> 'void' LIMIT 1 FOR UPDATE"
            );
                        $duplicate->execute([$billingKey, $periodEnd, $periodStart]);
                        if ($duplicate->fetchColumn()) throw new \RuntimeException('Periode invoice bertumpang tindih dengan invoice aktif pada akun billing ini.');

            $invoiceNo = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $pdo->prepare(
                'INSERT INTO subscription_invoices
                    (invoice_no, billing_key, tenant_id, owner_account_id, plan_id, plan_name, amount, period_start, period_end, issued_at, due_at, grace_until, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'unpaid\', ?)'
            )->execute([
                $invoiceNo, $billingKey, (int) $tenantId, $accountId, $planId, $planRow['name'],
                $planRow['price_monthly'], $periodStart, $periodEnd, date('Y-m-d'), $dueAt,
                (new DateTimeImmutable($dueAt))->modify('+7 days')->format('Y-m-d'), Auth::userId(),
            ]);
            $invoiceId = (int) $pdo->lastInsertId();
            $this->recordAdminAction($pdo, 'billing.invoice_created', 'subscription_invoice', $invoiceId, [
                'invoice_no' => $invoiceNo, 'billing_key' => $billingKey, 'tenant_id' => (int) $tenantId,
                'amount' => (float) $planRow['price_monthly'], 'period_start' => $periodStart,
                'period_end' => $periodEnd, 'due_at' => $dueAt,
                'grace_until' => (new DateTimeImmutable($dueAt))->modify('+7 days')->format('Y-m-d'),
            ]);
            $pdo->commit();
            $this->flash('success', 'Invoice ' . $invoiceNo . ' berhasil dibuat.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] createInvoice gagal: ' . $e->getMessage());
            $this->flash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Invoice gagal dibuat.');
        }
        $this->back($detailPath);
    }

    public function payInvoice(): void
    {
        $invoiceId = filter_var(Request::post('invoice_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $paidAt = $this->validDate(Request::post('paid_at'));
        $method = Request::post('payment_method') ?? '';
        $reference = trim((string) Request::post('reference'));
        $notes = trim((string) Request::post('notes'));
        if ($invoiceId === false || $paidAt === null || $paidAt > date('Y-m-d')
            || !in_array($method, ['bank_transfer', 'qris', 'cash', 'other'], true)
            || $reference === '' || strlen($reference) > 120 || strlen($notes) > 255) {
            $this->flash('danger', 'Data pembayaran tidak valid. Referensi pembayaran wajib diisi.');
            $this->back('/admin/billing');
        }

        $pdo = Database::pdo();
        $this->ensureInvoiceSchema($pdo);
        $this->ensureAdminAuditSchema($pdo);
        $detailPath = '/admin/billing';
        try {
            $pdo->beginTransaction();
            $lookup = $pdo->prepare('SELECT * FROM subscription_invoices WHERE id = ? FOR UPDATE');
            $lookup->execute([(int) $invoiceId]);
            $invoice = $lookup->fetch();
            if (!$invoice) throw new \RuntimeException('Invoice tidak ditemukan.');
            $detailPath = '/admin/tenants/detail?id=' . (int) $invoice['tenant_id'];
            if ($paidAt < $invoice['issued_at']) {
                throw new \RuntimeException('Tanggal pembayaran tidak boleh sebelum tanggal invoice diterbitkan.');
            }
            if (!in_array($invoice['status'], ['unpaid', 'overdue'], true)) {
                throw new \RuntimeException('Invoice ini tidak dapat dibayar karena statusnya bukan belum lunas.');
            }

            $payment = $pdo->prepare(
                'INSERT INTO invoice_payments (invoice_id, billing_key, amount, paid_at, payment_method, reference, notes, recorded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $payment->execute([
                (int) $invoiceId, $invoice['billing_key'], $invoice['amount'], $paidAt,
                $method, $reference, $notes !== '' ? $notes : null, Auth::userId(),
            ]);
            $pdo->prepare("UPDATE subscription_invoices SET status = 'paid', paid_at = ? WHERE id = ?")
                ->execute([$paidAt, (int) $invoiceId]);
            $pdo->prepare("UPDATE invoice_payment_proofs SET status = 'rejected', reviewer_note = 'Pembayaran dicatat langsung oleh Admin.', reviewed_by = ?, reviewed_at = NOW(6) WHERE invoice_id = ? AND status = 'pending'")
                ->execute([Auth::userId(), (int) $invoiceId]);

            if ($invoice['period_end'] >= date('Y-m-d')) {
                $newEnd = $invoice['period_end'] . ' 23:59:59';
                if ($invoice['owner_account_id'] !== null) {
                    $account = $pdo->prepare('SELECT subscription_ends_at FROM owner_accounts WHERE id = ? FOR UPDATE');
                    $account->execute([(int) $invoice['owner_account_id']]);
                    $currentEnd = $account->fetchColumn();
                    $effectiveEnd = $currentEnd === false || $currentEnd === null || substr((string) $currentEnd, 0, 10) < $invoice['period_end']
                        ? $newEnd
                        : (string) $currentEnd;
                    $pdo->prepare("UPDATE owner_accounts SET subscription_ends_at = ?, status = 'active' WHERE id = ?")
                        ->execute([$effectiveEnd, (int) $invoice['owner_account_id']]);
                    $pdo->prepare(
                        "UPDATE tenants SET subscription_ends_at = ?, status = 'active' WHERE id IN
                          (SELECT tenant_id FROM owner_account_tenants WHERE owner_account_id = ?)"
                    )->execute([$effectiveEnd, (int) $invoice['owner_account_id']]);
                } else {
                    $current = $pdo->prepare('SELECT subscription_ends_at FROM tenants WHERE id = ? FOR UPDATE');
                    $current->execute([(int) $invoice['tenant_id']]);
                    $currentEnd = $current->fetchColumn();
                    $effectiveEnd = $currentEnd === false || $currentEnd === null || substr((string) $currentEnd, 0, 10) < $invoice['period_end']
                        ? $newEnd
                        : (string) $currentEnd;
                    $pdo->prepare("UPDATE tenants SET subscription_ends_at = ?, status = 'active' WHERE id = ?")
                        ->execute([$effectiveEnd, (int) $invoice['tenant_id']]);
                }
            }
            $this->recordAdminAction($pdo, 'billing.invoice_paid', 'subscription_invoice', (int) $invoiceId, [
                'invoice_no' => $invoice['invoice_no'], 'amount' => (float) $invoice['amount'],
                'paid_at' => $paidAt, 'payment_method' => $method, 'reference' => $reference,
            ]);
            $pdo->commit();
            $this->flash('success', 'Invoice ' . $invoice['invoice_no'] . ' ditandai lunas.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] payInvoice gagal: ' . $e->getMessage());
            $this->flash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Pembayaran gagal dicatat. Pastikan referensi belum pernah digunakan.');
        }
        $this->back($detailPath);
    }

    public function invoiceDetail(): void
    {
        $invoiceId = Request::query('id');
        $invoiceId = $invoiceId === null ? false : filter_var($invoiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($invoiceId === false) Response::page(404, '404', 'Invoice tidak ditemukan.');
        $pdo = Database::pdo();
        $this->ensureInvoiceSchema($pdo);
        $statement = $pdo->prepare(
            "SELECT i.*, CASE WHEN i.status = 'unpaid' AND i.due_at < CURRENT_DATE THEN 'overdue' ELSE i.status END AS display_status,
                    t.name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone,
                    ip.paid_at, ip.payment_method, ip.reference
               FROM subscription_invoices i JOIN tenants t ON t.id = i.tenant_id
               LEFT JOIN invoice_payments ip ON ip.invoice_id = i.id WHERE i.id = ?"
        );
        $statement->execute([(int) $invoiceId]);
        $invoice = $statement->fetch();
        if (!$invoice) Response::page(404, '404', 'Invoice tidak ditemukan.');
        $this->renderPage('admin/invoice', 'billing', 'Invoice ' . $invoice['invoice_no'], ['invoice' => $invoice]);
    }

    public function voidInvoice(): void
    {
        $invoiceId = filter_var(Request::post('invoice_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($invoiceId === false) {
            $this->flash('danger', 'Invoice tidak valid.');
            $this->back('/admin/billing');
        }
        $pdo = Database::pdo();
        $this->ensureInvoiceSchema($pdo);
        (new BillingController())->ensureProofSchema($pdo);
        $this->ensureAdminAuditSchema($pdo);
        try {
            $pdo->beginTransaction();
            $lookup = $pdo->prepare('SELECT invoice_no, status FROM subscription_invoices WHERE id = ? FOR UPDATE');
            $lookup->execute([(int) $invoiceId]);
            $invoice = $lookup->fetch();
            if (!$invoice || !in_array($invoice['status'], ['unpaid', 'overdue'], true)) {
                throw new \RuntimeException('Hanya invoice yang belum lunas dapat dibatalkan.');
            }
            $pdo->prepare("UPDATE subscription_invoices SET status = 'void' WHERE id = ?")->execute([(int) $invoiceId]);
            $pdo->prepare("UPDATE invoice_payment_proofs SET status = 'rejected', reviewer_note = 'Invoice dibatalkan oleh Admin.', reviewed_by = ?, reviewed_at = NOW(6) WHERE invoice_id = ? AND status = 'pending'")
                ->execute([Auth::userId(), (int) $invoiceId]);
            $this->recordAdminAction($pdo, 'billing.invoice_voided', 'subscription_invoice', (int) $invoiceId, ['invoice_no' => $invoice['invoice_no']]);
            $pdo->commit();
            $this->flash('success', 'Invoice ' . $invoice['invoice_no'] . ' dibatalkan.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] voidInvoice gagal: ' . $e->getMessage());
            $this->flash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Invoice gagal dibatalkan.');
        }
        $this->back('/admin/billing');
    }

    public function resetOwnerPassword(): void
    {
        $userId = filter_var(Request::post('user_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $password = Request::post('password') ?? '';
        $confirmation = Request::post('password_confirmation') ?? '';
        if ($userId === false || strlen($password) < 12 || strlen($password) > 72 || !hash_equals($password, $confirmation)) {
            $this->flash('danger', 'Akun tidak valid, password harus 12–72 karakter, dan konfirmasi harus sama.');
            $this->back('/admin/accounts');
        }
        $pdo = Database::pdo();
        $this->ensureAdminAuditSchema($pdo);
        $lookup = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ? AND role = 'owner' AND deleted_at IS NULL");
        $lookup->execute([(int) $userId]);
        $owner = $lookup->fetch();
        if (!$owner) {
            $this->flash('danger', 'Akun pemilik tidak ditemukan.');
            $this->back('/admin/accounts');
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND role = 'owner'")
                ->execute([password_hash($password, PASSWORD_DEFAULT), (int) $userId]);
            $this->recordAdminAction($pdo, 'owner.password_reset', 'user', (int) $userId, ['name' => $owner['name'], 'email' => $owner['email']]);
            $pdo->commit();
            $this->flash('success', 'Password owner berhasil direset. Sesi aktif lama akan tidak berlaku.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[admin] resetOwnerPassword gagal: ' . $e->getMessage());
            $this->flash('danger', 'Password gagal direset. Periksa log server.');
        }
        $this->back('/admin/accounts');
    }

    private function validDate(?string $value): ?string
    {
        if ($value === null) return null;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function billingKeyForTenant(PDO $pdo, int $tenantId, ?int $ownerAccountId = null): string
    {
        $ownerAccountId ??= $this->ownerAccountIdForTenant($pdo, $tenantId);
        return $ownerAccountId === null ? 'tenant-' . $tenantId : 'owner-' . $ownerAccountId;
    }

    public function ensureInvoiceSchema(PDO $pdo): void
    {
        $accountConstraint = $this->ownerAccountSchemaAvailable($pdo)
            ? ', CONSTRAINT fk_subscription_invoice_account FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id)'
            : '';
        $pdo->exec('CREATE TABLE IF NOT EXISTS subscription_invoices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_no VARCHAR(40) NOT NULL,
            billing_key VARCHAR(50) NOT NULL,
            tenant_id INT UNSIGNED NOT NULL,
            owner_account_id INT UNSIGNED NULL,
            plan_id INT UNSIGNED NULL,
            plan_name VARCHAR(100) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            issued_at DATE NOT NULL,
            due_at DATE NOT NULL,
            grace_until DATE NOT NULL,
            status ENUM(\'unpaid\',\'paid\',\'overdue\',\'void\') NOT NULL DEFAULT \'unpaid\',
            paid_at DATE NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_subscription_invoice_no (invoice_no),
            KEY idx_subscription_invoice_due (status, due_at),
            KEY idx_subscription_invoice_tenant (tenant_id, created_at),
            CONSTRAINT fk_subscription_invoice_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)' . $accountConstraint . ',
            CONSTRAINT fk_subscription_invoice_plan FOREIGN KEY (plan_id) REFERENCES plans (id),
            CONSTRAINT fk_subscription_invoice_admin FOREIGN KEY (created_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS invoice_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            billing_key VARCHAR(50) NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            paid_at DATE NOT NULL,
            payment_method ENUM(\'bank_transfer\',\'qris\',\'cash\',\'other\') NOT NULL DEFAULT \'bank_transfer\',
            reference VARCHAR(120) NOT NULL,
            notes VARCHAR(255) NULL,
            recorded_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_invoice_payment_invoice (invoice_id),
            UNIQUE KEY uq_invoice_payment_reference (billing_key, reference),
            KEY idx_invoice_payment_date (paid_at),
            CONSTRAINT fk_invoice_payment_invoice FOREIGN KEY (invoice_id) REFERENCES subscription_invoices (id),
            CONSTRAINT fk_invoice_payment_admin FOREIGN KEY (recorded_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function ensureBillingSchema(PDO $pdo): void
    {
        $accountConstraint = $this->ownerAccountSchemaAvailable($pdo)
            ? ', CONSTRAINT fk_subscription_payment_account FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id)'
            : '';
        $pdo->exec('CREATE TABLE IF NOT EXISTS subscription_payments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            tenant_id INT UNSIGNED NOT NULL,
            owner_account_id INT UNSIGNED NULL,
            amount DECIMAL(15,2) NOT NULL,
            paid_at DATE NOT NULL,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            payment_method ENUM(\'bank_transfer\',\'qris\',\'cash\',\'other\') NOT NULL DEFAULT \'bank_transfer\',
            reference VARCHAR(120) NOT NULL,
            notes VARCHAR(255) NULL,
            recorded_by INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_subscription_payment_tenant (tenant_id, paid_at),
            KEY idx_subscription_payment_account (owner_account_id, paid_at),
            UNIQUE KEY uq_subscription_payment_reference (tenant_id, reference),
            CONSTRAINT fk_subscription_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)' . $accountConstraint . ',
            CONSTRAINT fk_subscription_payment_admin FOREIGN KEY (recorded_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
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
            PRIMARY KEY (id),
            KEY idx_admin_activity_time (created_at),
            KEY idx_admin_activity_entity (entity_type, entity_id),
            CONSTRAINT fk_admin_activity_user FOREIGN KEY (admin_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function recordAdminAction(PDO $pdo, string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        $pdo->prepare(
            'INSERT INTO admin_activity_logs (admin_user_id, action_code, entity_type, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            Auth::userId(), $action, $entityType, $entityId,
            $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            Request::ip(),
        ]);
    }

    private function ownerAccountIdForTenant(PDO $pdo, int $tenantId): ?int
    {
        if (!$this->ownerAccountSchemaAvailable($pdo)) {
            return null;
        }
        $statement = $pdo->prepare('SELECT owner_account_id FROM owner_account_tenants WHERE tenant_id = ?');
        $statement->execute([$tenantId]);
        $accountId = $statement->fetchColumn();
        return $accountId === false ? null : (int) $accountId;
    }

    private function ownerAccountSchemaAvailable(PDO $pdo): bool
    {
        $statement = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
              WHERE table_schema = DATABASE()
                AND table_name IN ('owner_accounts', 'owner_account_tenants')"
        );
        return (int) $statement->fetchColumn() === 2;
    }
}
