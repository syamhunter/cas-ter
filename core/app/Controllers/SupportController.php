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
use PDO;
use Throwable;

final class SupportController
{
    private const CATEGORIES = [
        'technical' => 'Teknis',
        'billing' => 'Billing / pembayaran',
        'account' => 'Akun',
        'other' => 'Lainnya',
    ];
    private const STATUSES = [
        'open' => 'Baru',
        'in_progress' => 'Diproses',
        'waiting_owner' => 'Menunggu owner',
        'resolved' => 'Selesai',
    ];

    public function ownerInbox(): void
    {
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        $context = $this->ownerContext($pdo);
        $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
        if ($status !== '' && !isset(self::STATUSES[$status])) $status = '';
        $where = 'st.owner_account_id = ?';
        $params = [$context['accountId']];
        if ($status !== '') {
            $where .= ' AND st.status = ?';
            $params[] = $status;
        }
        $count = $pdo->prepare('SELECT COUNT(*) FROM support_tickets st WHERE ' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = $this->pageNumber();
        $perPage = 25;
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pageCount);
        $offset = ($page - 1) * $perPage;
        $tickets = $pdo->prepare(
            'SELECT st.*, t.name AS tenant_name,
                    (SELECT sm.body FROM support_messages sm WHERE sm.ticket_id = st.id ORDER BY sm.id DESC LIMIT 1) AS last_message,
                    (SELECT sm.sender_role FROM support_messages sm WHERE sm.ticket_id = st.id ORDER BY sm.id DESC LIMIT 1) AS last_sender_role
               FROM support_tickets st JOIN tenants t ON t.id = st.tenant_id
              WHERE ' . $where . ' ORDER BY st.updated_at DESC, st.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $tickets->execute($params);
        $unread = $pdo->prepare('SELECT COUNT(*) FROM support_tickets WHERE owner_account_id = ? AND last_message_at > COALESCE(owner_read_at, \'1970-01-01 00:00:00\')');
        $unread->execute([$context['accountId']]);
        $this->renderOwner('owner/support', 'support', 'Bantuan & Tiket', $context + [
            'tickets' => $tickets->fetchAll(), 'statusFilter' => $status, 'page' => $page,
            'pageCount' => $pageCount, 'total' => $total, 'unreadCount' => (int) $unread->fetchColumn(),
            'categories' => self::CATEGORIES, 'statuses' => self::STATUSES,
            'invoices' => $this->ownerInvoices($pdo, $context['billingKey']),
        ]);
    }

    public function createOwnerTicket(): void
    {
        $category = Request::post('category') ?? '';
        $subject = trim((string) Request::post('subject'));
        $body = trim((string) Request::post('body'));
        $invoiceRaw = Request::post('invoice_id');
        $invoiceId = $invoiceRaw === null || $invoiceRaw === '' ? null : filter_var($invoiceRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!isset(self::CATEGORIES[$category]) || $this->textLength($subject) < 5 || $this->textLength($subject) > 150
            || $this->textLength($body) < 10 || $this->textLength($body) > 5000 || $invoiceId === false) {
            $this->ownerFlash('danger', 'Periksa kategori, subjek (5–150 karakter), dan pesan (10–5000 karakter).');
            $this->back('/owner/support');
        }
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        $context = $this->ownerContext($pdo);
        if ($invoiceId !== null) {
            $invoice = $pdo->prepare("SELECT id FROM subscription_invoices WHERE id = ? AND billing_key = ? AND status <> 'void'");
            $invoice->execute([(int) $invoiceId, $context['billingKey']]);
            if (!$invoice->fetchColumn()) {
                $this->ownerFlash('danger', 'Invoice tidak ditemukan pada akun Anda.');
                $this->back('/owner/support');
            }
        }
        try {
            $pdo->beginTransaction();
            $ticketNo = 'TKT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $pdo->prepare(
                'INSERT INTO support_tickets (ticket_no, owner_account_id, tenant_id, invoice_id, category, subject, status, last_message_at, owner_read_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, \'open\', NOW(6), NOW(6), ?)'
            )->execute([$ticketNo, $context['accountId'], $context['tenant']['id'], $invoiceId, $category, $subject, Auth::userId()]);
            $ticketId = (int) $pdo->lastInsertId();
            $this->addMessage($pdo, $ticketId, Auth::userId(), 'owner', $body);
            $pdo->commit();
            $this->ownerFlash('success', 'Tiket berhasil dikirim. Balasan admin akan muncul di percakapan ini.');
            $this->back('/owner/support/ticket?id=' . $ticketId);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[support] create owner ticket gagal: ' . $e->getMessage());
            $this->ownerFlash('danger', 'Tiket gagal dibuat. Silakan coba lagi.');
            $this->back('/owner/support');
        }
    }

