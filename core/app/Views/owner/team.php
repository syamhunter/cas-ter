<?php
/** @var string $base @var string $csrf @var array $tenant @var array $branch @var array $staffUsers @var array $activeBranchesList @var int $activeUsers */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$userLimit = $tenant['max_users'] !== null ? (int) $tenant['max_users'] : null;
$atCapacity = $userLimit !== null && $activeUsers >= $userLimit;
?>
<div class="row g-3">
  <aside class="col-xl-4"><form method="post" action="<?= $e($base) ?>/owner/cashiers" class="card"><div class="card-header"><strong>Tambah kasir</strong><div class="small text-muted">Kredensial terpisah untuk setiap pegawai.</div></div><div class="card-body">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="mb-3"><label class="form-label small fw-semibold" for="cashierName">Nama kasir</label><input class="form-control" id="cashierName" name="name" maxlength="150" required autocomplete="name"></div>
    <div class="mb-3"><label class="form-label small fw-semibold" for="cashierEmail">Email login</label><input class="form-control" id="cashierEmail" name="email" type="email" maxlength="190" required autocomplete="email"></div>
    <div class="mb-3"><label class="form-label small fw-semibold" for="cashierPassword">Kata sandi awal</label><input class="form-control" id="cashierPassword" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password"><div class="form-text">Berikan secara pribadi.</div></div>
    <div class="mb-3"><label class="form-label small fw-semibold" for="cashierBranch">Cabang akses</label><select class="form-select" id="cashierBranch" name="branch_id" required><?php foreach ($activeBranchesList as $activeBranch): ?><option value="<?= (int)$activeBranch['id'] ?>" <?= (int)$activeBranch['id']===(int)$branch['id']?'selected':'' ?>><?= $e($activeBranch['name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary w-100" type="submit" <?= $atCapacity || !$activeBranchesList?'disabled':'' ?>>Buat akun kasir</button>
    <?php if ($userLimit !== null): ?><div class="small text-muted text-center mt-2">Pengguna aktif: <?= number_format($activeUsers,0,',','.') ?> / <?= number_format($userLimit,0,',','.') ?></div><?php endif; ?>
    <?php if ($atCapacity): ?><div class="alert alert-warning small py-2 mt-3 mb-0">Batas paket telah tercapai. Nonaktifkan akun lama atau hubungi administrator.</div><?php endif; ?>
  </div></form></aside>
  <section class="col-xl-8"><div class="card"><div class="card-header d-flex justify-content-between align-items-center"><div><strong>Daftar kasir</strong><div class="small text-muted">Akun kasir untuk <?= $e($tenant['name']) ?></div></div><span class="badge text-bg-light border"><?= count($staffUsers) ?> akun</span></div>
    <div class="list-group list-group-flush">
      <?php foreach ($staffUsers as $staff): $name=(string)$staff['name']; $initial=strtoupper(function_exists('mb_substr')?mb_substr($name,0,1):substr($name,0,1)); ?>
        <article class="list-group-item p-3"><div class="d-flex flex-wrap justify-content-between align-items-start gap-3"><div class="d-flex gap-3"><span class="brand-mark"><?= $e($initial) ?></span><div><strong class="d-block"><?= $e($staff['name']) ?></strong><span class="small text-muted"><?= $e($staff['email']) ?></span><div class="small text-muted mt-1">Cabang: <?= $e($staff['branch_name'] ?? 'Cabang tidak aktif') ?> · Login terakhir: <?= $staff['last_login_at'] ? $e(date('d M Y, H:i',strtotime((string)$staff['last_login_at']))) : 'Belum pernah' ?></div></div></div><span class="badge text-bg-<?= (int)$staff['is_active']===1?'success':'secondary' ?>"><?= (int)$staff['is_active']===1?'Aktif':'Nonaktif' ?></span></div>
          <div class="d-flex flex-wrap gap-2 mt-3"><form method="post" action="<?= $e($base) ?>/owner/cashiers/toggle" onsubmit="return confirm('Ubah status akun kasir ini?')"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="user_id" value="<?= (int)$staff['id'] ?>"><button class="btn btn-outline-<?= (int)$staff['is_active']===1?'danger':'success' ?> btn-sm" type="submit"><?= (int)$staff['is_active']===1?'Nonaktifkan':'Aktifkan' ?></button></form>
            <details><summary class="btn btn-outline-primary btn-sm">Reset kata sandi</summary><form method="post" action="<?= $e($base) ?>/owner/cashiers/password" class="package-edit mt-2" onsubmit="return confirm('Ganti kata sandi kasir? Sesi aktif akan berakhir.')"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="user_id" value="<?= (int)$staff['id'] ?>"><label class="form-label small" for="pass<?= (int)$staff['id'] ?>">Kata sandi baru</label><input class="form-control form-control-sm mb-2" id="pass<?= (int)$staff['id'] ?>" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password"><label class="form-label small" for="confirm<?= (int)$staff['id'] ?>">Ulangi kata sandi</label><input class="form-control form-control-sm mb-2" id="confirm<?= (int)$staff['id'] ?>" name="password_confirmation" type="password" minlength="10" maxlength="72" required autocomplete="new-password"><button class="btn btn-primary btn-sm" type="submit">Simpan kata sandi</button></form></details>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$staffUsers): ?><div class="text-center text-muted py-5">Belum ada akun kasir.</div><?php endif; ?>
    </div>
  </div></section>
</div>