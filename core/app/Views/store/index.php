<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($value): string => 'Rp' . number_format((float) $value, 0, ',', '.');
$statusLabel = static fn($value): string => ucwords(str_replace('_', ' ', (string) $value));
$typeModule = ['goods' => 'retail', 'menu' => 'fnb', 'medicine' => 'pharmacy', 'laundry' => 'laundry', 'print' => 'printing', 'service' => 'service'];
$typePricing = [
    'goods' => ['unit' => 'Per unit', 'fixed' => 'Harga tetap'],
    'menu' => ['unit' => 'Per porsi/unit', 'fixed' => 'Harga tetap'],
    'medicine' => ['unit' => 'Per unit', 'fixed' => 'Harga tetap'],
    'laundry' => ['per_kg' => 'Per kilogram', 'unit' => 'Per item', 'fixed' => 'Harga tetap'],
    'print' => ['per_m2' => 'Per meter persegi'],
    'service' => ['per_hour' => 'Per jam', 'unit' => 'Per unit', 'fixed' => 'Harga tetap'],
];
$orderLabels = ['dine_in' => 'Makan di tempat', 'takeaway' => 'Bawa pulang', 'laundry' => 'Laundry', 'printing' => 'Percetakan', 'service' => 'Jasa'];
$enabledOrderTypes = [];
foreach ($fulfillmentSteps as $type => $_steps) {
    if (($type === 'dine_in' || $type === 'takeaway') ? in_array('fnb', $activeModules, true) : in_array($type === 'dine_in' ? 'fnb' : $type, $activeModules, true)) {
        $enabledOrderTypes[$type] = $orderLabels[$type] ?? $statusLabel($type);
    }
}
$sidebarBase = $base;
$sidebarBusiness = $context['tenant_name'];
$sidebarBranch = $context['branch_name'];
$sidebarRole = $context['role'];
$sidebarUser = \App\Core\Auth::name();
$sidebarPage = 'store';
$sidebarActive = 'catalog';
$sidebarHasOrders = (bool) $enabledOrderTypes;
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Operasional Toko - <?= $e($context['tenant_name']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root { --ink: #17243b; --muted: #738096; --blue: #3157d5; }
    body { min-height: 100vh; display: flex; flex-direction: column; background: #f3f5fa; color: var(--ink); }
    main { flex: 1 0 auto; width: 100%; }
    .page-wrap { max-width: 1500px; }
    .surface { min-width: 0; background: #fff; border: 1px solid #e5e9f2; border-radius: 16px; }
    .brand-mark { display: grid; place-items: center; width: 38px; height: 38px; border-radius: 12px; color: #fff; background: var(--blue); font-weight: 800; }
    .kicker { color: var(--blue); font-size: .72rem; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
    .section-title { font-size: 1rem; font-weight: 750; }
    .table-responsive { border: 1px solid #e8ecf4; border-radius: 12px; }
    .operation-card { border: 1px solid #e5e9f2; border-radius: 13px; background: #fbfcff; }
    .order-card { border: 1px solid #e5e9f2; border-radius: 13px; }
    .order-card.overdue { border-color: #f0b6b6; background: #fffafa; }
    .low-stock { color: #a56300; background: #fff6dc; }
    .store-footer { border-top: 1px solid #e5e9f2; background: #fff; color: var(--muted); }
    .type-fields { display: none; }
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
    @media(max-width:991.98px) {
      .app-shell { display: flex; flex-direction: column; }
      .app-sidebar { position: sticky; top: 0; width: 100%; height: auto; padding: 8px 12px; overflow: hidden; }
      .app-sidebar-brand, .app-sidebar-caption, .app-sidebar-footer { display: none; }
      .app-sidebar-nav { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: thin; }
      .app-sidebar-link { flex: 0 0 auto; padding: 8px 11px; font-size: .82rem; }
      .app-sidebar-icon { display: none; }
      .app-content { width: 100%; }
    }
    @media (max-width: 575.98px) { main.container { padding-left: 12px; padding-right: 12px; } }
  </style>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<div class="app-content">
<nav class="navbar bg-white border-bottom shadow-sm">
  <div class="container-fluid page-wrap d-flex justify-content-between gap-3 py-2">
    <a class="d-flex align-items-center gap-2 text-decoration-none text-dark min-w-0" href="<?= $e($base) ?>/store">
      <span class="brand-mark" aria-hidden="true">K</span>
      <span class="text-truncate"><span class="fw-bold d-block text-truncate"><?= $e($context['tenant_name']) ?></span><span class="small text-muted"><?= $e($context['branch_name']) ?> · Manajemen toko</span></span>
    </a>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="<?= $e($base) ?>/pos">Buka kasir</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/owner">Dashboard</a>
    </div>
  </div>
</nav>
<main class="container-fluid page-wrap py-4">
  <header class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div><div class="kicker">Operasional toko</div><h1 class="h3 fw-bold mb-1">Kelola katalog dan layanan</h1><p class="text-muted mb-0">Katalog, stok, pelanggan, dan antrean ini hanya berlaku untuk <strong><?= $e($context['tenant_name']) ?></strong>.</p></div>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($activeModules as $module): ?><span class="badge text-bg-light border text-dark"><?= $e($moduleLabels[$module] ?? $module) ?></span><?php endforeach; ?>
    </div>
  </header>

  <?php if ($flash): ?><div class="alert alert-<?= $e($flash['type'] ?? 'info') ?> py-2" role="status"><?= $e($flash['message'] ?? '') ?></div><?php endif; ?>

  <section id="catalog" class="surface p-3 p-lg-4 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
      <div><h2 class="section-title mb-1">Katalog produk & layanan</h2><p class="small text-muted mb-0">Tambahkan item yang sesuai bidang aktif; harga final kasir selalu dihitung ulang server.</p></div>
      <span class="small text-muted">Jenis produk: <?= count($availableTypes) ?></span>
    </div>
    <?php if ($availableTypes): ?>
    <form method="post" action="<?= $e($base) ?>/store/products" class="operation-card p-3 mb-4">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <h3 class="h6 fw-bold mb-3">Tambah produk / layanan</h3>
      <div class="row g-3">
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="productName">Nama produk / layanan</label><input class="form-control" id="productName" name="name" required maxlength="190"></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="productType">Bidang produk</label><select class="form-select" id="productType" name="product_type" required><?php foreach ($availableTypes as $type => $label): ?><option value="<?= $e($type) ?>"><?= $e($label) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="pricingModel">Cara menentukan harga</label><select class="form-select" id="pricingModel" name="pricing_model" required></select></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="productPrice">Harga jual (Rp)</label><input class="form-control" id="productPrice" name="price" type="number" min="0" max="9999999999999" step="0.01" required></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="costPrice">Harga modal (Rp)</label><input class="form-control" id="costPrice" name="cost_price" type="number" min="0" max="9999999999999" step="0.01" value="0"></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="productUnit">Satuan</label><input class="form-control" id="productUnit" name="unit" maxlength="20" required value="pcs" placeholder="pcs, porsi, kg, jam"></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="productCategory">Kategori (opsional)</label><input class="form-control" id="productCategory" name="category" maxlength="120" placeholder="Kategori baru atau yang sudah ada"></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="productSku">SKU</label><input class="form-control" id="productSku" name="sku" maxlength="60"></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="productBarcode">Barcode</label><input class="form-control" id="productBarcode" name="barcode" maxlength="64"></div>
        <div class="col-md-6 col-xl-4"><label class="form-label small fw-semibold" for="minStock">Peringatan stok minimum</label><input class="form-control" id="minStock" name="min_stock" type="number" min="0" step="0.001" value="0"></div>

        <div class="col-12 type-fields" data-type="print">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label small fw-semibold" for="material">Bahan</label><input class="form-control" id="material" name="material" maxlength="100" placeholder="Flexi, art paper, vinyl"></div>
            <div class="col-md-4"><label class="form-label small fw-semibold" for="maxWidth">Lebar bahan maksimum (cm)</label><input class="form-control" id="maxWidth" name="max_width_cm" type="number" min="0" step="0.01"></div>
            <div class="col-md-4"><label class="form-label small fw-semibold" for="minArea">Luas minimum tagihan (m²)</label><input class="form-control" id="minArea" name="min_area_m2" type="number" min="0" step="0.0001" value="0"></div>
            <div class="col-12"><label class="form-label small fw-semibold" for="finishing">Pilihan finishing</label><input class="form-control" id="finishing" name="finishing_options" placeholder="laminasi, cutting, jilid"><div class="form-text">Pisahkan dengan koma; pilihan akan tersedia saat transaksi.</div></div>
          </div>
        </div>
        <div class="col-12 type-fields" data-type="laundry">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label small fw-semibold" for="laundryServices">Pilihan layanan</label><input class="form-control" id="laundryServices" name="services" placeholder="reguler, express, dry_clean"><div class="form-text">Pisahkan dengan koma.</div></div>
            <div class="col-md-6"><label class="form-label small fw-semibold" for="expressSurcharge">Tambahan ekspres (%)</label><input class="form-control" id="expressSurcharge" name="express_surcharge_pct" type="number" min="0" max="100" step="0.01" value="0"></div>
          </div>
        </div>
        <div class="col-12 type-fields" data-type="medicine">
          <div class="row g-3">
            <div class="col-md-3"><label class="form-label small fw-semibold" for="genericName">Nama generik</label><input class="form-control" id="genericName" name="generic_name" maxlength="100"></div>
            <div class="col-md-3"><label class="form-label small fw-semibold" for="strength">Kekuatan</label><input class="form-control" id="strength" name="strength" maxlength="60" placeholder="500 mg"></div>
            <div class="col-md-3"><label class="form-label small fw-semibold" for="dosageForm">Bentuk sediaan</label><input class="form-control" id="dosageForm" name="dosage_form" maxlength="60" placeholder="tablet, sirup"></div>
            <div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" id="requiresPrescription" name="requires_prescription" type="checkbox"><label class="form-check-label" for="requiresPrescription">Wajib resep dokter</label></div></div>
          </div>
        </div>
        <div class="col-12 type-fields" data-type="menu">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label small fw-semibold" for="station">Stasiun dapur</label><input class="form-control" id="station" name="station" maxlength="60" placeholder="Bar, hot kitchen"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold" for="prepMinutes">Estimasi persiapan (menit)</label><input class="form-control" id="prepMinutes" name="prep_minutes" type="number" min="1" max="1440"></div>
          </div>
        </div>
        <div class="col-12 type-fields" data-type="service">
          <div class="row g-3">
            <div class="col-md-4"><label class="form-label small fw-semibold" for="minDuration">Durasi minimum (menit)</label><input class="form-control" id="minDuration" name="min_duration_minutes" type="number" min="0" max="1440" value="0"></div>
            <div class="col-md-4"><label class="form-label small fw-semibold" for="rounding">Pembulatan durasi (menit)</label><input class="form-control" id="rounding" name="rounding_minutes" type="number" min="1" max="1440" value="1"></div>
            <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" id="requiresStaff" name="requires_staff" type="checkbox"><label class="form-check-label" for="requiresStaff">Wajib pilih petugas</label></div></div>
          </div>
        </div>
        <div class="col-12">
          <div class="row g-3 align-items-end">
            <div class="col-md-4" id="stockTrackingField"><div class="form-check"><input class="form-check-input" id="trackStock" name="track_stock" type="checkbox" checked><label class="form-check-label fw-semibold" for="trackStock">Lacak stok per cabang</label></div></div>
            <div class="col-md-4 type-fields" data-type="medicine" id="batchTrackingField"><div class="form-check"><input class="form-check-input" id="trackBatch" name="track_batch" type="checkbox"><label class="form-check-label fw-semibold" for="trackBatch">Lacak batch & kedaluwarsa (FEFO)</label></div></div>
            <div class="col-md-4" id="initialStockField"><label class="form-label small fw-semibold" for="initialQty">Stok awal cabang ini</label><input class="form-control" id="initialQty" name="initial_qty" type="number" min="0" step="0.001" value="0"></div>
          </div>
          <div class="row g-3 mt-1 type-fields" data-type="medicine" id="initialBatchFields">
            <div class="col-md-6"><label class="form-label small fw-semibold" for="initialBatch">Nomor batch awal</label><input class="form-control" id="initialBatch" name="batch_number" maxlength="60"></div>
            <div class="col-md-6"><label class="form-label small fw-semibold" for="initialExpiry">Kedaluwarsa batch awal</label><input class="form-control" id="initialExpiry" name="expired_at" type="date" min="<?= $e(date('Y-m-d')) ?>"></div>
          </div>
        </div>
        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary px-4" type="submit">Simpan ke katalog</button></div>
      </div>
    </form>
    <?php else: ?><div class="alert alert-warning">Belum ada bidang usaha aktif pada toko ini. Aktifkan modul di profil usaha sebelum menambah katalog.</div><?php endif; ?>

    <?php if ($products): ?>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0">
      <thead class="table-light"><tr><th>Produk / layanan</th><th>SKU / Barcode</th><th>Harga</th><th>Stok cabang</th><th>Status</th><th></th></tr></thead>
      <tbody><?php foreach ($products as $product):
        $type = (string) $product['product_type']; $tracks = (int) $product['track_stock'] === 1; $trackBatch = (int) $product['track_batch'] === 1;
        $stock = (float) $product['stock_qty']; $price = $product['variant_price'] ?? $product['base_price'];
        $low = $tracks && $stock <= (float) $product['min_stock'];
      ?>
        <tr>
          <td><div class="fw-semibold"><?= $e($product['name']) ?></div><div class="small text-muted"><?= $e($productLabels[$type] ?? $type) ?><?= $product['category_name'] ? ' · ' . $e($product['category_name']) : '' ?> · <?= $e($product['unit']) ?></div></td>
          <td class="small"><div><?= $e($product['sku'] ?? '-') ?></div><div class="text-muted"><?= $e($product['barcode'] ?? '') ?></div></td>
          <td class="text-nowrap"><?= $money($price) ?><?= $type === 'print' ? '/m²' : '' ?></td>
          <td><?php if (!$tracks): ?><span class="text-muted">Tidak dilacak</span><?php else: ?><span class="badge <?= $low ? 'low-stock' : 'text-bg-light border text-dark' ?>"><?= number_format($stock, 3, ',', '.') ?><?= $trackBatch ? ' · batch' : '' ?></span><?php if ($low): ?><div class="small text-warning-emphasis">Stok minimum</div><?php endif; ?><?php endif; ?></td>
          <td><span class="badge text-bg-<?= (int) $product['is_active'] === 1 ? 'success' : 'secondary' ?>"><?= (int) $product['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
          <td class="text-nowrap">
            <details class="mb-2"><summary class="small text-primary" role="button">Edit data</summary>
              <form method="post" action="<?= $e($base) ?>/store/products/update" class="operation-card p-2 mt-2">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><input type="hidden" name="variant_id" value="<?= (int) $product['variant_id'] ?>">
                <label class="form-label small mb-1" for="editName<?= (int) $product['id'] ?>">Nama</label><input class="form-control form-control-sm mb-2" id="editName<?= (int) $product['id'] ?>" name="name" maxlength="190" required value="<?= $e($product['name']) ?>">
                <label class="form-label small mb-1" for="editCategory<?= (int) $product['id'] ?>">Kategori</label><input class="form-control form-control-sm mb-2" id="editCategory<?= (int) $product['id'] ?>" name="category" maxlength="120" value="<?= $e($product['category_name'] ?? '') ?>">
                <div class="row g-2"><div class="col-6"><label class="form-label small mb-1" for="editPrice<?= (int) $product['id'] ?>">Harga</label><input class="form-control form-control-sm" id="editPrice<?= (int) $product['id'] ?>" name="price" type="number" min="0" step="0.01" required value="<?= $e($product['variant_price'] ?? $product['base_price']) ?>"></div>
                  <div class="col-6"><label class="form-label small mb-1" for="editCost<?= (int) $product['id'] ?>">Modal</label><input class="form-control form-control-sm" id="editCost<?= (int) $product['id'] ?>" name="cost_price" type="number" min="0" step="0.01" required value="<?= $e($product['variant_cost'] ?? $product['cost_price']) ?>"></div></div>
                <label class="form-label small mt-2 mb-1" for="editSku<?= (int) $product['id'] ?>">SKU</label><input class="form-control form-control-sm mb-2" id="editSku<?= (int) $product['id'] ?>" name="sku" maxlength="60" value="<?= $e($product['sku'] ?? '') ?>">
                <label class="form-label small mb-1" for="editBarcode<?= (int) $product['id'] ?>">Barcode</label><input class="form-control form-control-sm mb-2" id="editBarcode<?= (int) $product['id'] ?>" name="barcode" maxlength="64" value="<?= $e($product['barcode'] ?? '') ?>">
                <button class="btn btn-primary btn-sm w-100" type="submit">Simpan perubahan</button>
              </form>
            </details>
            <form method="post" action="<?= $e($base) ?>/store/products/toggle" class="m-0"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>"><button class="btn btn-outline-<?= (int) $product['is_active'] === 1 ? 'danger' : 'success' ?> btn-sm"><?= (int) $product['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button></form>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><div class="text-center text-muted py-4">Belum ada katalog. Tambahkan produk atau layanan pertama usaha ini.</div><?php endif; ?>
  </section>

  <section id="inventory" class="surface p-3 p-lg-4 mb-3">
    <div class="mb-3"><h2 class="section-title mb-1">Stok & batch</h2><p class="small text-muted mb-0">Penyesuaian dicatat sebagai pergerakan stok dan dipisahkan per cabang.</p></div>
    <div class="row g-3">
      <div class="col-lg-6">
        <form method="post" action="<?= $e($base) ?>/store/stock/adjust" class="operation-card p-3 h-100">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><h3 class="h6 fw-bold">Penyesuaian stok biasa</h3>
          <p class="small text-muted">Gunakan jumlah positif untuk stok masuk dan negatif untuk stok keluar/koreksi.</p>
          <label class="form-label small fw-semibold" for="adjustVariant">Produk terlacak stok</label>
          <select class="form-select mb-2" id="adjustVariant" name="variant_id" required><option value="">Pilih produk</option>
            <?php foreach ($products as $product): if ((int) $product['track_stock'] !== 1 || (int) $product['track_batch'] === 1 || (int) $product['is_active'] !== 1) continue; ?>
              <option value="<?= (int) $product['variant_id'] ?>"><?= $e($product['name']) ?> · <?= number_format((float) $product['stock_qty'], 3, ',', '.') ?> <?= $e($product['unit']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="row g-2"><div class="col-sm-5"><label class="form-label small fw-semibold" for="adjustQty">Perubahan jumlah</label><input class="form-control" id="adjustQty" name="quantity" type="number" step="0.001" required></div>
            <div class="col-sm-7"><label class="form-label small fw-semibold" for="adjustNote">Alasan (wajib)</label><input class="form-control" id="adjustNote" name="note" minlength="3" maxlength="255" required placeholder="Stok opname, rusak, koreksi"></div></div>
          <button class="btn btn-outline-primary mt-3" type="submit">Catat penyesuaian</button>
        </form>
      </div>
      <div class="col-lg-6">
        <form method="post" action="<?= $e($base) ?>/store/stock/batch" class="operation-card p-3 h-100">
          <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><h3 class="h6 fw-bold">Terima batch obat</h3>
          <p class="small text-muted">Stok batch dialokasikan FEFO saat kasir menjual obat dan tidak bisa dikoreksi tanpa nomor batch.</p>
          <label class="form-label small fw-semibold" for="batchVariant">Obat terlacak batch</label>
          <select class="form-select mb-2" id="batchVariant" name="variant_id" required><option value="">Pilih obat</option>
            <?php foreach ($products as $product): if ((int) $product['track_batch'] !== 1 || (int) $product['is_active'] !== 1) continue; ?>
              <option value="<?= (int) $product['variant_id'] ?>"><?= $e($product['name']) ?> · stok <?= number_format((float) $product['stock_qty'], 3, ',', '.') ?></option>
            <?php endforeach; ?>
          </select>
          <div class="row g-2">
            <div class="col-sm-4"><label class="form-label small fw-semibold" for="receiveQty">Jumlah</label><input class="form-control" id="receiveQty" name="quantity" type="number" min="0.001" step="0.001" required></div>
            <div class="col-sm-4"><label class="form-label small fw-semibold" for="receiveBatch">Nomor batch</label><input class="form-control" id="receiveBatch" name="batch_number" maxlength="60" required></div>
            <div class="col-sm-4"><label class="form-label small fw-semibold" for="receiveExpiry">Kedaluwarsa</label><input class="form-control" id="receiveExpiry" name="expired_at" type="date" min="<?= $e(date('Y-m-d')) ?>" required></div>
            <div class="col-sm-6"><label class="form-label small fw-semibold" for="receiveCost">Harga modal / unit</label><input class="form-control" id="receiveCost" name="cost_price" type="number" min="0" step="0.01" value="0"></div>
            <div class="col-sm-6"><label class="form-label small fw-semibold" for="receiveSupplier">Pemasok</label><input class="form-control" id="receiveSupplier" name="supplier_name" maxlength="150"></div>
          </div>
          <button class="btn btn-outline-primary mt-3" type="submit">Catat penerimaan batch</button>
        </form>
      </div>
    </div>
    <?php if ($batches): ?>
      <h3 class="h6 fw-bold mt-4">Batch mendekati kedaluwarsa (60 hari)</h3>
      <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Produk</th><th>Batch</th><th>Kedaluwarsa</th><th class="text-end">Sisa</th></tr></thead><tbody>
      <?php foreach ($batches as $batch): ?><tr><td><?= $e($batch['product_name']) ?></td><td><?= $e($batch['batch_number']) ?></td><td><span class="badge text-bg-warning"><?= $e($batch['expired_at']) ?></span></td><td class="text-end"><?= number_format((float) $batch['qty_remaining'], 3, ',', '.') ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </section>

  <section id="customers" class="surface p-3 p-lg-4 mb-3">
    <div class="mb-3"><h2 class="section-title mb-1">Data pelanggan</h2><p class="small text-muted mb-0">Pelanggan tersimpan per usaha; pencarian bisa langsung digunakan oleh kasir.</p></div>
    <form method="post" action="<?= $e($base) ?>/store/customers" class="operation-card p-3 mb-3">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small fw-semibold" for="customerName">Nama</label><input class="form-control" id="customerName" name="name" minlength="2" maxlength="150" required></div>
        <div class="col-md-3"><label class="form-label small fw-semibold" for="customerPhone">Telepon</label><input class="form-control" id="customerPhone" name="phone" maxlength="30"></div>
        <div class="col-md-3"><label class="form-label small fw-semibold" for="customerEmail">Email</label><input class="form-control" id="customerEmail" name="email" type="email" maxlength="190"></div>
        <div class="col-md-3"><label class="form-label small fw-semibold" for="customerAddress">Alamat</label><input class="form-control" id="customerAddress" name="address" maxlength="1000"></div>
        <div class="col-12 text-end"><button class="btn btn-outline-primary" type="submit">Tambah pelanggan</button></div>
      </div>
    </form>
    <?php if ($customers): ?><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Nama</th><th>Telepon</th><th>Email</th><th>Terdaftar</th></tr></thead><tbody>
      <?php foreach ($customers as $customer): ?><tr><td class="fw-semibold"><?= $e($customer['name']) ?></td><td><?= $e($customer['phone'] ?? '-') ?></td><td><?= $e($customer['email'] ?? '-') ?></td><td class="small text-muted"><?= $e(date('d M Y', strtotime((string) $customer['created_at']))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><div class="text-center text-muted py-3">Belum ada pelanggan tersimpan.</div><?php endif; ?>
  </section>

  <?php if ($enabledOrderTypes): ?>
  <section id="orders" class="surface p-3 p-lg-4 mb-3">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3"><div><h2 class="section-title mb-1">Antrean pengerjaan</h2><p class="small text-muted mb-0">Status diperbarui bertahap dan setiap perubahan masuk ke riwayat transaksi.</p></div><span class="badge text-bg-light border text-dark"><?= count($orders) ?> pesanan</span></div>
    <?php if ($orders): ?><div class="row g-3">
      <?php foreach ($orders as $order):
        $steps = $fulfillmentSteps[$order['order_type']] ?? [];
        $currentIndex = array_search($order['fulfillment_status'], $steps, true);
        $nextStatus = $currentIndex !== false && isset($steps[$currentIndex + 1]) ? $steps[$currentIndex + 1] : null;
        $isLate = $order['due_at'] !== null && strtotime((string) $order['due_at']) < time() && $nextStatus !== null;
      ?>
        <div class="col-lg-6 col-xxl-4"><article class="order-card p-3 h-100 <?= $isLate ? 'overdue' : '' ?>">
          <div class="d-flex justify-content-between align-items-start gap-2"><div><div class="fw-bold"><?= $e($order['invoice_no']) ?></div><div class="small text-muted"><?= $e($orderLabels[$order['order_type']] ?? $statusLabel($order['order_type'])) ?> · <?= $e($order['customer_name'] ?? 'Pelanggan umum') ?></div></div><span class="badge text-bg-<?= $nextStatus ? 'primary' : 'success' ?>"><?= $e($statusLabel($order['fulfillment_status'])) ?></span></div>
          <div class="small mt-3"><strong><?= (int) $order['item_count'] ?> item:</strong> <?= $e($order['item_names'] ?? '') ?></div>
          <div class="small text-muted mt-2">Kasir: <?= $e($order['cashier_name']) ?> · <?= $money($order['grand_total']) ?></div>
          <?php if ($order['due_at'] !== null): ?><div class="small mt-1 <?= $isLate ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $isLate ? 'Terlambat · ' : 'Target · ' ?><?= $e(date('d M Y H:i', strtotime((string) $order['due_at']))) ?></div><?php endif; ?>
          <?php if ($order['notes']): ?><div class="small text-muted mt-1">Catatan: <?= $e($order['notes']) ?></div><?php endif; ?>
          <div class="d-flex justify-content-between align-items-center mt-3">
            <div class="small text-muted">Tahap <?= $currentIndex === false ? '-' : ($currentIndex + 1) ?> / <?= count($steps) ?></div>
            <?php if ($nextStatus): ?><form method="post" action="<?= $e($base) ?>/store/orders/status" class="m-0"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="transaction_id" value="<?= (int) $order['id'] ?>"><input type="hidden" name="status" value="<?= $e($nextStatus) ?>"><button class="btn btn-sm btn-primary" type="submit">Lanjut: <?= $e($statusLabel($nextStatus)) ?></button></form><?php else: ?><span class="small text-success fw-semibold">Pengerjaan selesai</span><?php endif; ?>
          </div>
        </article></div>
      <?php endforeach; ?>
    </div><?php else: ?><div class="text-center text-muted py-4">Belum ada pesanan yang memerlukan pengerjaan.</div><?php endif; ?>
  </section>
  <?php endif; ?>
</main>
<footer class="store-footer mt-4"><div class="container-fluid page-wrap d-flex flex-wrap justify-content-between gap-2 py-3 small"><span><?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></span><span>Operasional toko · Data dipisahkan per usaha dan cabang</span></div></footer>
</div>
</div>
<script>
(function () {
  'use strict';
  const rules = <?= json_encode($typePricing, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>;
  const typeSelect = document.getElementById('productType');
  if (!typeSelect) return;
  const pricingSelect = document.getElementById('pricingModel');
  const trackStock = document.getElementById('trackStock');
  const trackBatch = document.getElementById('trackBatch');
  const stockTrackingField = document.getElementById('stockTrackingField');
  const initialStockField = document.getElementById('initialStockField');
  const initialBatchFields = document.getElementById('initialBatchFields');
  const batchTrackingField = document.getElementById('batchTrackingField');
  function refresh() {
    const type = typeSelect.value;
    pricingSelect.replaceChildren();
    Object.keys(rules[type] || {}).forEach(function (key) {
      const option = document.createElement('option');
      option.value = key;
      option.textContent = rules[type][key];
      pricingSelect.append(option);
    });
    document.querySelectorAll('.type-fields').forEach(function (section) {
      const visible = section.dataset.type === type;
      section.style.display = visible ? 'block' : 'none';
      section.querySelectorAll('input,select').forEach(function (input) {
        input.disabled = !visible;
      });
    });
    const stockable = type !== 'print' && type !== 'service';
    stockTrackingField.style.display = stockable ? '' : 'none';
    trackStock.disabled = !stockable;
    if (!stockable) trackStock.checked = false;
    batchTrackingField.style.display = type === 'medicine' ? '' : 'none';
    trackBatch.disabled = type !== 'medicine' || !trackStock.checked;
    if (type !== 'medicine' || !trackStock.checked) trackBatch.checked = false;
    initialStockField.style.display = stockable && trackStock.checked ? '' : 'none';
    initialStockField.querySelector('input').disabled = !stockable || !trackStock.checked;
    const needsBatch = type === 'medicine' && trackBatch.checked && Number(document.getElementById('initialQty').value) > 0;
    initialBatchFields.style.display = needsBatch ? 'flex' : 'none';
    initialBatchFields.querySelectorAll('input').forEach(function (input) {
      input.disabled = !needsBatch;
      input.required = needsBatch;
    });
  }
  typeSelect.addEventListener('change', refresh);
  trackStock.addEventListener('change', refresh);
  trackBatch.addEventListener('change', refresh);
  document.getElementById('initialQty').addEventListener('input', refresh);
  refresh();
})();
</script>
</body>
</html>