    public function ownerTicketDetail(): void
    {
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        $context = $this->ownerContext($pdo);
        $ticketId = $this->queryId();
        $ticket = $this->ownerTicket($pdo, $ticketId, $context['accountId']);
        if (!$ticket) Response::page(404, 'Tiket tidak ditemukan', 'Tiket ini tidak tersedia pada akun Anda.');
        $pdo->prepare('UPDATE support_tickets SET owner_read_at = NOW(6) WHERE id = ?')->execute([$ticketId]);
        $messages = $this->messages($pdo, $ticketId);
        $this->renderOwner('owner/support-ticket', 'support', 'Tiket ' . $ticket['ticket_no'], $context + [
            'ticket' => $ticket, 'messages' => $messages, 'statuses' => self::STATUSES,
        ]);
    }

    public function ownerReply(): void
    {
        $ticketId = $this->postId('ticket_id');
        $body = trim((string) Request::post('body'));
        if ($this->textLength($body) < 2 || $this->textLength($body) > 5000) {
            $this->ownerFlash('danger', 'Balasan harus 2–5000 karakter.');
            $this->back('/owner/support/ticket?id=' . $ticketId);
        }
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        $context = $this->ownerContext($pdo);
        try {
            $pdo->beginTransaction();
            $ticket = $this->ownerTicket($pdo, $ticketId, $context['accountId'], true);
            if (!$ticket) throw new \RuntimeException('Tiket tidak ditemukan pada akun Anda.');
            $this->addMessage($pdo, $ticketId, Auth::userId(), 'owner', $body);
            $pdo->prepare("UPDATE support_tickets SET status = CASE WHEN status IN ('resolved','waiting_owner') THEN 'open' ELSE status END, last_message_at = NOW(6), owner_read_at = NOW(6), admin_read_at = NULL WHERE id = ?")
                ->execute([$ticketId]);
            $pdo->commit();
            $this->ownerFlash('success', 'Balasan terkirim.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[support] owner reply gagal: ' . $e->getMessage());
            $this->ownerFlash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Balasan gagal dikirim.');
        }
        $this->back('/owner/support/ticket?id=' . $ticketId);
    }

