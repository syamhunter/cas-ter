<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\DemoSeeder;
use App\Core\Environment;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use PDO;
use Throwable;

/**
 * Installer satu halaman. Hanya aktif selama storage/installed.lock belum ada.
 * Dilindungi KODE INSTALASI (storage/install-code.txt) agar orang lain tidak bisa
 * memasang aplikasi lebih dulu dari Anda setelah file diunggah.
 */
final class InstallController
{
    private function codeFile(): string
    {
        return CORE_PATH . '/storage/install-code.txt';
    }

    private function detectBaseUrl(): string
    {
        $dir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
        return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    }

    public function show(): void
    {
        View::render('install/index', [
            'csrf' => Csrf::token(),
            'errors' => [],
            'old' => ['db_host' => 'localhost', 'db_port' => '3306', 'base_url' => $this->detectBaseUrl(), 'demo' => '1'],
            'codeMissing' => !is_file($this->codeFile()),
            'result' => null,
            'writable' => $this->checks(),
        ]);
    }

    /** @return array<string,bool> */
    private function checks(): array
    {
        return [
            'PHP >= 8.1' => PHP_VERSION_ID >= 80100,
            'ekstensi pdo_mysql' => extension_loaded('pdo_mysql'),
            'ekstensi mbstring (disarankan)' => extension_loaded('mbstring'),
            'folder core dapat ditulis (.env)' => is_writable(CORE_PATH),
            'folder storage dapat ditulis' => is_writable(CORE_PATH . '/storage') && is_writable(CORE_PATH . '/storage/sessions'),
        ];
    }

    public function run(): void
    {
        $in = [];
        foreach (['install_code', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'base_url', 'admin_name', 'admin_email', 'admin_password'] as $k) {
            $in[$k] = trim((string) Request::post($k));
        }
        $in['db_pass'] = (string) Request::post('db_pass'); // jangan di-trim
        $in['admin_password'] = (string) Request::post('admin_password');
        $in['demo'] = Request::post('demo') === '1' ? '1' : '';

        $err = [];
        $codeFile = $this->codeFile();
        if (!is_file($codeFile)) {
            $err['install_code'] = 'File storage/install-code.txt tidak ditemukan.';
        } elseif (!hash_equals(trim((string) file_get_contents($codeFile)), $in['install_code'])) {
            $err['install_code'] = 'Kode instalasi salah.';
        }
        if ($in['db_name'] === '' || $in['db_user'] === '' || $in['db_host'] === '') {
            $err['db'] = 'Host, nama database, dan user database wajib diisi.';
        }
        if (!preg_match('/^\d{2,5}$/', $in['db_port'])) {
            $err['db_port'] = 'Port tidak valid.';
        }
        if ($in['base_url'] !== '' && !preg_match('#^/[A-Za-z0-9._/-]*$#', $in['base_url'])) {
            $err['base_url'] = 'URL dasar harus diawali / (contoh: /pos) atau kosong.';
        }
        if (mb_strlen($in['admin_name']) < 3) {
            $err['admin_name'] = 'Nama minimal 3 karakter.';
        }
        if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $err['admin_email'] = 'Email tidak valid.';
        }
        if (strlen($in['admin_password']) < 10 || strlen($in['admin_password']) > 72) {
            $err['admin_password'] = 'Kata sandi 10-72 karakter.';
        }

        $result = null;
        if (!$err) {
            try {
                $result = $this->install($in);
            } catch (Throwable $e) {
                error_log('[install] ' . $e->getMessage());
                $err['fatal'] = 'Instalasi gagal. Periksa konfigurasi database dan log server, lalu pastikan database kosong sebelum mencoba kembali.';
            }
        }

        $safeOld = $in;
        unset($safeOld['db_pass'], $safeOld['admin_password'], $safeOld['install_code']);
        View::render('install/index', [
            'csrf' => Csrf::token(),
            'errors' => $err,
            'old' => $safeOld,
            'codeMissing' => !is_file($codeFile) && $result === null,
            'result' => $result,
            'writable' => $this->checks(),
        ]);
    }

    private function install(array $in): array
    {
        $pdo = new PDO(
            "mysql:host={$in['db_host']};port={$in['db_port']};dbname={$in['db_name']};charset=utf8mb4",
            $in['db_user'],
            $in['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );

        $tableCount = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'
        )->fetchColumn();
        if ($tableCount > 0) {
            throw new \RuntimeException('Database instalasi harus kosong.');
        }

        // Skema: dijalankan per pernyataan agar kesalahan di pernyataan mana pun terlihat.
        $sql = (string) file_get_contents(CORE_PATH . '/database/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        foreach (explode(';', $sql) as $stmt) {
            $stmt = trim($stmt);
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
        }

        $modulesAll = json_encode(['retail', 'fnb', 'pharmacy', 'laundry', 'printing', 'service']);
        $plan = $pdo->prepare('INSERT INTO plans (code, name, price_monthly, max_branches, max_users, max_transactions_month, allowed_modules) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $plan->execute(['free', 'Free', 0, 1, 2, 100, $modulesAll]);
        $plan->execute(['starter', 'Starter', 99000, 1, 3, 1000, $modulesAll]);
        $plan->execute(['pro', 'Pro', 199000, 5, 20, 20000, $modulesAll]);
        $plan->execute(['enterprise', 'Enterprise', 499000, null, null, null, $modulesAll]);
        $proId = (int) $pdo->query("SELECT id FROM plans WHERE code = 'pro'")->fetchColumn();

        $pdo->prepare('INSERT INTO users (tenant_id, branch_id, name, email, password_hash, role) VALUES (NULL, NULL, ?, ?, ?, ?)')
            ->execute([$in['admin_name'], strtolower($in['admin_email']), password_hash($in['admin_password'], PASSWORD_DEFAULT), 'super_admin']);

        $demo = null;
        if ($in['demo'] === '1') {
            $demo = DemoSeeder::seed($pdo, $proId);
        }

        // .env
        $env = "APP_DEBUG=false\n"
            . 'APP_BASE_URL=' . $in['base_url'] . "\n"
            . "APP_TIMEZONE=Asia/Jakarta\n"
            . 'APP_KEY=' . bin2hex(random_bytes(32)) . "\n\n"
            . 'DB_HOST=' . $in['db_host'] . "\n"
            . 'DB_PORT=' . $in['db_port'] . "\n"
            . 'DB_NAME=' . $in['db_name'] . "\n"
            . 'DB_USER=' . $in['db_user'] . "\n"
            . 'DB_PASS=' . Environment::quote($in['db_pass']) . "\n";
        $envWritten = @file_put_contents(CORE_PATH . '/.env', $env, LOCK_EX) !== false;
        if ($envWritten) {
            @chmod(CORE_PATH . '/.env', 0640);
        }

        $locked = @file_put_contents(CORE_PATH . '/storage/installed.lock', date('c') . "\n") !== false;
        if ($locked) {
            @unlink($this->codeFile());
        }

        return [
            'demo' => $demo,
            'env_written' => $envWritten,
            'env_content' => $envWritten ? null : $env,
            'locked' => $locked,
            'base' => $in['base_url'],
        ];
    }
}
