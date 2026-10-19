<?php
/**
 * @var string $csrf @var array $errors @var array $old @var bool $codeMissing @var ?array $result @var array<string,bool> $writable
 */
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$v = static fn(string $k, string $d = '') => $old[$k] ?? $d;
$fieldErr = static fn(string $k) => isset($errors[$k]) ? '<div class="text-danger small mt-1">' . htmlspecialchars($errors[$k], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</div>' : '';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Instalasi</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<main class="container py-4" style="max-width:720px">
  <h1 class="h3 mb-3">Instalasi Aplikasi</h1>

<?php if ($result !== null): ?>
  <div class="alert alert-success"><strong>Instalasi berhasil.</strong> Simpan informasi di bawah ini sekarang, kata sandi demo tidak akan ditampilkan lagi.</div>
  <?php if (!$result['env_written']): ?>
    <div class="alert alert-warning">
      File <code>.env</code> tidak dapat ditulis otomatis. Buat file <code>core/.env</code> secara manual dengan isi berikut, lalu buat file kosong <code>core/storage/installed.lock</code>:
      <pre class="mb-0 mt-2 small"><?= $e($result['env_content']) ?></pre>
    </div>
  <?php endif; ?>
  <?php if (!$result['locked']): ?>
    <div class="alert alert-danger"><strong>Penting:</strong> buat file kosong <code>core/storage/installed.lock</code> secara manual agar installer tertutup.</div>
  <?php endif; ?>
  <?php if (!empty($result['demo'])): $d = $result['demo']; ?>
    <div class="card mb-3"><div class="card-body">
      <h2 class="h6">Akun demo (Toko Demo)</h2>
      <table class="table table-sm mb-0">
        <tr><td>Pemilik</td><td><code><?= $e($d['owner']['email']) ?></code></td><td><code><?= $e($d['owner']['password']) ?></code></td></tr>
        <tr><td>Kasir</td><td><code><?= $e($d['cashier']['email']) ?></code></td><td><code><?= $e($d['cashier']['password']) ?></code></td></tr>
      </table>
      <p class="small text-muted mt-2 mb-0">ID resep demo untuk uji obat resep: <strong><?= (int) $d['prescription_id'] ?></strong></p>
    </div></div>
  <?php endif; ?>
  <a class="btn btn-primary" href="<?= $e($result['base']) ?>/login">Ke halaman login</a>

<?php else: ?>
  <?php if ($codeMissing): ?>
    <div class="alert alert-danger">File <code>core/storage/install-code.txt</code> tidak ditemukan. Buat file tersebut berisi satu kata acak (minimal 8 karakter), lalu muat ulang halaman ini.</div>
  <?php endif; ?>
  <?php if (isset($errors['fatal'])): ?>
    <div class="alert alert-danger"><?= $e($errors['fatal']) ?></div>
  <?php endif; ?>

  <div class="card mb-3"><div class="card-body">
    <h2 class="h6">Pemeriksaan server</h2>
    <ul class="list-unstyled small mb-0">
      <?php foreach ($writable as $label => $ok): ?>
        <li><?= $ok ? '&#9989;' : '&#10060;' ?> <?= $e($label) ?></li>
      <?php endforeach; ?>
    </ul>
  </div></div>

  <form method="post" action="" class="card"><div class="card-body">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">

    <h2 class="h6">Keamanan</h2>
    <div class="mb-3">
      <label class="form-label" for="install_code">Kode instalasi</label>
      <input class="form-control" id="install_code" name="install_code" required autocomplete="off">
      <div class="form-text">Isi file <code>core/storage/install-code.txt</code> di paket yang Anda unggah.</div>
      <?= $fieldErr('install_code') ?>
    </div>

    <h2 class="h6 mt-4">Database</h2>
    <?= $fieldErr('db') ?>
    <div class="row g-2">
      <div class="col-8"><label class="form-label" for="db_host">Host</label><input class="form-control" id="db_host" name="db_host" required value="<?= $e($v('db_host')) ?>"></div>
      <div class="col-4"><label class="form-label" for="db_port">Port</label><input class="form-control" id="db_port" name="db_port" required value="<?= $e($v('db_port', '3306')) ?>"><?= $fieldErr('db_port') ?></div>
      <div class="col-12"><label class="form-label" for="db_name">Nama database</label><input class="form-control" id="db_name" name="db_name" required value="<?= $e($v('db_name')) ?>"></div>
      <div class="col-6"><label class="form-label" for="db_user">User</label><input class="form-control" id="db_user" name="db_user" required autocomplete="off" value="<?= $e($v('db_user')) ?>"></div>
      <div class="col-6"><label class="form-label" for="db_pass">Kata sandi</label><input class="form-control" id="db_pass" name="db_pass" type="password" autocomplete="new-password"></div>
    </div>
    <div class="form-text mb-3">Database harus kosong. Di InfinityFree, host berbentuk <code>sqlXXX.infinityfree.com</code> (lihat panel hosting).</div>

    <h2 class="h6 mt-4">Aplikasi</h2>
    <div class="mb-3">
      <label class="form-label" for="base_url">URL dasar (subfolder)</label>
      <input class="form-control" id="base_url" name="base_url" value="<?= $e($v('base_url')) ?>" placeholder="kosongkan jika di root domain">
      <?= $fieldErr('base_url') ?>
    </div>

    <h2 class="h6 mt-4">Akun Super Admin</h2>
    <div class="row g-2">
      <div class="col-12"><label class="form-label" for="admin_name">Nama</label><input class="form-control" id="admin_name" name="admin_name" required value="<?= $e($v('admin_name')) ?>"><?= $fieldErr('admin_name') ?></div>
      <div class="col-12"><label class="form-label" for="admin_email">Email</label><input class="form-control" id="admin_email" name="admin_email" type="email" required value="<?= $e($v('admin_email')) ?>"><?= $fieldErr('admin_email') ?></div>
      <div class="col-12"><label class="form-label" for="admin_password">Kata sandi (min. 10 karakter)</label><input class="form-control" id="admin_password" name="admin_password" type="password" required autocomplete="new-password"><?= $fieldErr('admin_password') ?></div>
    </div>

    <div class="form-check mt-4">
      <input class="form-check-input" type="checkbox" id="demo" name="demo" value="1" <?= $v('demo') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label" for="demo">Buat data demo (Toko Demo dengan produk 6 industri)</label>
    </div>

    <button class="btn btn-primary mt-4" type="submit" <?= $codeMissing ? 'disabled' : '' ?>>Pasang sekarang</button>
  </div></form>
<?php endif; ?>
</main>
</body>
</html>
