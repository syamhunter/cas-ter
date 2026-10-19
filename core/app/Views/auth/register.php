<?php
/** @var string $csrf @var string $base @var array $plans @var array $modules @var array $selectedModules @var array $old @var array $errors */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fieldError = static fn(string $field): string => isset($errors[$field])
    ? '<div class="form-text text-danger">' . $e($errors[$field]) . '</div>'
    : '';
$selectedPrimary = (string) ($old['primary_module'] ?? '');
$selectedAdditional = array_fill_keys($selectedModules, true);
if ($selectedPrimary === '' && $selectedAdditional) {
    $selectedPrimary = (string) array_key_first($selectedAdditional);
}
$defaultPlan = (string) ($old['plan_id'] ?? ($plans[0]['id'] ?? ''));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Daftar - Kasir Usaha</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    body { min-height: 100vh; background: #f3f5fa; color: #152238; }
    .register-wrap { max-width: 920px; }
    .register-card { border: 0; border-radius: 18px; box-shadow: 0 18px 55px #22345d14; }
    .brand-mark { width: 42px; height: 42px; display: grid; place-items: center; color: #fff; background: #3157d5; border-radius: 13px; font-weight: 800; }
    .module-card { height: 100%; border: 1px solid #e3e8f2; border-radius: 13px; transition: border-color .15s, background .15s; }
    .module-card:has(input:checked) { border-color: #3157d5; background: #f5f7ff; }
    .module-card:has(input:focus-visible) { outline: 3px solid #3157d533; }
    .trial-note { border-radius: 12px; background: #f1f5ff; }
  </style>
</head>
<body class="d-flex align-items-center py-4">
<main class="container register-wrap">
  <div class="d-flex align-items-center gap-3 mb-3 px-1">
    <span class="brand-mark" aria-hidden="true">K</span>
    <div><div class="fw-bold">Kasir Usaha</div><div class="small text-muted">Pendaftaran pemilik usaha</div></div>
  </div>
  <section class="card register-card">
    <div class="card-body p-4 p-lg-5">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
        <div><h1 class="h3 mb-1">Mulai kelola usaha Anda</h1><p class="text-muted mb-0">Pilih bidang usaha; fitur kasir akan mengikuti kebutuhan bisnis Anda.</p></div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/login">Sudah punya akun? Masuk</a>
      </div>

      <?php if (isset($errors['general'])): ?>
        <div class="alert alert-danger py-2" role="alert"><?= $e($errors['general']) ?></div>
      <?php endif; ?>
      <?php if (!$plans): ?>
        <div class="alert alert-warning" role="alert">Pendaftaran belum tersedia karena belum ada paket aktif. Hubungi administrator.</div>
      <?php endif; ?>

      <form method="post" action="<?= $e($base) ?>/register" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <h2 class="h6 fw-bold mb-3">1. Akun dan usaha</h2>
        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <label class="form-label" for="ownerName">Nama pemilik</label>
            <input class="form-control" id="ownerName" name="owner_name" maxlength="150" required autocomplete="name" value="<?= $e($old['owner_name'] ?? '') ?>">
            <?= $fieldError('owner_name') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="businessName">Nama usaha</label>
            <input class="form-control" id="businessName" name="business_name" maxlength="150" required autocomplete="organization" value="<?= $e($old['business_name'] ?? '') ?>">
            <?= $fieldError('business_name') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="email">Email untuk login</label>
            <input class="form-control" id="email" name="email" type="email" maxlength="190" required autocomplete="email" value="<?= $e($old['email'] ?? '') ?>">
            <?= $fieldError('email') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="phone">Nomor telepon <span class="text-muted">(opsional)</span></label>
            <input class="form-control" id="phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="<?= $e($old['phone'] ?? '') ?>">
            <?= $fieldError('phone') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="password">Kata sandi <span class="text-muted">(minimal 10 karakter)</span></label>
            <input class="form-control" id="password" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password">
            <?= $fieldError('password') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="passwordConfirmation">Ulangi kata sandi</label>
            <input class="form-control" id="passwordConfirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" required autocomplete="new-password">
            <?= $fieldError('password_confirmation') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="branchName">Nama cabang pertama</label>
            <input class="form-control" id="branchName" name="branch_name" maxlength="150" placeholder="Cabang Utama" value="<?= $e($old['branch_name'] ?? '') ?>">
            <?= $fieldError('branch_name') ?>
          </div>
        </div>

        <h2 class="h6 fw-bold mb-2">2. Bidang usaha</h2>
        <p class="small text-muted">Pilih bidang utama. Jika usaha Anda memiliki beberapa jenis layanan, tambahkan bidang lainnya.</p>
        <?php if (isset($errors['primary_module']) || isset($errors['additional_modules'])): ?>
          <div class="small text-danger mb-2"><?= $e($errors['primary_module'] ?? $errors['additional_modules']) ?></div>
        <?php endif; ?>
        <div class="row g-2 mb-4">
          <?php foreach ($modules as $code => $module): ?>
            <?php $radioId = 'primary_' . $code; $checkId = 'additional_' . $code; ?>
            <div class="col-sm-6 col-lg-4">
              <div class="module-card p-3">
                <div class="form-check">
                  <input class="form-check-input primary-module" type="radio" name="primary_module" id="<?= $e($radioId) ?>" value="<?= $e($code) ?>" required <?= $selectedPrimary === $code ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="<?= $e($radioId) ?>"><?= $e($module['label']) ?></label>
                </div>
                <div class="small text-muted ms-4"><?= $e($module['description']) ?></div>
                <div class="form-check ms-4 mt-2">
                  <input class="form-check-input additional-module" type="checkbox" name="additional_modules[]" id="<?= $e($checkId) ?>" value="<?= $e($code) ?>" <?= isset($selectedAdditional[$code]) && $selectedPrimary !== $code ? 'checked' : '' ?>>
                  <label class="form-check-label small text-muted" for="<?= $e($checkId) ?>">Tambahkan juga</label>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <h2 class="h6 fw-bold mb-2">3. Pilih paket</h2>
        <?php if (isset($errors['plan_id'])): ?><div class="small text-danger mb-2"><?= $e($errors['plan_id']) ?></div><?php endif; ?>
        <div class="row g-2 mb-3">
          <?php foreach ($plans as $plan): ?>
            <?php
              $included = $plan['allowed_modules'] ?: array_keys($modules);
              $includedLabels = array_map(static fn(string $code): string => $modules[$code]['label'] ?? $code, $included);
            ?>
            <div class="col-md-4">
              <label class="module-card d-block p-3 h-100" for="plan_<?= (int) $plan['id'] ?>">
                <span class="form-check d-block">
                  <input class="form-check-input" type="radio" name="plan_id" id="plan_<?= (int) $plan['id'] ?>" value="<?= (int) $plan['id'] ?>" required <?= $defaultPlan === (string) $plan['id'] ? 'checked' : '' ?>>
                  <span class="form-check-label fw-bold"><?= $e($plan['name']) ?></span>
                </span>
                <?php if ($plan['code'] === 'free'): ?>
                  <span class="d-block fs-5 fw-bold mt-2 text-success">Gratis selamanya</span>
                <?php else: ?>
                  <span class="d-block fs-5 fw-bold mt-2">Rp<?= number_format((float) $plan['price_monthly'], 0, ',', '.') ?><small class="fs-6 fw-normal text-muted"> / bulan</small></span>
                <?php endif; ?>
                <span class="d-block small text-muted mt-1"><?= $plan['max_branches'] === null ? 'Cabang tanpa batas' : (int) $plan['max_branches'] . ' cabang' ?> · <?= $plan['max_users'] === null ? 'Pengguna tanpa batas' : (int) $plan['max_users'] . ' pengguna' ?></span>
                <span class="d-block small text-muted mt-1"><?= $plan['max_transactions_month'] === null ? 'Transaksi bulanan tanpa batas' : number_format((int) $plan['max_transactions_month'], 0, ',', '.') . ' transaksi / bulan' ?></span>
                <span class="d-block small text-muted mt-1">Bidang: <?= $e(implode(', ', $includedLabels)) ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <?= $fieldError('plan_id') ?>

        <div class="trial-note small p-3 mb-3">
          <strong id="trialNotice">Paket berbayar dimulai dengan uji coba 14 hari.</strong>
          Paket Free berlaku tanpa batas waktu dengan kuota terbatas. Pendaftaran membuat akun pemilik dan toko; login memakai email pemilik dan akun kasir tidak dibuat secara publik.
        </div>
        <button class="btn btn-primary btn-lg w-100" id="registerSubmit" type="submit" <?= !$plans ? 'disabled' : '' ?>>Buat akun &amp; mulai</button>
        <p class="small text-muted text-center mt-3 mb-0">Dengan mendaftar, Anda membuat akun pemilik usaha. Fitur pembayaran langganan dapat dihubungkan setelah konfigurasi penyedia pembayaran tersedia.</p>
      </form>
    </div>
  </section>
</main>
<script>
  (function () {
    'use strict';
    const submit = document.getElementById('registerSubmit');
    const notice = document.getElementById('trialNotice');
    document.querySelectorAll('input[name="plan_id"]').forEach(function (input) {
      input.addEventListener('change', function () {
        const free = input.checked && input.closest('label').querySelector('.form-check-label').textContent.trim() === 'Free';
        if (free) {
          notice.textContent = 'Paket Free aktif tanpa batas waktu: 1 cabang, maksimal 2 akun, dan 100 transaksi per bulan.';
          submit.textContent = 'Buat akun Free';
        } else if (input.checked) {
          notice.textContent = 'Paket berbayar dimulai dengan uji coba 14 hari.';
          submit.textContent = 'Buat akun & mulai uji coba';
        }
      });
    });
    const selected = document.querySelector('input[name="plan_id"]:checked');
    if (selected) selected.dispatchEvent(new Event('change'));
  })();
</script>
</body>
</html>
