<?php
/** @var string $base @var string $csrf @var array $plans @var array $tenants @var array $modules @var string $search @var string $selectedStatus @var int $selectedPlan @var int $page @var int $pageCount @var int $tenantCount */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$badge = ['trial' => 'info', 'active' => 'success', 'suspended' => 'danger', 'expired' => 'secondary'];
$queryParams = array_filter(['q' => $search, 'status' => $selectedStatus, 'plan_id' => $selectedPlan > 0 ? $selectedPlan : ''], static fn($value): bool => $value !== '');
?>
<div class="card mb-3">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><strong>Daftar pelanggan</strong><div class="small text-muted"><?= number_format($tenantCount, 0, ',', '.') ?> usaha · <?= $pageCount > 1 ? '25' : count($tenants) ?> per halaman</div></div>
    <a class="btn btn-primary btn-sm" href="#add-customer">Tambah pelanggan</a>
  </div>
  <form method="get" action="<?= $e($base) ?>/admin/tenants" class="row g-2 p-3 border-bottom">
    <div class="col-md-5"><label class="visually-hidden" for="tenantSearch">Cari usaha, pemilik, atau email</label><input class="form-control form-control-sm" id="tenantSearch" name="q" value="<?= $e($search) ?>" placeholder="Cari usaha, pemilik, atau email"></div>
    <div class="col-md-3"><label class="visually-hidden" for="tenantStatus">Filter status</label><select class="form-select form-select-sm" id="tenantStatus" name="status"><option value="">Semua status</option>
      <?php foreach (['trial' => 'Trial', 'active' => 'Aktif', 'suspended' => 'Ditangguhkan', 'expired' => 'Kedaluwarsa'] as $key => $label): ?><option value="<?= $e($key) ?>"<?= $selectedStatus === $key ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-md-3"><label class="visually-hidden" for="tenantPlan">Filter paket</label><select class="form-select form-select-sm" id="tenantPlan" name="plan_id"><option value="">Semua paket</option>
      <?php foreach ($plans as $plan): ?><option value="<?= (int) $plan['id'] ?>"<?= $selectedPlan === (int) $plan['id'] ? ' selected' : '' ?>><?= $e($plan['name']) ?></option><?php endforeach; ?>
    </select></div>
    <div class="col-md-1 d-grid"><button class="btn btn-primary btn-sm" type="submit">Cari</button></div>
  </form>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0">
    <thead><tr><th>Usaha / pemilik</th><th>Paket</th><th>Status</th><th>Berakhir</th><th>Trx bulan ini</th><th>Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($tenants as $tenant): ?>
      <tr>
        <td><a class="fw-semibold text-decoration-none" href="<?= $e($base) ?>/admin/tenants/detail?id=<?= (int) $tenant['id'] ?>"><?= $e($tenant['name']) ?></a>
          <div class="small text-muted"><?= $e($tenant['owner_name']) ?> · <?= $e($tenant['email']) ?></div>
          <div class="small text-muted"><?= (int) $tenant['branch_count'] ?> cabang · <?= (int) $tenant['user_count'] ?> pengguna</div>
        </td>
        <td><div class="fw-semibold"><?= $e($tenant['plan_name']) ?></div><form method="post" action="<?= $e($base) ?>/admin/tenants/plan" class="d-flex gap-1 mt-1">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tenant_id" value="<?= (int) $tenant['id'] ?>">
          <label class="visually-hidden" for="plan_<?= (int) $tenant['id'] ?>">Paket baru untuk <?= $e($tenant['name']) ?></label>
          <select class="form-select form-select-sm" id="plan_<?= (int) $tenant['id'] ?>" name="plan_id" required><?php foreach ($plans as $plan): if ((int) $plan['is_active'] === 1): ?><option value="<?= (int) $plan['id'] ?>"<?= (int) $tenant['current_plan_id'] === (int) $plan['id'] ? ' selected' : '' ?>><?= $e($plan['name']) ?></option><?php endif; endforeach; ?></select>
          <button class="btn btn-outline-primary btn-sm" type="submit">Ubah</button>
        </form></td>
        <td><span class="badge text-bg-<?= $e($badge[$tenant['status']] ?? 'secondary') ?>"><?= $e($tenant['status']) ?></span></td>
        <td class="small text-nowrap"><?= $e($tenant['subscription_ends_at'] ?? '-') ?></td>
        <td><?= number_format((int) $tenant['trx_month'], 0, ',', '.') ?></td>
        <td class="text-nowrap"><div class="d-flex gap-1">
          <a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/tenants/detail?id=<?= (int) $tenant['id'] ?>">Detail</a>
          <form method="post" action="<?= $e($base) ?>/admin/tenants/toggle" onsubmit="return confirm('Ubah status usaha ini?')"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tenant_id" value="<?= (int) $tenant['id'] ?>"><button class="btn btn-outline-<?= in_array($tenant['status'], ['active', 'trial'], true) ? 'danger' : 'success' ?> btn-sm" type="submit"><?= in_array($tenant['status'], ['active', 'trial'], true) ? 'Suspend' : 'Aktifkan' ?></button></form>
        </div></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$tenants): ?><tr><td colspan="6" class="text-center text-muted py-4">Tidak ada pelanggan yang cocok.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="card-footer d-flex flex-wrap justify-content-between align-items-center gap-2 small">
    <span>Halaman <?= $page ?> dari <?= $pageCount ?></span><nav aria-label="Halaman pelanggan" class="d-flex gap-1">
      <?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/tenants?<?= $e(http_build_query(array_merge($queryParams, ['page' => $page - 1]))) ?>">Sebelumnya</a><?php endif; ?>
      <?php if ($page < $pageCount): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/tenants?<?= $e(http_build_query(array_merge($queryParams, ['page' => $page + 1]))) ?>">Berikutnya</a><?php endif; ?>
    </nav>
  </div>
