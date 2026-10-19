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
use PDOException;
use Throwable;

final class RegistrationController
{
    private const FREE_MODULES = ['retail', 'fnb', 'pharmacy', 'laundry', 'printing', 'service'];
    private const FREE_MAX_BRANCHES = 1;
    private const FREE_MAX_USERS = 2;
    private const FREE_MAX_TRANSACTIONS_MONTH = 100;

    private const MODULES = [
        'retail' => ['label' => 'Retail / Toko', 'description' => 'Produk, barcode, dan stok'],
        'fnb' => ['label' => 'Cafe & Resto', 'description' => 'Meja, pesanan, dan service charge'],
        'pharmacy' => ['label' => 'Apotek', 'description' => 'Stok batch dan kedaluwarsa'],
        'laundry' => ['label' => 'Laundry', 'description' => 'Layanan kiloan dan ekspres'],
        'printing' => ['label' => 'Percetakan', 'description' => 'Ukuran, bahan, dan finishing'],
        'service' => ['label' => 'Jasa', 'description' => 'Durasi layanan dan petugas'],
    ];

    public function show(): void
    {
        if (Auth::check()) {
            Response::redirect(Router::base() . '/');
        }
        $this->render([], []);
    }

    public function store(): void
    {
        if (Auth::check()) {
            Response::redirect(Router::base() . '/');
        }

        $input = [
            'owner_name' => trim((string) Request::post('owner_name')),
            'business_name' => trim((string) Request::post('business_name')),
            'email' => strtolower(trim((string) Request::post('email'))),
            'phone' => trim((string) Request::post('phone')),
            'branch_name' => trim((string) Request::post('branch_name')),
            'plan_id' => trim((string) Request::post('plan_id')),
            'primary_module' => trim((string) Request::post('primary_module')),
        ];
        $password = (string) Request::post('password');
        $confirm = (string) Request::post('password_confirmation');
        $extraModules = array_values(array_unique(array_filter(
            array_map(static fn($value): string => is_string($value) ? $value : '', (array) ($_POST['additional_modules'] ?? [])),
            static fn(string $value): bool => $value !== ''
        )));

        $errors = [];
        if ($this->length($input['owner_name']) < 3 || $this->length($input['owner_name']) > 150) {
            $errors['owner_name'] = 'Nama pemilik harus 3-150 karakter.';
        }
        if ($this->length($input['business_name']) < 3 || $this->length($input['business_name']) > 150) {
            $errors['business_name'] = 'Nama usaha harus 3-150 karakter.';
        }
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL) || strlen($input['email']) > 190) {
            $errors['email'] = 'Alamat email tidak valid.';
        }
        if ($input['phone'] !== '' && (strlen($input['phone']) > 30 || !preg_match('/^[0-9+(). -]+$/', $input['phone']))) {
            $errors['phone'] = 'Nomor telepon tidak valid.';
        }
        if (strlen($password) < 10 || strlen($password) > 72) {
            $errors['password'] = 'Kata sandi harus 10-72 karakter.';
        }
        if (!hash_equals($password, $confirm)) {
            $errors['password_confirmation'] = 'Konfirmasi kata sandi tidak cocok.';
        }
        if ($input['branch_name'] !== '' && $this->length($input['branch_name']) > 150) {
            $errors['branch_name'] = 'Nama cabang maksimal 150 karakter.';
        }
        if (!isset(self::MODULES[$input['primary_module']])) {
            $errors['primary_module'] = 'Pilih bidang usaha utama.';
        }
        foreach ($extraModules as $module) {
            if (!isset(self::MODULES[$module])) {
                $errors['additional_modules'] = 'Pilihan bidang usaha tidak valid.';
                break;
            }
        }

        $pdo = Database::pdo();
        $this->ensureFreePlan($pdo);
        $planId = ctype_digit($input['plan_id']) ? (int) $input['plan_id'] : 0;
        $plan = null;
        if ($planId > 0) {
            $st = $pdo->prepare('SELECT id, code, name, price_monthly, max_branches, max_users, max_transactions_month, allowed_modules FROM plans WHERE id = ? AND is_active = 1');
            $st->execute([$planId]);
            $plan = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($plan === null) {
            $errors['plan_id'] = 'Pilih paket yang masih aktif.';
        } else {
            $selectedModules = array_values(array_unique(array_merge([$input['primary_module']], $extraModules)));
            $allowed = $this->decode($plan['allowed_modules']);
            if ($allowed !== [] && array_diff($selectedModules, $allowed)) {
                $errors['plan_id'] = 'Paket yang dipilih belum mencakup bidang usaha tersebut.';
            }
        }

        $check = $pdo->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
        $check->execute([$input['email']]);
        if ($check->fetchColumn()) {
            $errors['email'] = 'Email sudah digunakan. Silakan masuk atau gunakan email lain.';
        }

        if ($errors) {
            $this->render($input, $errors, $extraModules);
            return;
        }

        $modules = array_values(array_unique(array_merge([$input['primary_module']], $extraModules)));
        $branchName = $input['branch_name'] !== '' ? $input['branch_name'] : 'Cabang Utama';
        $branchCode = 'PST';
        $tenantName = $input['business_name'];
        try {
            $pdo->beginTransaction();
            $slugBase = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($tenantName)), '-');
            $slug = substr($slugBase !== '' ? $slugBase : 'toko', 0, 80) . '-' . bin2hex(random_bytes(4));
            $isFree = $plan['code'] === 'free';
            $endsAt = $isFree ? null : (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))
                ->modify('+14 days')->format('Y-m-d H:i:s');
            $tenantStatus = $isFree ? 'active' : 'trial';

            $pdo->prepare(
                'INSERT INTO tenants (plan_id, name, slug, owner_name, email, phone, status, subscription_ends_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $planId, $tenantName, $slug, $input['owner_name'], $input['email'],
                $input['phone'] !== '' ? $input['phone'] : null, $tenantStatus, $endsAt,
            ]);
            $tenantId = (int) $pdo->lastInsertId();

            $moduleInsert = $pdo->prepare('INSERT INTO tenant_modules (tenant_id, module_code, is_active, settings) VALUES (?, ?, 1, ?)');
            foreach ($modules as $module) {
                $moduleInsert->execute([
                    $tenantId,
                    $module,
                    $module === 'fnb' ? json_encode(['service_charge_pct' => 0]) : null,
                ]);
            }

            $pdo->prepare('INSERT INTO branches (tenant_id, code, name, settings) VALUES (?, ?, ?, ?)')
                ->execute([$tenantId, $branchCode, $branchName, json_encode(['tax_pct' => 0, 'tax_mode' => 'exclusive'])]);
            $branchId = (int) $pdo->lastInsertId();

            $ownerPasswordHash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (tenant_id, branch_id, name, email, password_hash, role) VALUES (?, NULL, ?, ?, ?, \'owner\')')
                ->execute([$tenantId, $input['owner_name'], $input['email'], $ownerPasswordHash]);
            $ownerId = (int) $pdo->lastInsertId();

            if (in_array('fnb', $modules, true)) {
                $table = $pdo->prepare('INSERT INTO dining_tables (tenant_id, branch_id, name, capacity) VALUES (?, ?, ?, 4)');
                for ($number = 1; $number <= 5; $number++) {
                    $table->execute([$tenantId, $branchId, 'Meja ' . $number]);
                }
            }

            $pdo->commit();
            Auth::login([
                'id' => $ownerId,
                'tenant_id' => $tenantId,
                'name' => $input['owner_name'],
                'role' => 'owner',
                'password_hash' => $ownerPasswordHash,
            ], $branchId);
            Response::redirect(Router::base() . '/owner');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                $this->render($input, ['email' => 'Email sudah digunakan. Silakan masuk atau gunakan email lain.'], $extraModules);
                return;
            }
            error_log('[registration] database error: ' . $e->getMessage());
            $this->render($input, ['general' => 'Pendaftaran gagal disimpan. Silakan coba lagi.'], $extraModules);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[registration] gagal: ' . $e->getMessage());
            $this->render($input, ['general' => 'Pendaftaran gagal disimpan. Silakan coba lagi.'], $extraModules);
        }
    }

    private function render(array $old, array $errors, array $selectedModules = []): void
    {
        $pdo = Database::pdo();
        $this->ensureFreePlan($pdo);
        $plans = $pdo->query(
            'SELECT id, code, name, price_monthly, max_branches, max_users, max_transactions_month, allowed_modules
               FROM plans WHERE is_active = 1 ORDER BY price_monthly, id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($plans as &$plan) {
            $plan['allowed_modules'] = $this->decode($plan['allowed_modules']);
        }
        unset($plan);
        View::render('auth/register', [
            'csrf' => Csrf::token(),
            'base' => Router::base(),
            'plans' => $plans,
            'modules' => self::MODULES,
            'selectedModules' => $selectedModules,
            'old' => $old,
            'errors' => $errors,
        ]);
    }

    private function ensureFreePlan(PDO $pdo): void
    {
        $modules = json_encode(self::FREE_MODULES, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $st = $pdo->prepare(
            'INSERT INTO plans (code, name, price_monthly, max_branches, max_users, max_transactions_month, allowed_modules)
             VALUES (\'free\', \'Free\', 0, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), price_monthly = VALUES(price_monthly),
               max_branches = VALUES(max_branches), max_users = VALUES(max_users),
               max_transactions_month = VALUES(max_transactions_month), allowed_modules = VALUES(allowed_modules),
               is_active = 1'
        );
        $st->execute([
            self::FREE_MAX_BRANCHES,
            self::FREE_MAX_USERS,
            self::FREE_MAX_TRANSACTIONS_MONTH,
            $modules,
        ]);
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

    private function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value) : (int) preg_match_all('/./us', $value);
    }
}
