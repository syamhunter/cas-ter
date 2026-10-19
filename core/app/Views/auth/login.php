<?php
/** @var string $csrf @var string $base @var string $error @var string $email */
$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Masuk</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
  <main class="container" style="max-width:420px">
    <div class="card shadow-sm border-0">
      <div class="card-body p-4">
        <h1 class="h4 mb-1">Masuk</h1>
        <p class="text-muted small mb-4">Aplikasi Kasir &amp; Manajemen Usaha</p>
        <?php if ($error !== ''): ?>
          <div class="alert alert-danger py-2 small" role="alert"><?= $e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= $e($base) ?>/login" autocomplete="on">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <div class="mb-3">
            <label class="form-label" for="email">Email</label>
            <input class="form-control" id="email" name="email" type="email" required autofocus autocomplete="username" value="<?= $e($email) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label" for="password">Kata sandi</label>
            <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
          </div>
          <button class="btn btn-primary w-100" type="submit">Masuk</button>
        </form>
        <div class="text-center small mt-3">Belum punya akun? <a href="<?= $e($base) ?>/register">Daftar usaha</a></div>
      </div>
    </div>
  </main>
</body>
</html>
