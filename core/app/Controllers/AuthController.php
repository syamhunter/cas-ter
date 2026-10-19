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

final class AuthController
{
    private const WINDOW_MINUTES = 15;
    private const MAX_FAIL_PER_IP = 20;
    private const MAX_FAIL_PER_EMAIL = 8;

    /** GET / -> arahkan sesuai role. */
    public function home(): void
    {
        $base = Router::base();
        if (!Auth::check()) {
            Response::redirect($base . '/login');
        }
        $destination = Auth::role() === 'super_admin' ? '/admin' : (Auth::role() === 'owner' ? '/owner' : '/pos');
        Response::redirect($base . $destination);
    }

    public function showLogin(): void
    {
        if (Auth::check()) {
            Response::redirect(Router::base() . '/');
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        View::render('auth/login', [
            'csrf' => Csrf::token(),
            'base' => Router::base(),
            'error' => is_array($flash) ? (string) ($flash['msg'] ?? '') : '',
            'email' => (string) ($_SESSION['last_email'] ?? ''),
        ]);
    }

    public function login(): void
    {
        $pdo = Database::pdo();
        $email = strtolower(trim((string) Request::post('email')));
        $password = (string) Request::post('password');
        $ip = Request::ip();
        $_SESSION['last_email'] = substr($email, 0, 190);

        $deny = static function (string $msg): void {
            $_SESSION['flash'] = ['type' => 'danger', 'msg' => $msg];
            Response::redirect(Router::base() . '/login');
        };

        // Pembatasan percobaan login (brute force). Memakai jam DATABASE (NOW()) agar tidak
        // terpengaruh perbedaan zona waktu antara PHP dan server database.
        $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND created_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)');
        $st->execute([$ip]);
        $byIp = (int) $st->fetchColumn();
        $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at >= (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)');
        $st->execute([substr($email, 0, 190)]);
        $byEmail = (int) $st->fetchColumn();
        if ($byIp >= self::MAX_FAIL_PER_IP || $byEmail >= self::MAX_FAIL_PER_EMAIL) {
            $deny('Terlalu banyak percobaan gagal. Coba lagi dalam ' . self::WINDOW_MINUTES . ' menit.');
        }

        $user = null;
        if ($email !== '' && $password !== '' && strlen($password) <= 200) {
            $st = $pdo->prepare('SELECT id, tenant_id, branch_id, name, password_hash, role, is_active FROM users WHERE email = ? AND deleted_at IS NULL');
            $st->execute([$email]);
            $user = $st->fetch() ?: null;
        }
        // Waktu verifikasi dibuat sama baik user ada maupun tidak.
        $hash = $user['password_hash'] ?? password_hash('dummy-password', PASSWORD_DEFAULT);
        $ok = password_verify($password, $hash) && $user !== null && (int) $user['is_active'] === 1;

        if (!$ok) {
            $pdo->prepare('INSERT INTO login_attempts (ip, email) VALUES (?, ?)')->execute([$ip, substr($email, 0, 190)]);
            if (random_int(1, 100) === 1) {
                $pdo->exec('DELETE FROM login_attempts WHERE created_at < (NOW() - INTERVAL 1 DAY)');
            }
            $deny('Email atau kata sandi salah.');
        }

        // Tentukan cabang aktif.
        $branchId = null;
        if ($user['role'] !== 'super_admin') {
            if ($user['branch_id'] !== null) {
                $st = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND tenant_id = ? AND is_active = 1 AND deleted_at IS NULL');
                $st->execute([(int) $user['branch_id'], (int) $user['tenant_id']]);
            } else {
                $st = $pdo->prepare('SELECT id FROM branches WHERE tenant_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1');
                $st->execute([(int) $user['tenant_id']]);
            }
            $b = $st->fetchColumn();
            if ($b === false) {
                $deny('Akun belum memiliki cabang aktif. Hubungi administrator.');
            }
            $branchId = (int) $b;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, (int) $user['id']]);
            $user['password_hash'] = $hash;
        }
        $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), (int) $user['id']]);
        $pdo->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([substr($email, 0, 190)]);

        unset($_SESSION['last_email']);
        Auth::login($user, $branchId);
        Response::redirect(Router::base() . '/');
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect(Router::base() . '/login');
    }
}