    public function adminInbox(): void
    {
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        $status = isset($_GET['status']) && is_string($_GET['status']) ? $_GET['status'] : '';
        if ($status !== '' && !isset(self::STATUSES[$status])) $status = '';
        $category = isset($_GET['category']) && is_string($_GET['category']) ? $_GET['category'] : '';
        if ($category !== '' && !isset(self::CATEGORIES[$category])) $category = '';
        $conditions = [];
        $params = [];
        if ($status !== '') { $conditions[] = 'st.status = ?'; $params[] = $status; }
        if ($category !== '') { $conditions[] = 'st.category = ?'; $params[] = $category; }
        $where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
        $count = $pdo->prepare('SELECT COUNT(*) FROM support_tickets st' . $where);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $page = $this->pageNumber();
        $perPage = 25;
        $pageCount = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pageCount);
        $offset = ($page - 1) * $perPage;
        $hasInvoices = $this->tableExists($pdo, 'subscription_invoices');
        $invoiceSelect = $hasInvoices ? 'i.invoice_no' : 'NULL AS invoice_no';
        $invoiceJoin = $hasInvoices ? ' LEFT JOIN subscription_invoices i ON i.id = st.invoice_id' : '';
        $tickets = $pdo->prepare(
            'SELECT st.*, t.name AS tenant_name, u.name AS owner_name, u.email AS owner_email,
                ' . $invoiceSelect . ',
                    (SELECT sm.body FROM support_messages sm WHERE sm.ticket_id = st.id ORDER BY sm.id DESC LIMIT 1) AS last_message,
                    (SELECT sm.sender_role FROM support_messages sm WHERE sm.ticket_id = st.id ORDER BY sm.id DESC LIMIT 1) AS last_sender_role
               FROM support_tickets st JOIN owner_accounts oa ON oa.id = st.owner_account_id
               JOIN users u ON u.id = oa.owner_user_id JOIN tenants t ON t.id = st.tenant_id
               ' . $invoiceJoin . $where . '
              ORDER BY (st.last_message_at > COALESCE(st.admin_read_at, \'1970-01-01 00:00:00\')) DESC,
                       st.updated_at DESC, st.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
        );
        $tickets->execute($params);
        $unread = (int) $pdo->query('SELECT COUNT(*) FROM support_tickets WHERE last_message_at > COALESCE(admin_read_at, \'1970-01-01 00:00:00\')')->fetchColumn();
        $activeCount = (int) $pdo->query("SELECT COUNT(*) FROM support_tickets WHERE status <> 'resolved'")->fetchColumn();
        $this->renderAdmin('admin/tickets', 'support', 'Dukungan Owner', [
            'tickets' => $tickets->fetchAll(), 'statuses' => self::STATUSES, 'categories' => self::CATEGORIES,
            'statusFilter' => $status, 'categoryFilter' => $category, 'page' => $page,
            'pageCount' => $pageCount, 'total' => $total, 'unreadCount' => $unread, 'statusesCount' => $activeCount,
        ]);
    }

    public function adminTicketDetail(): void
    {
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        $ticketId = $this->queryId();
        $ticket = $this->adminTicket($pdo, $ticketId, true);
        if (!$ticket) Response::page(404, 'Tiket tidak ditemukan', 'Tiket tidak tersedia.');
        $pdo->prepare('UPDATE support_tickets SET admin_read_at = NOW(6) WHERE id = ?')->execute([$ticketId]);
        $this->renderAdmin('admin/ticket', 'support', 'Tiket ' . $ticket['ticket_no'], [
            'ticket' => $ticket, 'messages' => $this->messages($pdo, $ticketId),
            'statuses' => self::STATUSES, 'categories' => self::CATEGORIES,
        ]);
    }

    public function adminReply(): void
    {
        $ticketId = $this->postId('ticket_id', '/admin/support');
        $body = trim((string) Request::post('body'));
        if ($this->textLength($body) < 2 || $this->textLength($body) > 5000) {
            $this->adminFlash('danger', 'Balasan harus 2–5000 karakter.');
            $this->back('/admin/support/ticket?id=' . $ticketId);
        }
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        try {
            $pdo->beginTransaction();
            if (!$this->adminTicket($pdo, $ticketId, true)) throw new \RuntimeException('Tiket tidak ditemukan.');
            $this->addMessage($pdo, $ticketId, Auth::userId(), 'super_admin', $body);
            $pdo->prepare("UPDATE support_tickets SET status = 'waiting_owner', last_message_at = NOW(6), admin_read_at = NOW(6) WHERE id = ?")
                ->execute([$ticketId]);
            $pdo->commit();
            $this->adminFlash('success', 'Balasan terkirim ke tiket.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[support] admin reply gagal: ' . $e->getMessage());
            $this->adminFlash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Balasan gagal dikirim.');
        }
        $this->back('/admin/support/ticket?id=' . $ticketId);
    }

    public function adminUpdateStatus(): void
    {
        $ticketId = $this->postId('ticket_id', '/admin/support');
        $status = Request::post('status') ?? '';
        if (!isset(self::STATUSES[$status])) {
            $this->adminFlash('danger', 'Status tiket tidak valid.');
            $this->back('/admin/support/ticket?id=' . $ticketId);
        }
        $pdo = Database::pdo();
        $this->ensureSchema($pdo);
        try {
            $pdo->beginTransaction();
            $ticket = $this->adminTicket($pdo, $ticketId, true);
            if (!$ticket) throw new \RuntimeException('Tiket tidak ditemukan.');
            if ($ticket['status'] === $status) {
                $pdo->commit();
                $this->adminFlash('info', 'Status tiket tidak berubah.');
                $this->back('/admin/support/ticket?id=' . $ticketId);
            }
            $pdo->prepare('UPDATE support_tickets SET status = ?, last_message_at = NOW(6), admin_read_at = NOW(6) WHERE id = ?')
                ->execute([$status, $ticketId]);
            $this->addMessage($pdo, $ticketId, Auth::userId(), 'super_admin', 'Status tiket diperbarui: ' . self::STATUSES[$status] . '.');
            $pdo->prepare('UPDATE support_tickets SET admin_read_at = NOW(6) WHERE id = ?')->execute([$ticketId]);
            $pdo->commit();
            $this->adminFlash('success', 'Status tiket diperbarui dan owner mendapat notifikasi di inbox.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[support] update status gagal: ' . $e->getMessage());
            $this->adminFlash('danger', $e instanceof \RuntimeException ? $e->getMessage() : 'Status tiket gagal diperbarui.');
        }
        $this->back('/admin/support/ticket?id=' . $ticketId);
    }

    private function ownerContext(PDO $pdo): array
    {
        $account = $pdo->prepare('SELECT id FROM owner_accounts WHERE owner_user_id = ?');
        $account->execute([Auth::userId()]);
        $accountId = (int) $account->fetchColumn();
        if ($accountId <= 0) Response::page(403, 'Akun tidak ditemukan', 'Akun owner tidak memiliki billing account.');
        $tenantId = (int) Auth::tenantId();
        $tenant = $pdo->prepare('SELECT t.id, t.name, t.owner_name, t.email, oa.status, oa.subscription_ends_at, p.name AS plan_name, p.price_monthly, p.code AS plan_code
             FROM tenants t JOIN owner_account_tenants oat ON oat.tenant_id = t.id
             JOIN owner_accounts oa ON oa.id = oat.owner_account_id JOIN plans p ON p.id = oa.plan_id
            WHERE oat.owner_account_id = ? AND t.id = ? AND t.deleted_at IS NULL');
        $tenant->execute([$accountId, $tenantId]);
        $tenantRow = $tenant->fetch();
        if (!$tenantRow) Response::page(403, 'Usaha tidak ditemukan', 'Usaha aktif tidak terhubung ke akun owner.');
        $branch = $pdo->prepare('SELECT id, name FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL');
        $branch->execute([(int) Auth::branchId(), $tenantId]);
        $branchRow = $branch->fetch();
        if (!$branchRow) Response::page(403, 'Cabang tidak ditemukan', 'Cabang aktif tidak tersedia.');
        $businesses = $pdo->prepare('SELECT t.id, t.name FROM owner_account_tenants oat JOIN tenants t ON t.id = oat.tenant_id WHERE oat.owner_account_id = ? AND t.deleted_at IS NULL ORDER BY t.name');
        $businesses->execute([$accountId]);
        return [
            'base' => Router::base(), 'csrf' => Csrf::token(), 'ownerName' => Auth::name(),
            'pdo' => $pdo,
            'tenant' => $tenantRow, 'branch' => $branchRow, 'businesses' => $businesses->fetchAll(),
            'accountId' => $accountId, 'billingKey' => 'owner-' . $accountId,
        ];
    }

    private function ownerInvoices(PDO $pdo, string $billingKey): array
    {
        if (!$this->tableExists($pdo, 'subscription_invoices')) return [];
        $statement = $pdo->prepare("SELECT id, invoice_no, amount, status, due_at FROM subscription_invoices WHERE billing_key = ? AND status <> 'void' ORDER BY id DESC LIMIT 25");
        $statement->execute([$billingKey]);
        return $statement->fetchAll();
    }

    private function ownerTicket(PDO $pdo, int $ticketId, int $accountId, bool $lock = false): ?array
    {
        $invoiceJoin = $this->tableExists($pdo, 'subscription_invoices')
            ? ' LEFT JOIN subscription_invoices i ON i.id = st.invoice_id'
            : '';
        $invoiceSelect = $invoiceJoin !== '' ? 'i.invoice_no' : 'NULL AS invoice_no';
        $statement = $pdo->prepare(
            'SELECT st.*, t.name AS tenant_name, ' . $invoiceSelect . ' FROM support_tickets st
               JOIN tenants t ON t.id = st.tenant_id' . $invoiceJoin . '
              WHERE st.id = ? AND st.owner_account_id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$ticketId, $accountId]);
        $ticket = $statement->fetch();
        return $ticket ?: null;
    }

    private function adminTicket(PDO $pdo, int $ticketId, bool $lock = false): ?array
    {
        $invoiceJoin = $this->tableExists($pdo, 'subscription_invoices')
            ? ' LEFT JOIN subscription_invoices i ON i.id = st.invoice_id'
            : '';
        $invoiceSelect = $invoiceJoin !== '' ? 'i.invoice_no' : 'NULL AS invoice_no';
        $statement = $pdo->prepare(
            'SELECT st.*, t.name AS tenant_name, oa.owner_user_id, u.name AS owner_name, u.email AS owner_email, ' . $invoiceSelect . '
               FROM support_tickets st JOIN owner_accounts oa ON oa.id = st.owner_account_id
               JOIN users u ON u.id = oa.owner_user_id JOIN tenants t ON t.id = st.tenant_id
               ' . $invoiceJoin . '
              WHERE st.id = ?' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute([$ticketId]);
        $ticket = $statement->fetch();
        return $ticket ?: null;
    }

    private function messages(PDO $pdo, int $ticketId): array
    {
        $statement = $pdo->prepare('SELECT sm.*, u.name AS sender_name FROM support_messages sm JOIN users u ON u.id = sm.sender_user_id WHERE sm.ticket_id = ? ORDER BY sm.id');
        $statement->execute([$ticketId]);
        return $statement->fetchAll();
    }

    private function addMessage(PDO $pdo, int $ticketId, int $userId, string $role, string $body): void
    {
        $pdo->prepare('INSERT INTO support_messages (ticket_id, sender_user_id, sender_role, body) VALUES (?, ?, ?, ?)')
            ->execute([$ticketId, $userId, $role, $body]);
        $pdo->prepare('UPDATE support_tickets SET last_message_at = NOW(6), updated_at = NOW(6) WHERE id = ?')->execute([$ticketId]);
    }

    private function renderOwner(string $view, string $active, string $title, array $data): void
    {
        $unread = $data['pdo']->prepare('SELECT COUNT(*) FROM support_tickets WHERE owner_account_id = ? AND last_message_at > COALESCE(owner_read_at, \'1970-01-01 00:00:00\')');
        $unread->execute([(int) $data['accountId']]);
        $data['supportUnread'] = (int) $unread->fetchColumn();
        $body = $this->capture($view, $data + ['categories' => self::CATEGORIES, 'statuses' => self::STATUSES]);
        $flash = $_SESSION['owner_flash'] ?? null;
        unset($_SESSION['owner_flash']);
        View::render('owner/layout', $data + [
            'activePage' => $active, 'pageTitle' => $title, 'body' => $body,
            'ownerFlash' => is_array($flash) ? $flash : null,
        ]);
    }

    private function renderAdmin(string $view, string $active, string $title, array $data): void
    {
        $unread = Database::pdo()->query('SELECT COUNT(*) FROM support_tickets WHERE last_message_at > COALESCE(admin_read_at, \'1970-01-01 00:00:00\')');
        $data['ticketUnread'] = (int) $unread->fetchColumn();
        $viewData = array_merge(['base' => Router::base(), 'csrf' => Csrf::token()], $data, [
            'categories' => self::CATEGORIES,
            'statuses' => self::STATUSES,
        ]);
        $body = $this->capture($view, $viewData);
        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);
        View::render('admin/layout', $data + [
            'base' => Router::base(), 'csrf' => Csrf::token(), 'adminName' => Auth::name(),
            'flash' => is_array($flash) ? $flash : null, 'activePage' => $active,
            'pageTitle' => $title, 'body' => $body,
        ]);
    }

    private function capture(string $view, array $data): string
    {
        ob_start();
        try { View::render($view, $data); return (string) ob_get_contents(); }
        finally { ob_end_clean(); }
    }

    private function ensureSchema(PDO $pdo): void
    {
        $hasOwnerSchema = $this->tableExists($pdo, 'owner_accounts');
        $hasInvoiceSchema = $this->tableExists($pdo, 'subscription_invoices');
        $accountConstraint = $hasOwnerSchema ? ', CONSTRAINT fk_support_ticket_owner FOREIGN KEY (owner_account_id) REFERENCES owner_accounts (id)' : '';
        $invoiceConstraint = $hasInvoiceSchema ? ', CONSTRAINT fk_support_ticket_invoice FOREIGN KEY (invoice_id) REFERENCES subscription_invoices (id)' : '';
        $pdo->exec('CREATE TABLE IF NOT EXISTS support_tickets (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_no VARCHAR(40) NOT NULL,
            owner_account_id INT UNSIGNED NOT NULL,
            tenant_id INT UNSIGNED NOT NULL,
            invoice_id BIGINT UNSIGNED NULL,
            category ENUM(\'technical\',\'billing\',\'account\',\'other\') NOT NULL DEFAULT \'other\',
            subject VARCHAR(150) NOT NULL,
            status ENUM(\'open\',\'in_progress\',\'waiting_owner\',\'resolved\') NOT NULL DEFAULT \'open\',
            last_message_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            owner_read_at DATETIME(6) NULL,
            admin_read_at DATETIME(6) NULL,
            created_by INT UNSIGNED NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id), UNIQUE KEY uq_support_ticket_no (ticket_no), KEY idx_support_ticket_owner (owner_account_id, updated_at),
            KEY idx_support_ticket_admin (status, updated_at), KEY idx_support_ticket_invoice (invoice_id),
            CONSTRAINT fk_support_ticket_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id)' . $accountConstraint . $invoiceConstraint . ',
            CONSTRAINT fk_support_ticket_user FOREIGN KEY (created_by) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $pdo->exec('CREATE TABLE IF NOT EXISTS support_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            sender_user_id INT UNSIGNED NOT NULL,
            sender_role ENUM(\'owner\',\'super_admin\') NOT NULL,
            body TEXT NOT NULL,
            created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
            PRIMARY KEY (id), KEY idx_support_message_ticket (ticket_id, id),
            CONSTRAINT fk_support_message_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets (id) ON DELETE CASCADE,
            CONSTRAINT fk_support_message_user FOREIGN KEY (sender_user_id) REFERENCES users (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $statement->execute([$table]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function pageNumber(): int
    {
        $page = Request::query('page');
        $page = $page === null ? 1 : filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $page === false ? 1 : (int) $page;
    }

    private function queryId(): int
    {
        $id = Request::query('id');
        $id = $id === null ? false : filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) Response::page(404, 'Tiket tidak ditemukan', 'Tiket tidak valid.');
        return (int) $id;
    }

    private function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : (int) preg_match_all('/./us', $value);
    }

    private function postId(string $key, string $returnPath = '/owner/support'): int
    {
        $id = filter_var(Request::post($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            if (strpos($returnPath, '/admin/') === 0) $this->adminFlash('danger', 'Tiket tidak valid.');
            else $this->ownerFlash('danger', 'Tiket tidak valid.');
            $this->back($returnPath);
        }
        return (int) $id;
    }

    private function ownerFlash(string $type, string $message): void { $_SESSION['owner_flash'] = ['type' => $type, 'message' => $message]; }
    private function adminFlash(string $type, string $message): void { $_SESSION['admin_flash'] = ['type' => $type, 'msg' => $message]; }

    private function back(string $path): void { Response::redirect(Router::base() . $path); }
}