</div>

<details id="add-customer" class="card">
  <summary class="card-header package-summary fw-semibold">Tambah pelanggan / usaha</summary>
  <form method="post" action="<?= $e($base) ?>/admin/tenants" class="card-body row g-2">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <div class="col-md-6"><label class="form-label small" for="newTenantName">Nama usaha</label><input class="form-control form-control-sm" id="newTenantName" name="name" required maxlength="150"></div>
    <div class="col-md-6"><label class="form-label small" for="newOwnerName">Nama pemilik</label><input class="form-control form-control-sm" id="newOwnerName" name="owner_name" required minlength="3"></div>
    <div class="col-md-6"><label class="form-label small" for="newOwnerEmail">Email login pemilik</label><input class="form-control form-control-sm" id="newOwnerEmail" name="email" type="email" required></div>
    <div class="col-md-6"><label class="form-label small" for="newOwnerPassword">Kata sandi awal</label><input class="form-control form-control-sm" id="newOwnerPassword" name="password" type="password" minlength="8" maxlength="72" autocomplete="new-password" required></div>
    <div class="col-md-4"><label class="form-label small" for="newTenantPlan">Paket</label><select class="form-select form-select-sm" id="newTenantPlan" name="plan_id" required><?php foreach ($plans as $plan): if ((int) $plan['is_active'] === 1): ?><option value="<?= (int) $plan['id'] ?>"><?= $e($plan['name']) ?></option><?php endif; endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label small" for="newTenantDays">Masa aktif (hari)</label><input class="form-control form-control-sm" id="newTenantDays" name="days" type="number" min="1" max="3650" value="14"></div>
    <div class="col-md-3"><label class="form-label small" for="newBranchName">Cabang pertama</label><input class="form-control form-control-sm" id="newBranchName" name="branch_name" placeholder="Cabang Utama"></div>
    <div class="col-md-3"><label class="form-label small" for="newBranchCode">Kode cabang</label><input class="form-control form-control-sm" id="newBranchCode" name="branch_code" maxlength="10" placeholder="PST"></div>
    <div class="col-12"><span class="form-label small d-block">Modul aktif</span><?php foreach ($modules as $code => $label): ?><label class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="modules[]" value="<?= $e($code) ?>"><span class="form-check-label small"><?= $e($label) ?></span></label><?php endforeach; ?></div>
    <div class="col-12 d-flex gap-3"><label class="form-check"><input class="form-check-input" type="radio" name="status" value="trial" checked><span class="form-check-label">Trial</span></label><label class="form-check"><input class="form-check-input" type="radio" name="status" value="active"><span class="form-check-label">Aktif</span></label></div>
    <div class="col-md-3"><label class="form-label small" for="newTenantTax">Pajak (%)</label><input class="form-control form-control-sm" id="newTenantTax" name="tax_pct" type="number" min="0" max="100" step="0.01" value="0"></div>
    <div class="col-md-4"><label class="form-label small" for="newTenantTaxMode">Mode pajak</label><select class="form-select form-select-sm" id="newTenantTaxMode" name="tax_mode"><option value="none">Tidak dikenakan</option><option value="exclusive">Ditambahkan ke total</option><option value="inclusive">Termasuk harga</option></select></div>
    <div class="col-md-3"><label class="form-label small" for="newTenantService">Service charge F&amp;B (%)</label><input class="form-control form-control-sm" id="newTenantService" name="service_pct" type="number" min="0" max="100" step="0.01" value="0"></div>
    <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Buat pelanggan</button></div>
  </form>
</details>