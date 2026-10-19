<?php
/** @var string $csrf @var string $base @var string $ownerName @var array $tenant @var array $branch @var array $modules @var array $summary @var array $recent */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($value): string => 'Rp' . number_format((float) $value, 0, ',', '.');
$status = (string) $tenant['status'];
$expired = $tenant['subscription_ends_at'] !== null
    && strtotime((string) $tenant['subscription_ends_at']) <= time();
$statusLabel = $expired ? 'Masa langganan berakhir' : [
    'trial' => 'Masa uji coba',
    'active' => 'Aktif',
    'suspended' => 'Ditangguhkan',
    'expired' => 'Berakhir',
][$status] ?? 'Tidak diketahui';
$statusClass = $expired || in_array($status, ['suspended', 'expired'], true)
    ? 'danger'
    : ($status === 'trial' ? 'primary' : 'success');
$monthCount = (int) ($summary['transactions_month'] ?? 0);
$accountMonthCount = (int) ($accountMonthCount ?? $monthCount);
$monthLimit = $monthLimit !== null ? (int) $monthLimit : null;
$userLimit = $tenant['max_users'] !== null ? (int) $tenant['max_users'] : null;
$branchLimit = $tenant['max_branches'] !== null ? (int) $tenant['max_branches'] : null;
$usageTone = $usagePct !== null && $usagePct >= 90 ? 'bg-warning' : 'bg-primary';
$orderLabels = [
    'retail' => 'Retail',
    'dine_in' => 'Makan di tempat',
    'takeaway' => 'Bawa pulang',
    'pharmacy' => 'Apotek',
    'laundry' => 'Laundry',
    'printing' => 'Percetakan',
    'service' => 'Jasa',
];
$sidebarBase = $base;
$sidebarBusiness = $tenant['name'];
$sidebarBranch = $branch['name'];
$sidebarRole = 'owner';
$sidebarUser = $ownerName;
$sidebarPage = 'owner';
$sidebarActive = 'dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Dashboard Pemilik - <?= $e($tenant['name']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root { --owner-ink: #17243b; --owner-muted: #738096; --owner-blue: #3157d5; }
    body { min-height: 100vh; display: flex; flex-direction: column; background: #f3f5fa; color: var(--owner-ink); line-height: 1.5; }
    .navbar { min-height: 68px; }
    .brand-mark { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 12px; color: white; background: var(--owner-blue); font-weight: 800; }
    .page-wrap { max-width: 1440px; }
    .welcome-panel { overflow: hidden; position: relative; border: 0; border-radius: 18px; color: #fff; background: linear-gradient(115deg, #253d9b, #4269e8); }
    .welcome-panel:after { content: ""; position: absolute; width: 260px; height: 260px; right: -60px; top: -120px; border: 35px solid #ffffff18; border-radius: 50%; }
    .surface-card { min-width: 0; border: 1px solid #e5e9f2; border-radius: 15px; background: #fff; }
    main.page-wrap { flex: 1 0 auto; width: 100%; }
    .dashboard-grid { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(300px, 1fr); align-items: start; gap: 16px; margin-bottom: 16px; }
    .dashboard-primary, .dashboard-sidebar { display: grid; align-content: start; gap: 16px; min-width: 0; }
    .dashboard-panel-heading { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: start; gap: 12px; margin-bottom: 16px; }
    .dashboard-panel-heading > div { min-width: 0; }
    .dashboard-panel-heading .small { line-height: 1.5; }
    .business-settings { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .business-settings-card { min-width: 0; border: 1px solid #e5e9f2; border-radius: 15px; background: #fff; }
    .business-module-choice { height: 100%; border: 1px solid #e5e9f2; border-radius: 11px; background: #fff; }
    .business-module-choice:has(input:checked) { border-color: #9eafea; background: #f4f6ff; }
    .business-switch { min-width: 225px; }
    .metric-label { color: var(--owner-muted); font-size: .82rem; }
    .metric-value { color: var(--owner-ink); font-size: 1.55rem; font-weight: 750; letter-spacing: -.02em; }
    .metric-icon { display: grid; place-items: center; width: 40px; height: 40px; border-radius: 12px; color: #3157d5; background: #eef2ff; font-weight: 700; }
    .module-card { border: 1px solid #e8ecf4; border-radius: 12px; }
    .module-dot { width: 9px; height: 9px; border-radius: 50%; background: #31aa75; display: inline-block; }
    .section-title { font-size: 1rem; font-weight: 700; }
    .table-responsive { border: 1px solid #e8ecf4; border-radius: 12px; }
    #cashiers { display: flow-root; height: auto; scroll-margin-top: 84px; line-height: 1.5; }
    .cashier-heading { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: start; gap: 12px; margin-bottom: 16px; }
    .cashier-heading-copy { min-width: 0; }
    .cashier-heading-copy p { max-width: 70ch; line-height: 1.5; overflow-wrap: anywhere; }
    .cashier-layout { display: grid; grid-template-columns: minmax(260px, 0.8fr) minmax(0, 1.2fr); align-items: start; gap: 16px; }
    .cashier-create { background: #f8f9fc; border: 1px solid #e7ebf3; border-radius: 14px; }
    .cashier-create, .cashier-list-column { min-width: 0; }
    .cashier-create .form-control, .cashier-create .form-select { min-height: 42px; }
    .cashier-list { display: grid; gap: 12px; }
    .cashier-card { min-width: 0; border: 1px solid #e5e9f2; border-radius: 14px; padding: 16px; background: #fff; }
    .cashier-card .min-w-0 { min-width: 0; }
    .cashier-avatar { flex: 0 0 42px; width: 42px; height: 42px; display: grid; place-items: center; border-radius: 13px; background: #eef2ff; color: #3157d5; font-weight: 750; }
    .cashier-email { overflow-wrap: anywhere; }
    .cashier-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
    .cashier-password-panel { width: min(100%, 360px); }
    .cashier-password-panel[open] { padding: 10px; background: #f8f9fc; border: 1px solid #e5e9f2; border-radius: 10px; }
    .cashier-password-panel summary { cursor: pointer; font-weight: 600; }
    .cashier-password-panel[open] summary { margin-bottom: 10px; }
    .cashier-empty { min-height: 190px; border: 1px dashed #d5dceb; border-radius: 14px; background: #fafbfe; }
    .owner-footer { border-top: 1px solid #e5e9f2; background: #fff; color: var(--owner-muted); }
    .owner-footer .container { min-height: 64px; }
    .app-shell { min-height: 100vh; display: grid; grid-template-columns: 250px minmax(0, 1fr); align-items: stretch; }
    .app-content { min-width: 0; display: flex; flex-direction: column; }
    .app-sidebar { position: sticky; top: 0; height: 100vh; padding: 20px 14px; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; background: #111d36; color: #e8eeff; z-index: 1040; }
    .app-sidebar-brand { display: flex; align-items: center; gap: 11px; min-width: 0; padding: 3px 5px 18px; text-decoration: none; color: inherit; }
    .app-sidebar-mark { flex: 0 0 40px; width: 40px; height: 40px; display: grid; place-items: center; border-radius: 13px; background: #4269e8; color: white; font-weight: 800; }
    .app-sidebar-brand-copy { min-width: 0; display: grid; }
    .app-sidebar-brand-copy strong, .app-sidebar-brand-copy small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .app-sidebar-brand-copy small { color: #aab6d1; }
    .app-sidebar-caption { padding: 8px 10px 4px; color: #8493b2; font-size: .68rem; font-weight: 750; letter-spacing: .1em; }
    .app-sidebar-nav { display: grid; gap: 5px; }
    .app-sidebar-link { display: flex; align-items: center; gap: 11px; padding: 10px 11px; border-radius: 10px; text-decoration: none; color: #c7d1e7; font-size: .9rem; font-weight: 600; transition: background .15s ease, color .15s ease; }
    .app-sidebar-link:hover { background: #ffffff12; color: #fff; }
    .app-sidebar-link.active { background: #3157d5; color: #fff; box-shadow: 0 5px 14px #07122c45; }
    .app-sidebar-icon { flex: 0 0 25px; width: 25px; height: 25px; display: grid; place-items: center; border: 1px solid #ffffff20; border-radius: 8px; font-size: .72rem; font-weight: 800; }
    .app-sidebar-icon svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .app-sidebar-footer { display: grid; gap: 3px; margin-top: auto; padding: 13px 10px 3px; border-top: 1px solid #ffffff1c; color: #aab6d1; font-size: .75rem; }
    .app-sidebar-user { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #fff; font-weight: 650; }
    .app-content > main { flex: 1 0 auto; }
    .app-content section[id] { scroll-margin-top: 64px; }
    @media (max-width: 575.98px) {
      .navbar .container { gap: 8px; }
      .tenant-brand { max-width: 60vw; }
      main.container { padding-left: 14px; padding-right: 14px; }
      .welcome-panel .card-body { padding: 1.25rem !important; }
      .dashboard-panel-heading { grid-template-columns: minmax(0, 1fr); }
      .dashboard-panel-heading > .badge, .dashboard-panel-heading > a { justify-self: start; }
      .business-settings { grid-template-columns: minmax(0, 1fr); }
      .business-switch { min-width: 0; width: 100%; }
    }
    @media (max-width: 991.98px) {
      .app-shell { display: flex; flex-direction: column; }
      .app-sidebar { position: sticky; top: 0; width: 100%; height: auto; padding: 8px 12px; overflow: hidden; }
      .app-sidebar-brand, .app-sidebar-caption, .app-sidebar-footer { display: none; }
      .app-sidebar-nav { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: thin; }
      .app-sidebar-link { flex: 0 0 auto; padding: 8px 11px; font-size: .82rem; }
      .app-sidebar-icon { display: none; }
      .app-content { width: 100%; }
      .cashier-heading { grid-template-columns: minmax(0, 1fr); }
      .cashier-heading > .badge { justify-self: start; }
      .cashier-layout { grid-template-columns: minmax(0, 1fr); }
      .dashboard-grid { grid-template-columns: minmax(0, 1fr); }
    }
  </style>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<div class="app-content">
<nav class="navbar bg-white border-bottom shadow-sm">
  <div class="container page-wrap d-flex justify-content-between">
    <a class="text-decoration-none d-flex align-items-center gap-2 tenant-brand" href="<?= $e($base) ?>/owner">
      <span class="brand-mark" aria-hidden="true">K</span>
      <span class="text-truncate">
        <span class="d-block fw-bold text-dark text-truncate"><?= $e($tenant['name']) ?></span>
        <span class="d-block small text-muted"><?= $e($branch['name']) ?></span>
      </span>
    </a>
    <div class="d-flex align-items-center gap-2">
      <span class="small text-muted d-none d-sm-inline"><?= $e($ownerName) ?></span>
      <?php if (count($businesses) > 1): ?>
        <form method="post" action="<?= $e($base) ?>/owner/business/switch" class="d-flex align-items-center gap-2 m-0 business-switch">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <label for="businessSwitch" class="visually-hidden">Pilih usaha aktif</label>
          <select class="form-select form-select-sm" id="businessSwitch" name="tenant_id" aria-label="Pilih usaha aktif" onchange="this.form.requestSubmit()">
            <?php foreach ($businesses as $business): ?>
              <option value="<?= (int) $business['id'] ?>" <?= (int) $business['id'] === (int) $tenant['id'] ? 'selected' : '' ?>><?= $e($business['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
      <?php endif; ?>
    </div>
  </div>
</nav>

<main class="container page-wrap py-4">
  <section class="card welcome-panel mb-4" id="dashboard">
    <div class="card-body p-4 p-lg-5 position-relative" style="z-index:1">
      <div class="row align-items-center g-3">
        <div class="col-lg-8">
          <div class="small text-white-50 mb-2"><?= $e($today) ?> · Dashboard pemilik</div>
          <h1 class="h3 fw-bold mb-2">Selamat datang, <?= $e($ownerName) ?></h1>
          <p class="mb-0 text-white-50">Pantau usaha dan buka operasional kasir dari satu tempat.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
          <?php if (!$expired && in_array($status, ['active', 'trial'], true)): ?>
            <a class="btn btn-light btn-lg fw-semibold px-4" href="<?= $e($base) ?>/pos">Buka kasir <span aria-hidden="true">&rarr;</span></a>
            <a class="btn btn-outline-light btn-lg ms-lg-2 mt-2 mt-lg-0" href="<?= $e($base) ?>/store">Kelola katalog &amp; layanan</a>
            <a class="btn btn-outline-light btn-lg ms-lg-2 mt-2 mt-lg-0" href="<?= $e($base) ?>/operations">Shift, pembelian &amp; stok</a>
          <?php else: ?>
            <span class="btn btn-light btn-lg disabled" aria-disabled="true">Kasir tidak aktif</span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php if ($ownerFlash): ?>
    <div class="alert alert-<?= $e($ownerFlash['type'] ?? 'info') ?> py-2" role="status"><?= $e($ownerFlash['message'] ?? '') ?></div>
  <?php endif; ?>

  <?php if ($expired || in_array($status, ['suspended', 'expired'], true)): ?>
    <div class="alert alert-danger d-flex flex-wrap align-items-center justify-content-between gap-2" role="alert">
      <span><strong>Akses kasir dijeda.</strong> <?= $expired ? 'Masa langganan toko telah berakhir.' : 'Status toko saat ini ' . $e($statusLabel) . '.' ?> Hubungi administrator untuk mengaktifkan kembali layanan.</span>
    </div>
  <?php elseif ($status === 'trial'): ?>
    <div class="alert alert-primary d-flex flex-wrap align-items-center justify-content-between gap-2" role="status">
      <span><strong>Anda sedang mencoba <?= $e($tenant['plan_name']) ?>.</strong>
        <?php if ($daysLeft !== null): ?><?= $daysLeft ?> hari tersisa dalam masa uji coba.<?php else: ?>Masa uji coba aktif.<?php endif; ?>
      </span>
      <span class="small">Untuk berlangganan atau memperpanjang, hubungi administrator.</span>
    </div>
  <?php endif; ?>

  <section class="business-settings mb-4" id="profile">
    <form method="post" action="<?= $e($base) ?>/owner/business/profile" class="business-settings-card p-3 p-lg-4">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div class="mb-3">
        <h2 class="section-title mb-1">Profil usaha &amp; bidang</h2>
        <p class="small text-muted mb-0">Nama dan alamat cabang terpilih akan dicetak pada nota.</p>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold mb-1" for="businessName">Nama usaha</label>
          <input class="form-control" id="businessName" name="business_name" maxlength="150" required value="<?= $e($tenant['name']) ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold mb-1" for="businessPhone">Telepon usaha</label>
          <input class="form-control" id="businessPhone" name="phone" maxlength="30" value="<?= $e($tenant['phone'] ?? '') ?>" autocomplete="tel">
        </div>
        <div class="col-12">
          <label class="form-label small fw-semibold mb-1" for="businessAddress">Alamat cabang / alamat pada nota</label>
          <textarea class="form-control" id="businessAddress" name="address" rows="2" maxlength="1000"><?= $e($branch['address'] ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold mb-1" for="branchTaxPct">Tarif pajak (%)</label>
          <input class="form-control" id="branchTaxPct" name="tax_pct" type="number" min="0" max="100" step="0.01" required value="<?= $e($branch['tax_pct']) ?>">
        </div>
        <div class="col-md-8">
          <label class="form-label small fw-semibold mb-1" for="branchTaxMode">Cara menghitung pajak</label>
          <select class="form-select" id="branchTaxMode" name="tax_mode" required>
            <option value="none" <?= $branch['tax_mode'] === 'none' ? 'selected' : '' ?>>Tidak dikenakan pajak</option>
            <option value="exclusive" <?= $branch['tax_mode'] === 'exclusive' ? 'selected' : '' ?>>Tambahkan pajak ke total (ditagihkan saat checkout)</option>
            <option value="inclusive" <?= $branch['tax_mode'] === 'inclusive' ? 'selected' : '' ?>>Pajak sudah termasuk harga (total tidak bertambah)</option>
          </select>
          <div class="form-text">Pilih “Tidak dikenakan pajak” hanya jika transaksi/usaha Anda memang tidak wajib mengenakan pajak menurut ketentuan yang berlaku. Jika pajak berlaku, pilih cara penagihannya. Pengaturan berlaku untuk transaksi baru di cabang terpilih.</div>
        </div>
        <div class="col-12">
          <div class="small fw-semibold mb-2">Bidang usaha aktif pada menu kasir</div>
          <div class="row g-2">
            <?php foreach ($availableModules as $module): ?>
              <div class="col-sm-6">
                <label class="business-module-choice d-flex align-items-start gap-2 p-2">
                  <input class="form-check-input mt-1" type="checkbox" name="modules[]" value="<?= $e($module['code']) ?>" <?= $module['active'] ? 'checked' : '' ?>>
                  <span><span class="d-block small fw-semibold"><?= $e($module['name']) ?></span><span class="d-block text-muted" style="font-size:.75rem"><?= $e($module['summary']) ?></span></span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="col-12 d-flex flex-wrap justify-content-between align-items-center gap-2">
          <span class="small text-muted">Pilihan modul dibatasi paket bersama.</span>
          <button class="btn btn-primary" type="submit">Simpan profil usaha</button>
        </div>
      </div>
    </form>

    <form method="post" action="<?= $e($base) ?>/owner/businesses" class="business-settings-card p-3 p-lg-4" id="newBusiness">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <div class="mb-3">
        <h2 class="section-title mb-1">Tambah usaha lain</h2>
        <p class="small text-muted mb-0">Setiap usaha memiliki produk, kasir, cabang, dan nota sendiri. Paket dan kuota digunakan bersama.</p>
      </div>
      <div class="small text-muted mb-3">Usaha terdaftar: <?= number_format(count($businesses), 0, ',', '.') ?> · Kuota cabang terpakai: <?= number_format($activeBranches, 0, ',', '.') ?><?= $branchLimit !== null ? ' / ' . number_format($branchLimit, 0, ',', '.') : '' ?></div>
      <div class="small text-muted mb-3">Setiap usaha baru memakai minimal satu kuota cabang dari paket bersama.</div>
      <div class="mb-3">
        <label class="form-label small fw-semibold mb-1" for="newBusinessName">Nama usaha</label>
        <input class="form-control" id="newBusinessName" name="business_name" maxlength="150" required placeholder="Contoh: Kedai Kopi">
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold mb-1" for="newBranchName">Nama cabang pertama</label>
        <input class="form-control" id="newBranchName" name="branch_name" maxlength="150" required value="Cabang Utama">
      </div>
      <div class="row g-3 mb-3">
        <div class="col-sm-6">
          <label class="form-label small fw-semibold mb-1" for="newBusinessPhone">Telepon</label>
          <input class="form-control" id="newBusinessPhone" name="phone" maxlength="30" autocomplete="tel">
        </div>
        <div class="col-sm-6">
          <label class="form-label small fw-semibold mb-1" for="newBusinessAddress">Alamat</label>
          <input class="form-control" id="newBusinessAddress" name="address" maxlength="1000">
        </div>
      </div>
      <div class="small fw-semibold mb-2">Pilih bidang usaha</div>
      <div class="row g-2 mb-3">
        <?php foreach ($availableModules as $module): ?>
          <div class="col-sm-6">
            <label class="business-module-choice d-flex align-items-start gap-2 p-2">
              <input class="form-check-input mt-1" type="checkbox" name="modules[]" value="<?= $e($module['code']) ?>">
              <span><span class="d-block small fw-semibold"><?= $e($module['name']) ?></span><span class="d-block text-muted" style="font-size:.75rem"><?= $e($module['summary']) ?></span></span>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
      <?php $businessQuotaFull = $branchLimit !== null && $activeBranches >= $branchLimit; ?>
      <button class="btn btn-outline-primary w-100" type="submit" <?= $businessQuotaFull ? 'disabled' : '' ?>>Buat usaha dan buka dashboard</button>
      <?php if ($businessQuotaFull): ?>
        <div class="alert alert-warning small mt-3 mb-0 py-2">Kuota cabang paket bersama sudah habis. Hubungi administrator untuk upgrade paket.</div>
      <?php endif; ?>
    </form>
  </section>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
      <div class="surface-card p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-start"><span class="metric-label">Penjualan hari ini</span><span class="metric-icon" aria-hidden="true">Rp</span></div>
        <div class="metric-value mt-3"><?= $money($summary['sales_today'] ?? 0) ?></div>
        <div class="small text-muted mt-1"><?= number_format((int) ($summary['transactions_today'] ?? 0), 0, ',', '.') ?> transaksi</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="surface-card p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-start"><span class="metric-label">Penjualan bulan ini</span><span class="metric-icon" aria-hidden="true">↗</span></div>
        <div class="metric-value mt-3"><?= $money($summary['sales_month'] ?? 0) ?></div>
        <div class="small text-muted mt-1"><?= number_format($monthCount, 0, ',', '.') ?> transaksi</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="surface-card p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-start"><span class="metric-label">Produk aktif</span><span class="metric-icon" aria-hidden="true">P</span></div>
        <div class="metric-value mt-3"><?= number_format($activeProducts, 0, ',', '.') ?></div>
        <div class="small text-muted mt-1">Untuk usaha terpilih</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="surface-card p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-start"><span class="metric-label">Akun pengguna</span><span class="metric-icon" aria-hidden="true">U</span></div>
        <div class="metric-value mt-3"><?= number_format($activeUsers, 0, ',', '.') ?><?= $userLimit !== null ? ' / ' . number_format($userLimit, 0, ',', '.') : '' ?></div>
        <div class="small text-muted mt-1"><?= number_format($activeBranches, 0, ',', '.') ?> cabang<?= $branchLimit !== null ? ' dari ' . number_format($branchLimit, 0, ',', '.') : '' ?></div>
      </div>
    </div>
  </div>

  <div class="dashboard-grid">
    <section class="dashboard-primary">
      <div class="surface-card p-3 p-lg-4 mb-3">
        <div class="dashboard-panel-heading">
          <div>          <h2 class="section-title mb-1">Paket &amp; pemakaian</h2><div class="small text-muted">Batas paket berlaku bersama untuk semua usaha Anda.</div></div>
          <span class="badge text-bg-<?= $e($statusClass) ?>"><?= $e($statusLabel) ?></span>
        </div>
        <div class="d-flex justify-content-between align-items-end gap-2">
          <div><div class="h5 fw-bold mb-1"><?= $e($tenant['plan_name']) ?></div>
            <div class="small text-muted"><?= (float) $tenant['price_monthly'] == 0.0 ? 'Gratis selamanya' : $money($tenant['price_monthly']) . ' / bulan' ?></div>
          </div>
          <?php if ($tenant['subscription_ends_at'] !== null): ?>
            <div class="small text-muted text-end">Berlaku sampai<br><strong class="text-dark"><?= $e(date('d M Y', strtotime((string) $tenant['subscription_ends_at']))) ?></strong></div>
          <?php else: ?>
            <div class="small text-success text-end fw-semibold">Tanpa batas waktu</div>
          <?php endif; ?>
        </div>
        <?php if ($monthLimit !== null && $monthLimit > 0): ?>
          <div class="d-flex justify-content-between small mt-4 mb-2"><span>Kuota transaksi semua usaha</span><strong><?= number_format($accountMonthCount, 0, ',', '.') ?> / <?= number_format($monthLimit, 0, ',', '.') ?></strong></div>
          <div class="progress" role="progressbar" aria-label="Pemakaian kuota transaksi" aria-valuenow="<?= (int) $usagePct ?>" aria-valuemin="0" aria-valuemax="100" style="height:8px">
            <div class="progress-bar <?= $e($usageTone) ?>" style="width:<?= (int) $usagePct ?>%"></div>
          </div>
          <div class="small text-muted mt-2">Kuota diperbarui setiap awal bulan.</div>
        <?php else: ?>
          <div class="small text-success mt-4">Transaksi bulanan tanpa batas pada paket ini.</div>
        <?php endif; ?>
        <hr class="my-4">
        <div class="row g-2">
          <div class="col-6"><div class="small text-muted">Maksimal pengguna</div><div class="fw-semibold"><?= $userLimit !== null ? number_format($userLimit, 0, ',', '.') : 'Tanpa batas' ?></div></div>
          <div class="col-6"><div class="small text-muted">Maksimal cabang</div><div class="fw-semibold"><?= $branchLimit !== null ? number_format($branchLimit, 0, ',', '.') : 'Tanpa batas' ?></div></div>
        </div>
      </div>

      <div class="surface-card p-3 p-lg-4">
        <div class="dashboard-panel-heading">
          <div><h2 class="section-title mb-1">Transaksi hari ini</h2><div class="small text-muted">Aktivitas terbaru cabang <?= $e($branch['name']) ?>.</div></div>
          <a class="btn btn-outline-primary btn-sm" href="<?= $e($base) ?>/pos">Buka kasir</a>
        </div>
        <?php if ($recent): ?>
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light"><tr><th>Invoice</th><th class="d-none d-sm-table-cell">Jenis</th><th class="text-end">Total</th><th class="text-end">Status</th></tr></thead>
              <tbody>
              <?php foreach ($recent as $transaction): ?>
                <tr>
                  <td><div class="fw-semibold"><?= $e($transaction['invoice_no']) ?></div><div class="small text-muted"><?= $e(date('H:i', strtotime((string) $transaction['created_at']))) ?> · <?= $e($transaction['cashier_name']) ?></div></td>
                  <td class="d-none d-sm-table-cell"><?= $e($orderLabels[$transaction['order_type']] ?? $transaction['order_type']) ?></td>
                  <td class="text-end text-nowrap"><?= $money($transaction['grand_total']) ?></td>
                  <td class="text-end"><span class="badge text-bg-<?= $transaction['status'] === 'refunded' ? 'warning' : 'success' ?>"><?= $e($transaction['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="text-center py-4">
            <div class="fw-semibold">Belum ada transaksi hari ini</div>
            <div class="small text-muted mt-1">Transaksi dari kasir akan muncul di sini.</div>
            <a class="btn btn-primary btn-sm mt-3" href="<?= $e($base) ?>/pos">Mulai transaksi</a>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <aside class="dashboard-sidebar">
      <div class="surface-card p-3 p-lg-4 mb-3">
        <div class="dashboard-panel-heading">
          <div><h2 class="section-title mb-1">Bidang usaha aktif</h2><div class="small text-muted">Menu kasir menyesuaikan modul di bawah.</div></div>
          <span class="badge text-bg-light border"><?= count($modules) ?> modul</span>
        </div>
        <?php if ($modules): ?>
          <div class="d-grid gap-2">
            <?php foreach ($modules as $module): ?>
              <div class="module-card p-3 d-flex align-items-center gap-3">
                <span class="module-dot" aria-hidden="true"></span>
                <div><div class="fw-semibold"><?= $e($module['name']) ?></div><div class="small text-muted"><?= $e($module['summary']) ?></div></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="alert alert-warning mb-0">Belum ada bidang usaha aktif. Hubungi administrator.</div>
        <?php endif; ?>
      </div>

      <div class="surface-card p-3 p-lg-4">
        <h2 class="section-title mb-1">Akun &amp; cabang</h2>
        <p class="small text-muted mb-3">Akun pemilik Anda digunakan untuk pengaturan toko. Akun pengguna saat ini <?= number_format($activeUsers, 0, ',', '.') ?><?= $userLimit !== null ? ' dari ' . number_format($userLimit, 0, ',', '.') : '' ?> yang tersedia.</p>
        <div class="small text-muted">Email pemilik</div>
        <div class="fw-semibold text-break mb-3"><?= $e($tenant['email']) ?></div>
        <div class="small text-muted">Cabang aktif</div>
        <div class="fw-semibold mb-3"><?= $e($branch['name']) ?></div>
        <p class="small text-muted border-top pt-3 mb-0">Akun kasir dapat dikelola dari bagian <a href="#cashiers">Akun kasir</a>. Pembayaran atau perpanjangan langganan saat ini dibantu administrator.</p>
      </div>
    </aside>
  </div>

  <section class="surface-card p-3 p-lg-4 mt-3" id="cashiers">
    <div class="cashier-heading">
      <div class="cashier-heading-copy">
        <h2 class="section-title mb-1">Akun kasir</h2>
        <p class="small text-muted mb-0">Kasir masuk melalui halaman login biasa dengan email yang Anda daftarkan. Setiap akun hanya terhubung ke cabang yang dipilih.</p>
      </div>
      <span class="badge text-bg-light border"><?= number_format($activeUsers, 0, ',', '.') ?><?= $userLimit !== null ? ' / ' . number_format($userLimit, 0, ',', '.') : '' ?> akun aktif</span>
    </div>

    <div class="cashier-layout">
        <form method="post" action="<?= $e($base) ?>/owner/cashiers" class="cashier-create p-3 p-lg-4">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
          <div class="mb-3">
            <h3 class="h6 fw-bold mb-1">Tambah kasir</h3>
            <p class="small text-muted mb-0">Buat kredensial login untuk pegawai Anda.</p>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1" for="cashierName">Nama kasir</label>
            <input class="form-control" id="cashierName" name="name" maxlength="150" required autocomplete="name" placeholder="Nama lengkap">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1" for="cashierEmail">Email login</label>
            <input class="form-control" id="cashierEmail" name="email" type="email" maxlength="190" required autocomplete="email" placeholder="kasir@contoh.com">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1" for="cashierPassword">Kata sandi awal</label>
            <input class="form-control" id="cashierPassword" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password" placeholder="Minimal 10 karakter">
            <div class="form-text">Sampaikan kata sandi kepada kasir secara pribadi.</div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold mb-1" for="cashierBranch">Cabang akses</label>
            <select class="form-select" id="cashierBranch" name="branch_id" required>
              <?php foreach ($activeBranchesList as $activeBranch): ?>
                <option value="<?= (int) $activeBranch['id'] ?>" <?= (int) $activeBranch['id'] === (int) $branch['id'] ? 'selected' : '' ?>><?= $e($activeBranch['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php $atCapacity = $userLimit !== null && $activeUsers >= $userLimit; ?>
          <button class="btn btn-primary w-100 py-2" type="submit" <?= $atCapacity || !$activeBranchesList ? 'disabled' : '' ?>>Buat akun kasir</button>
          <?php if ($atCapacity): ?>
            <div class="alert alert-warning small mt-3 mb-0 py-2">Batas paket sudah tercapai. Nonaktifkan akun lama atau upgrade paket untuk menambah pengguna.</div>
          <?php endif; ?>
        </form>
      <div class="cashier-list-column">
        <?php if ($staffUsers): ?>
          <div class="cashier-list">
              <?php foreach ($staffUsers as $staff): ?>
                <?php
                  $cashierName = (string) $staff['name'];
                  $cashierInitials = strtoupper(function_exists('mb_substr') ? mb_substr($cashierName, 0, 1) : substr($cashierName, 0, 1));
                ?>
                <article class="cashier-card">
                  <div class="d-flex justify-content-between align-items-start gap-3">
                    <div class="d-flex align-items-center gap-3 min-w-0">
                      <span class="cashier-avatar" aria-hidden="true"><?= $e($cashierInitials) ?></span>
                      <div class="min-w-0">
                        <div class="fw-semibold text-break"><?= $e($staff['name']) ?></div>
                        <div class="cashier-email small text-muted"><?= $e($staff['email']) ?></div>
                      </div>
                    </div>
                    <span class="badge rounded-pill text-bg-<?= (int) $staff['is_active'] === 1 ? 'success' : 'secondary' ?> flex-shrink-0"><?= (int) $staff['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span>
                  </div>
                  <div class="d-flex flex-wrap gap-3 small text-muted mt-3">
                    <span><strong class="text-dark">Cabang:</strong> <?= $e($staff['branch_name'] ?? 'Cabang tidak aktif') ?></span>
                    <span><strong class="text-dark">Login terakhir:</strong> <?= $staff['last_login_at'] ? $e(date('d M Y, H:i', strtotime((string) $staff['last_login_at']))) : 'Belum pernah' ?></span>
                  </div>
                  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 border-top mt-3 pt-3">
                    <div class="cashier-actions">
                    <form method="post" action="<?= $e($base) ?>/owner/cashiers/toggle" class="m-0" onsubmit="return confirm('<?= (int) $staff['is_active'] === 1 ? 'Nonaktifkan akun kasir ini?' : 'Aktifkan kembali akun kasir ini?' ?>')">
                      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                      <input type="hidden" name="user_id" value="<?= (int) $staff['id'] ?>">
                      <button class="btn btn-outline-<?= (int) $staff['is_active'] === 1 ? 'danger' : 'success' ?> btn-sm" type="submit"><?= (int) $staff['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
                    </form>
                    </div>
                    <details class="cashier-password-panel">
                      <summary class="small text-primary">Reset kata sandi</summary>
                      <form method="post" action="<?= $e($base) ?>/owner/cashiers/password" onsubmit="return confirm('Ganti kata sandi kasir ini? Sesi yang aktif akan berakhir.')">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="user_id" value="<?= (int) $staff['id'] ?>">
                        <label class="visually-hidden" for="newPassword<?= (int) $staff['id'] ?>">Kata sandi baru</label>
                        <input class="form-control form-control-sm mb-2" id="newPassword<?= (int) $staff['id'] ?>" name="password" type="password" minlength="10" maxlength="72" required autocomplete="new-password" placeholder="Kata sandi baru">
                        <label class="visually-hidden" for="confirmPassword<?= (int) $staff['id'] ?>">Ulangi kata sandi baru</label>
                        <input class="form-control form-control-sm mb-2" id="confirmPassword<?= (int) $staff['id'] ?>" name="password_confirmation" type="password" minlength="10" maxlength="72" required autocomplete="new-password" placeholder="Ulangi kata sandi baru">
                        <button class="btn btn-primary btn-sm w-100" type="submit">Simpan kata sandi</button>
                      </form>
                    </details>
                  </div>
                </article>
              <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="cashier-empty text-center p-4 d-flex flex-column align-items-center justify-content-center">
            <span class="cashier-avatar mb-3" aria-hidden="true">+</span>
            <div class="fw-semibold">Belum ada akun kasir</div>
            <div class="small text-muted mt-1">Lengkapi form di samping untuk membuat akun login pegawai pertama.</div>
          </div>
        <?php endif; ?>
      </div>
    </div>
    <div class="small text-muted mt-3">Untuk keamanan, pemilik hanya bisa membuat akun berperan sebagai kasir. Menonaktifkan akun akan menolak login dan akses kasir selanjutnya.</div>
  </section>
</main>

<footer class="owner-footer mt-4">
  <div class="container page-wrap d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
    <span class="small">&copy; <?= date('Y') ?> <?= $e($tenant['name']) ?> · Dashboard pemilik</span>
    <span class="small">Kelola usaha dengan lebih mudah.</span>
  </div>
</footer>
</div>
</div>
</body>
</html>
