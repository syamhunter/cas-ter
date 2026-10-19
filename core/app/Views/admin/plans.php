<?php
/** @var string $base @var string $csrf @var array $plans @var array $modules */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="card mb-3">
  <div class="card-header"><strong>Daftar paket</strong><div class="small text-muted">Edit batas dan modul, atau nonaktifkan paket tanpa menghapus riwayat pelanggan.</div></div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Paket</th><th>Harga/bulan</th><th>Cabang</th><th>Pengguna</th><th>Trx/bulan</th><th>Modul</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
    <?php foreach ($plans as $plan): $planModules = json_decode((string) ($plan['allowed_modules'] ?? ''), true) ?: []; ?>
      <tr>
        <td><strong><?= $e($plan['name']) ?></strong><div class="small text-muted"><?= $e($plan['code']) ?></div></td>
        <td>Rp<?= number_format((float) $plan['price_monthly'], 0, ',', '.') ?></td>
        <td><?= $plan['max_branches'] === null ? 'Tanpa batas' : (int) $plan['max_branches'] ?></td>
        <td><?= $plan['max_users'] === null ? 'Tanpa batas' : (int) $plan['max_users'] ?></td>
        <td><?= $plan['max_transactions_month'] === null ? 'Tanpa batas' : number_format((int) $plan['max_transactions_month'], 0, ',', '.') ?></td>
        <td class="small"><?= $e(implode(', ', array_intersect_key($modules, array_flip($planModules)))) ?></td>
        <td><span class="badge text-bg-<?= (int) $plan['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $plan['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
        <td class="text-nowrap"><details><summary class="btn btn-outline-primary btn-sm">Edit</summary>
          <form method="post" action="<?= $e($base) ?>/admin/plans/toggle" class="d-inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>"><button class="btn btn-outline-<?= (int) $plan['is_active'] === 1 ? 'danger' : 'success' ?> btn-sm" type="submit"><?= (int) $plan['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
          <form method="post" action="<?= $e($base) ?>/admin/plans" class="row g-2 package-edit mt-2" style="min-width: min(680px, 80vw)">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>">
            <div class="col-md-3"><label class="form-label small" for="code<?= (int) $plan['id'] ?>">Kode</label><input class="form-control form-control-sm" id="code<?= (int) $plan['id'] ?>" name="code" value="<?= $e($plan['code']) ?>" required maxlength="40"></div>
            <div class="col-md-3"><label class="form-label small" for="name<?= (int) $plan['id'] ?>">Nama</label><input class="form-control form-control-sm" id="name<?= (int) $plan['id'] ?>" name="name" value="<?= $e($plan['name']) ?>" required maxlength="100"></div>
            <div class="col-md-3"><label class="form-label small" for="price<?= (int) $plan['id'] ?>">Harga/bulan</label><input class="form-control form-control-sm" id="price<?= (int) $plan['id'] ?>" name="price_monthly" type="number" min="0" step="0.01" value="<?= $e($plan['price_monthly']) ?>" required></div>
            <div class="col-md-3"><label class="form-label small" for="branches<?= (int) $plan['id'] ?>">Maks. cabang</label><input class="form-control form-control-sm" id="branches<?= (int) $plan['id'] ?>" name="max_branches" type="number" min="1" value="<?= $e($plan['max_branches'] ?? '') ?>" placeholder="Tanpa batas"></div>
            <div class="col-md-3"><label class="form-label small" for="users<?= (int) $plan['id'] ?>">Maks. pengguna</label><input class="form-control form-control-sm" id="users<?= (int) $plan['id'] ?>" name="max_users" type="number" min="1" value="<?= $e($plan['max_users'] ?? '') ?>" placeholder="Tanpa batas"></div>
            <div class="col-md-3"><label class="form-label small" for="transactions<?= (int) $plan['id'] ?>">Maks. transaksi/bulan</label><input class="form-control form-control-sm" id="transactions<?= (int) $plan['id'] ?>" name="max_transactions_month" type="number" min="1" value="<?= $e($plan['max_transactions_month'] ?? '') ?>" placeholder="Tanpa batas"></div>
            <div class="col-12"><span class="form-label small d-block">Modul termasuk</span><?php foreach ($modules as $code => $label): ?><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="allowed_modules[]" value="<?= $e($code) ?>"<?= in_array($code, $planModules, true) ? ' checked' : '' ?>><span class="form-check-label small"><?= $e($label) ?></span></label><?php endforeach; ?></div>
            <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Simpan paket</button></div>
          </form>
        </details></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$plans): ?><tr><td colspan="8" class="text-center text-muted py-4">Belum ada paket.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>
<details open class="card">
  <summary class="card-header package-summary fw-semibold">Tambah paket baru</summary>
  <form method="post" action="<?= $e($base) ?>/admin/plans" class="card-body row g-2">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="col-md-3"><label class="form-label small" for="newPlanCode">Kode paket</label><input class="form-control form-control-sm" id="newPlanCode" name="code" required maxlength="40" placeholder="starter"></div>
    <div class="col-md-3"><label class="form-label small" for="newPlanName">Nama paket</label><input class="form-control form-control-sm" id="newPlanName" name="name" required maxlength="100"></div>
    <div class="col-md-3"><label class="form-label small" for="newPlanPrice">Harga/bulan</label><input class="form-control form-control-sm" id="newPlanPrice" name="price_monthly" type="number" min="0" step="0.01" value="0" required></div>
    <div class="col-md-3"><label class="form-label small" for="newPlanBranches">Maks. cabang</label><input class="form-control form-control-sm" id="newPlanBranches" name="max_branches" type="number" min="1" placeholder="Tanpa batas"></div>
    <div class="col-md-3"><label class="form-label small" for="newPlanUsers">Maks. pengguna</label><input class="form-control form-control-sm" id="newPlanUsers" name="max_users" type="number" min="1" placeholder="Tanpa batas"></div>
    <div class="col-md-3"><label class="form-label small" for="newPlanTransactions">Maks. transaksi/bulan</label><input class="form-control form-control-sm" id="newPlanTransactions" name="max_transactions_month" type="number" min="1" placeholder="Tanpa batas"></div>
    <div class="col-12"><span class="form-label small d-block">Modul termasuk</span><?php foreach ($modules as $code => $label): ?><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="allowed_modules[]" value="<?= $e($code) ?>"><span class="form-check-label small"><?= $e($label) ?></span></label><?php endforeach; ?></div>
    <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Buat paket</button></div>
  </form>
</details>