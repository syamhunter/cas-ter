<?php
declare(strict_types=1);

/**
 * app/Views/pos/index.php  -  Antarmuka kasir
 *
 * Variabel yang disediakan PosController:
 *   string $csrf       token CSRF sesi
 *   array  $posConfig  {
 *       baseUrl: "",                         // "" bila di root domain, "/pos" bila di subfolder
 *       tenantName, branchName, currency: "IDR",
 *       modules: ["retail","fnb","pharmacy","laundry","printing","service"],  // modul AKTIF tenant
 *       tables: [{id, name, status}],        // meja cabang aktif (modul fnb)
 *       taxPct: 0, taxMode: "exclusive", servicePct: 0, // hanya untuk estimasi; server tetap menghitung ulang
 *       canDiscount: false                   // true bila owner/manager atau permissions.can_discount
 *   }
 *
 * Kontrak endpoint:
 *   GET  /api/products?q=<teks>|barcode=<kode>
 *        -> {success, data:{items:[{variant_id, product_id, name, variant_name, product_type, pricing_model,
 *             unit, price:"25000.00", price_per_m2:"25000.00"|null, barcode, stock:"12.500"|null,
 *             attributes:{...atribut produk...}, batches:[{id,batch_number,expired_at,qty_remaining}]}]}}
 *   POST /api/checkout  -> lihat CheckoutController (harga final selalu dihitung server)
 */

$e = static fn($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$cfgJson = json_encode(
    $posConfig,
    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
);
$sidebarBase = (string) ($posConfig['baseUrl'] ?? '');
$sidebarBusiness = (string) ($posConfig['tenantName'] ?? 'Kasir');
$sidebarBranch = (string) ($posConfig['branchName'] ?? '');
$sidebarRole = (string) ($posConfig['role'] ?? (!empty($posConfig['canManage']) ? 'owner' : 'cashier'));
$sidebarUser = \App\Core\Auth::name();
$sidebarPage = 'pos';
$sidebarActive = 'pos';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="csrf-token" content="<?= $e($csrf) ?>">
  <meta name="robots" content="noindex,nofollow">
  <title>Kasir - <?= $e($posConfig['branchName'] ?? '') ?></title>
  <!-- Disarankan menambah atribut integrity + crossorigin (hash dari getbootstrap.com) saat produksi -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root { --pos-ink: #152238; --pos-muted: #718096; --pos-primary: #3157d5; --pos-surface: #fff; }
    body { min-height: 100vh; display: flex; flex-direction: column; background: #f3f5fa; color: var(--pos-ink); padding-bottom: env(safe-area-inset-bottom, 0); }
    .navbar { min-height: 68px; }
    .navbar-brand { color: var(--pos-ink); }
    .pos-brand-mark { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 12px; color: #fff; background: var(--pos-primary); font-weight: 800; }
    .pos-nav-actions { margin-left: auto; }
    main.pos-main { flex: 1 0 auto; width: 100%; }
    .pos-page-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 4px 0 16px; }
    .pos-page-heading h1 { font-size: 1.3rem; font-weight: 750; margin: 0; letter-spacing: -.02em; }
    .pos-kicker { color: var(--pos-primary); font-size: .72rem; font-weight: 750; letter-spacing: .08em; text-transform: uppercase; }
    .pos-terminal-status { display: inline-flex; align-items: center; gap: 7px; padding: 7px 11px; border: 1px solid #dcefe5; border-radius: 999px; background: #f1fbf5; color: #21734a; font-size: .8rem; font-weight: 650; }
    .pos-terminal-status:before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: #28a46a; }
    .pos-terminal-status-closed { border-color: #f2dfb4; background: #fff8e8; color: #875d00; }
    .pos-terminal-status-closed:before { background: #d99a11; }
    .pos-toolbar { background: var(--pos-surface); border: 1px solid #e5e9f2; border-radius: 16px; padding: 14px; box-shadow: 0 4px 16px #263b6808; }
    .pos-toolbar .input-group-text { color: var(--pos-primary); border-color: #e0e6f0; }
    .pos-toolbar .form-control { border-color: #e0e6f0; }
    .pos-toolbar .form-control:focus { box-shadow: 0 0 0 .2rem #3157d51c; border-color: #9aadee; }
    .product-filters { display: flex; gap: 7px; overflow-x: auto; padding: 2px 1px 5px; scrollbar-width: thin; }
    .product-filter { flex: 0 0 auto; padding: 6px 12px; border: 1px solid #e1e6ef; border-radius: 999px; background: #fff; color: #59677d; font-size: .82rem; font-weight: 600; }
    .product-filter:hover { border-color: #a9b9ef; color: var(--pos-primary); }
    .product-filter.active { border-color: var(--pos-primary); background: var(--pos-primary); color: #fff; }
    .product-card { display: flex; flex-direction: column; justify-content: space-between; min-height: 138px; border: 1px solid #e3e8f1 !important; border-radius: 14px; background: #fff; transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease; white-space: normal; }
    .product-card:hover:not(:disabled) { transform: translateY(-2px); border-color: #b7c6fa !important; box-shadow: 0 8px 20px #263b6814; background: #fff; }
    .product-card:active { transform: scale(.98); }
    .product-card:disabled { opacity: .65; }
    .product-mark { display: grid; place-items: center; width: 34px; height: 34px; border-radius: 11px; background: #eef2ff; color: var(--pos-primary); font-weight: 750; }
    .product-card-name { display: -webkit-box; overflow: hidden; -webkit-box-orient: vertical; -webkit-line-clamp: 2; min-height: 2.5em; overflow-wrap: anywhere; }
    #productScroll { max-height: calc(100vh - 250px); overflow-y: auto; padding: 2px; }
    #cartPanel { border-color: #e5e9f2 !important; border-radius: 16px !important; overflow: hidden; }
    #cartPanel .offcanvas-header { min-height: 54px; }
    #cartPanel .cart-body { overflow-y: auto; }
    #cartPanel .offcanvas-header { background: #fff; }
    #cartPanel .border-top { background: #fff; }
    .cart-line { border-color: #e8ecf3 !important; border-radius: 12px !important; }
    #cartPanel .border-top { padding: 14px !important; }
    #tGrand { font-size: 1.35rem !important; font-weight: 800; }
    #btnCheckout { font-weight: 700; letter-spacing: .01em; }
    .stat-card { border: 1px solid #e6eaf2; border-radius: 14px; background: #fff; padding: 14px; height: 100%; }
    .stat-label { color: var(--pos-muted); font-size: .8rem; }
    .stat-value { color: var(--pos-ink); font-size: 1.2rem; font-weight: 750; }
    .table-responsive { border: 1px solid #e7ebf3; border-radius: 12px; }
    .modal-content { border: 0; border-radius: 16px; box-shadow: 0 24px 80px #13254a2b; }
    #searchInput { border-radius: 12px; }
    .input-group:has(#searchInput) .input-group-text { border-radius: 12px 0 0 12px; background: white; }
    .pos-footer { flex: 0 0 auto; border-top: 1px solid #e5e9f2; background: #fff; color: var(--pos-muted); }
    .pos-footer .container-fluid { min-height: 54px; }
    .app-shell { min-height: 100vh; display: grid; grid-template-columns: 226px minmax(0, 1fr); align-items: stretch; }
    .app-content { min-width: 0; display: flex; flex-direction: column; }
    .app-sidebar { position: sticky; top: 0; height: 100vh; padding: 18px 12px; display: flex; flex-direction: column; gap: 8px; overflow-y: auto; background: #111d36; color: #e8eeff; z-index: 1040; }
    .app-sidebar-brand { display: flex; align-items: center; gap: 11px; min-width: 0; padding: 3px 5px 18px; text-decoration: none; color: inherit; }
    .app-sidebar-mark { flex: 0 0 40px; width: 40px; height: 40px; display: grid; place-items: center; border-radius: 13px; background: #4269e8; color: white; font-weight: 800; }
    .app-sidebar-brand-copy { min-width: 0; display: grid; }
    .app-sidebar-brand-copy strong, .app-sidebar-brand-copy small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .app-sidebar-brand-copy small { color: #aab6d1; }
    .app-sidebar-caption { padding: 8px 10px 4px; color: #8493b2; font-size: .68rem; font-weight: 750; letter-spacing: .1em; }
    .app-sidebar-nav { display: grid; gap: 5px; }
    .app-sidebar-link { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 10px; text-decoration: none; color: #c7d1e7; font-size: .86rem; font-weight: 600; transition: background .15s ease, color .15s ease; }
    .app-sidebar-link:hover { background: #ffffff12; color: #fff; }
    .app-sidebar-link.active { background: #3157d5; color: #fff; box-shadow: 0 5px 14px #07122c45; }
    .app-sidebar-icon { flex: 0 0 24px; width: 24px; height: 24px; display: grid; place-items: center; border: 1px solid #ffffff20; border-radius: 8px; font-size: .7rem; font-weight: 800; }
    .app-sidebar-icon svg { width: 16px; height: 16px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
    .app-sidebar-footer { display: grid; gap: 3px; margin-top: auto; padding: 13px 10px 3px; border-top: 1px solid #ffffff1c; color: #aab6d1; font-size: .75rem; }
    .app-sidebar-user { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #fff; font-weight: 650; }
    .app-content > main { flex: 1 0 auto; }
    .app-content section[id] { scroll-margin-top: 64px; }
    @media print {
      @page { size: 80mm auto; margin: 0; }
      html { min-width: 0 !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
      body { width: var(--receipt-paper-width, 80mm) !important; min-width: 0 !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
      body * { visibility: hidden !important; }
      #receiptModal, #receiptModal * { visibility: visible !important; }
      #receiptModal { position: absolute !important; inset: 0 auto auto 0 !important; display: block !important; width: var(--receipt-paper-width, 80mm) !important; height: auto !important; overflow: visible !important; background: transparent !important; }
      #receiptModal .modal-dialog { width: var(--receipt-paper-width, 80mm) !important; max-width: var(--receipt-paper-width, 80mm) !important; margin: 0 !important; }
      #receiptModal .modal-content { width: 100% !important; border: 0 !important; box-shadow: none !important; border-radius: 0 !important; }
      #receiptModal .modal-header, #receiptModal .modal-footer { display: none !important; }
      #receiptModal .modal-body { padding: 2mm !important; overflow: visible !important; }
      #receiptBody { width: 100% !important; max-width: 100% !important; color: #000 !important; font-family: Arial, sans-serif !important; font-size: 9pt !important; overflow-wrap: anywhere !important; }
      #receiptBody .table { width: 100% !important; table-layout: fixed !important; border-collapse: collapse !important; }
      #receiptBody .table td { padding: 1mm 0 !important; border: 0 !important; vertical-align: top !important; }
      #receiptBody .table td:first-child { width: 68% !important; }
      #receiptBody .table td:last-child { width: 32% !important; text-align: right !important; }
      #receiptBody dl { margin-bottom: 0 !important; }
      #receiptBody hr { border-top: 1px dashed #000 !important; opacity: 1 !important; }
      body.receipt-paper-58 { --receipt-paper-width: 58mm; }
      body.receipt-paper-80 { --receipt-paper-width: 80mm; }
      body.receipt-paper-a4 { --receipt-paper-width: 80mm; }
      body.receipt-paper-a4 #receiptModal { left: 50% !important; transform: translateX(-50%) !important; }
    }
    @media (min-width: 992px) {
      #cartPanel { position: sticky; top: 82px; height: calc(100vh - 100px); display: flex; flex-direction: column; }
      #cartPanel .cart-body { flex: 1 1 auto; }
    }
    @media (max-width: 991.98px) {
      .app-shell { display: flex; flex-direction: column; }
      .app-sidebar { position: sticky; top: 0; width: 100%; height: auto; padding: 8px 12px; overflow: hidden; }
      .app-sidebar-brand, .app-sidebar-caption, .app-sidebar-footer { display: none; }
      .app-sidebar-nav { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: thin; }
      .app-sidebar-link { flex: 0 0 auto; padding: 8px 11px; font-size: .82rem; }
      .app-sidebar-icon { display: none; }
      .app-content { width: 100%; }
      .app-content .navbar.sticky-top { top: 44px; }
      body { padding-bottom: calc(84px + env(safe-area-inset-bottom, 0)); }
      #cartPanel.offcanvas-bottom { height: min(90dvh, 820px); border-radius: 18px 18px 0 0 !important; }
      #productScroll { max-height: none; overflow: visible; padding-bottom: 88px; }
      .pos-toolbar { padding: 9px; }
    }
    @media (max-width: 575.98px) {
      main.container-fluid { padding-left: 12px; padding-right: 12px; }
      .navbar .container-fluid { gap: 8px; }
      .pos-nav-actions { width: 100%; margin-left: 0; flex-wrap: nowrap !important; justify-content: flex-start; overflow-x: auto; padding-bottom: 2px; }
      .pos-nav-actions > * { flex: 0 0 auto; }
      .pos-nav-actions form { display: inline-flex; }
      .pos-nav-actions .form-select { max-width: 150px !important; }
      .pos-brand-copy { max-width: 100%; }
      .pos-page-heading { align-items: flex-start; }
      .pos-terminal-status { font-size: .74rem; }
      .product-card { min-height: 130px; padding: 10px !important; }
      .pos-footer .container-fluid { justify-content: center !important; text-align: center; }
    }
    .line-error { border-color: var(--bs-danger) !important; }
    .fab { bottom: calc(12px + env(safe-area-inset-bottom, 0)); right: 12px; z-index: 1030; }
  </style>
</head>
<body>

<noscript><div class="alert alert-danger m-3">Aplikasi kasir membutuhkan JavaScript.</div></noscript>

<div class="app-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<div class="app-content">
<nav class="navbar bg-white border-bottom sticky-top shadow-sm">
  <div class="container-fluid">
    <div class="d-flex align-items-center gap-2 pos-brand-copy">
      <span class="pos-brand-mark" aria-hidden="true">K</span>
      <span class="navbar-brand mb-0 h6 text-truncate">
        <?= $e($posConfig['tenantName'] ?? 'Kasir') ?>
        <small class="text-muted d-block fw-normal"><?= $e($posConfig['branchName'] ?? '') ?></small>
      </span>
    </div>
    <div class="pos-nav-actions d-flex flex-wrap gap-2 align-items-center">
      <button type="button" id="btnHolds" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#holdsModal">Ditahan <span id="holdCount" class="badge text-bg-primary">0</span></button>
      <button type="button" id="btnHold" class="btn btn-outline-secondary btn-sm">Tahan</button>
      <a class="btn btn-outline-secondary btn-sm" href="<?= $e($posConfig['baseUrl'] ?? '') ?>/transactions">Transaksi</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= $e($posConfig['baseUrl'] ?? '') ?>/reports">Laporan</a>
      <a class="btn btn-outline-success btn-sm" href="<?= $e($posConfig['baseUrl'] ?? '') ?>/operations">Shift &amp; stok</a>
      <?php if (!empty($posConfig['canManage'])): ?><a class="btn btn-outline-primary btn-sm" href="<?= $e($posConfig['baseUrl'] ?? '') ?>/store">Kelola toko</a><?php endif; ?>
      <select id="orderType" class="form-select form-select-sm" aria-label="Tipe pesanan" style="max-width:170px"></select>
      <select id="tableSelect" class="form-select form-select-sm d-none" aria-label="Meja" style="max-width:130px"></select>
    </div>
  </div>
</nav>

<main class="container-fluid py-3 pos-main">
  <div class="pos-page-heading">
    <div>
      <div class="pos-kicker">Terminal penjualan</div>
      <h1>Transaksi baru</h1>
    </div>
    <span class="pos-terminal-status <?= empty($posConfig['shiftOpen']) ? 'pos-terminal-status-closed' : '' ?>">
      <?= empty($posConfig['shiftOpen']) ? 'Shift belum dibuka' : 'Shift aktif' ?> · <?= $e($posConfig['branchName'] ?? '') ?>
    </span>
  </div>
  <?php if (empty($posConfig['shiftOpen'])): ?>
    <div class="alert alert-warning d-flex flex-wrap justify-content-between align-items-center gap-2 py-2 mb-3" role="alert">
      <span>Transaksi dinonaktifkan sampai Anda membuka shift untuk akun ini.</span>
      <a class="btn btn-sm btn-warning" href="<?= $e($posConfig['baseUrl'] ?? '') ?>/operations#shift">Buka shift sekarang</a>
    </div>
  <?php endif; ?>
  <div class="row g-3">

    <!-- KIRI: pencarian & produk -->
    <section class="col-lg-7" aria-label="Produk">
      <div class="pos-toolbar mb-3">
      <div class="input-group">
        <span class="input-group-text" aria-hidden="true">&#128269;</span>
        <input id="searchInput" type="search" class="form-control form-control-lg" autocomplete="off" autofocus
               placeholder="Cari produk atau pindai barcode..." aria-label="Cari produk atau scan barcode">
      </div>
      <div class="d-flex justify-content-between align-items-center mt-2 px-1">
        <span class="small text-muted">Cari nama/SKU atau pindai barcode, lalu tekan Enter</span>
        <span id="productCount" class="small text-muted"></span>
      </div>
      <div id="productFilters" class="product-filters mt-3" role="group" aria-label="Filter bidang usaha"></div>
      </div>
      <div id="productScroll">
        <div id="gridState" class="text-center text-muted py-5 d-none"></div>
        <div id="productGrid" class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-2"></div>
      </div>
    </section>

    <!-- KANAN: keranjang (offcanvas di layar kecil) -->
    <aside class="col-lg-5">
      <div id="cartPanel" class="offcanvas-lg offcanvas-bottom bg-white border rounded-3 shadow-sm"
           tabindex="-1" aria-labelledby="cartTitle">
        <div class="offcanvas-header border-bottom py-2">
          <h2 id="cartTitle" class="h6 mb-0">Keranjang <span id="cartCount" class="badge text-bg-secondary">0</span></h2>
          <button type="button" class="btn-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#cartPanel" aria-label="Tutup"></button>
        </div>

        <div class="offcanvas-body d-flex flex-column p-0">
          <div class="cart-body p-2 flex-grow-1">
            <div id="alertBox" class="alert alert-danger py-2 small d-none" role="alert"></div>
            <div id="cartList"></div>
            <div id="cartEmpty" class="text-center text-muted py-4">Keranjang kosong</div>
          </div>

          <div class="border-top p-2">
            <div class="mb-2 position-relative" id="customerGroup">
              <label class="form-label small mb-1" for="customerSearch">Pelanggan (opsional)</label>
              <div class="input-group input-group-sm">
                <input id="customerSearch" type="search" class="form-control" autocomplete="off" placeholder="Cari nama atau telepon">
                <button type="button" id="customerClear" class="btn btn-outline-secondary" aria-label="Hapus pilihan pelanggan">Hapus</button>
              </div>
              <div id="customerResults" class="list-group position-absolute start-0 end-0 shadow-sm d-none" style="z-index:1050;max-height:220px;overflow:auto"></div>
              <div id="customerSelected" class="form-text">Transaksi tanpa pilihan akan dicatat sebagai pelanggan umum.</div>
            </div>
              <?php if (!empty($posConfig['canManage'])): ?>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" id="onAccountToggle">
                <label class="form-check-label small fw-semibold" for="onAccountToggle">Izinkan penjualan tempo / catat piutang</label>
              </div>
              <div id="creditSaleFields" class="mb-2 d-none">
                <label class="form-label small mb-1" for="creditDueAt">Jatuh tempo</label>
                <input class="form-control form-control-sm" type="date" id="creditDueAt" min="<?= $e(date('Y-m-d')) ?>">
                <div class="form-text">Wajib memilih pelanggan. Hanya pemilik/manajer yang dapat menyetujui penjualan tempo.</div>
              </div>
              <?php endif; ?>
              <div id="rxGroup" class="mb-2 d-none">
              <label class="form-label small mb-1" for="rxInput">ID Resep (wajib untuk obat resep)</label>
              <input id="rxInput" type="number" min="1" class="form-control form-control-sm" inputmode="numeric">
            </div>
            <div class="row g-2 mb-2">
              <div class="col-12" id="discGroup">
                <input id="discInput" type="number" min="0" step="1" class="form-control form-control-sm"
                       placeholder="Diskon transaksi (Rp)" inputmode="decimal" aria-label="Diskon transaksi">
              </div>
              <div class="col-12">
                <input id="notesInput" type="text" maxlength="500" class="form-control form-control-sm"
                       placeholder="Catatan transaksi (opsional)" aria-label="Catatan transaksi">
              </div>
            </div>

            <dl class="row small mb-2 gx-2">
              <dt class="col-6 fw-normal text-muted">Subtotal</dt><dd class="col-6 text-end mb-0" id="tSub">Rp0</dd>
              <dt class="col-6 fw-normal text-muted">Diskon</dt><dd class="col-6 text-end mb-0" id="tDisc">Rp0</dd>
              <dt class="col-6 fw-normal text-muted" id="tSvcL">Service</dt><dd class="col-6 text-end mb-0" id="tSvc">Rp0</dd>
              <dt class="col-6 fw-normal text-muted" id="tTaxL">Pajak</dt><dd class="col-6 text-end mb-0" id="tTax">Rp0</dd>
              <dt class="col-6 h6 mb-0">Total</dt><dd class="col-6 text-end h5 mb-0 text-primary" id="tGrand">Rp0</dd>
            </dl>
            <div class="form-text mb-2">Estimasi tampilan. Total final dihitung server.</div>

            <div class="fw-semibold small mb-1">Pembayaran</div>
            <div id="payRows" class="d-grid gap-2 mb-2"></div>
            <div class="d-flex gap-2 mb-2">
              <button type="button" id="btnAddPay" class="btn btn-outline-secondary btn-sm">+ Metode (split)</button>
              <button type="button" id="btnExact" class="btn btn-outline-secondary btn-sm">Uang pas</button>
            </div>
            <div id="payStatus" class="small mb-2"></div>

            <div class="d-flex gap-2">
              <button type="button" id="btnClear" class="btn btn-outline-danger">Kosongkan</button>
              <button type="button" id="btnCheckout" class="btn btn-success btn-lg flex-grow-1" disabled>
                <span id="btnCheckoutSpin" class="spinner-border spinner-border-sm d-none" aria-hidden="true"></span>
                <span id="btnCheckoutText">Checkout</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </aside>
  </div>
</main>

<footer class="pos-footer">
  <div class="container-fluid d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2">
    <span class="small"><?= $e($posConfig['tenantName'] ?? 'Kasir') ?> · <?= $e($posConfig['branchName'] ?? '') ?></span>
    <span class="small">Kasir POS · Pastikan pesanan dan pembayaran sesuai sebelum checkout.</span>
  </div>
</footer>
</div>
</div>

<!-- Tombol keranjang (mobile) -->
<button type="button" class="btn btn-primary btn-lg shadow fab position-fixed d-lg-none"
        data-bs-toggle="offcanvas" data-bs-target="#cartPanel" aria-controls="cartPanel">
  Keranjang <span id="fabCount" class="badge text-bg-light">0</span> &middot; <span id="fabTotal">Rp0</span>
</button>

<!-- Modal input item dinamis -->
<div class="modal fade" id="itemModal" tabindex="-1" aria-labelledby="itemModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="itemModalTitle"></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body">
        <div id="itemModalError" class="alert alert-danger py-2 small d-none" role="alert"></div>
        <div id="itemModalBody"></div>
        <div id="itemModalEst" class="fw-semibold"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
        <button type="button" class="btn btn-primary" id="itemModalOk">Tambahkan</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal struk -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="receiptTitle">Transaksi berhasil</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
      </div>
      <div class="modal-body" id="receiptBody"></div>
      <div class="modal-footer">
        <label class="small text-muted d-flex align-items-center gap-2 mb-0" for="receiptPaperSize">
          Kertas nota
          <select id="receiptPaperSize" class="form-select form-select-sm" aria-label="Ukuran kertas nota" style="width:auto">
            <option value="58">Thermal 58 mm</option>
            <option value="80">Thermal 80 mm</option>
            <option value="a4">A4</option>
          </select>
        </label>
        <label class="small text-muted d-flex align-items-center gap-2 mb-0" for="receiptSerialBaud">
          Serial
          <select id="receiptSerialBaud" class="form-select form-select-sm" aria-label="Kecepatan serial printer" style="width:auto">
            <option value="9600">9600</option><option value="19200">19200</option>
            <option value="38400">38400</option><option value="57600">57600</option>
            <option value="115200" selected>115200</option>
          </select>
        </label>
        <button type="button" class="btn btn-outline-primary" id="receiptSerialConnect">Hubungkan serial</button>
        <button type="button" class="btn btn-primary" id="receiptSerialPrint" disabled>Cetak ESC/POS</button>
        <span id="receiptSerialStatus" class="small text-muted" role="status">Perlu browser Chromium dan izin port serial.</span>
        <button type="button" class="btn btn-outline-primary" id="receiptUsbConnect">Hubungkan USB</button>
        <button type="button" class="btn btn-primary" id="receiptUsbPrint" disabled>Cetak ESC/POS USB</button>
        <span id="receiptUsbStatus" class="small text-muted" role="status">USB printer-class, browser Chromium, dan izin perangkat.</span>
        <button type="button" class="btn btn-outline-secondary" id="printReceiptButton">Cetak</button>
        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Transaksi baru</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="holdsModal" tabindex="-1" aria-labelledby="holdsTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h2 class="modal-title h5" id="holdsTitle">Transaksi ditahan</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
      <div class="modal-body"><div id="holdsList"></div></div>
    </div>
  </div>
</div>

<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><h2 class="modal-title h5" id="historyTitle">Riwayat transaksi</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
      <div class="modal-body">
        <div class="row g-2 mb-3">
          <div class="col-sm-3"><label class="form-label small" for="historyFrom">Dari tanggal</label><input class="form-control" type="date" id="historyFrom"></div>
          <div class="col-sm-3"><label class="form-label small" for="historyTo">Sampai tanggal</label><input class="form-control" type="date" id="historyTo"></div>
          <div class="col-sm-4"><label class="form-label small" for="historySearch">Invoice</label><input class="form-control" id="historySearch" maxlength="80" placeholder="Cari nomor invoice"></div>
          <div class="col-sm-2 d-flex align-items-end"><button type="button" id="historyLoad" class="btn btn-primary w-100">Cari</button></div>
        </div>
        <div id="historyMessage" class="small text-muted mb-2" role="status"></div>
        <div id="historyList"></div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><div><h2 class="modal-title h5 mb-1" id="reportTitle">Laporan operasional kasir</h2><div class="small text-muted">Ringkasan penjualan, pembayaran, produk, staf, retur, dan stok cabang ini.</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button></div>
      <div class="modal-body">
        <div class="row g-2 align-items-end mb-3">
          <div class="col-sm-4 col-lg-3"><label class="form-label small" for="reportFrom">Dari</label><input class="form-control" type="date" id="reportFrom"></div>
          <div class="col-sm-4 col-lg-3"><label class="form-label small" for="reportTo">Sampai</label><input class="form-control" type="date" id="reportTo"></div>
          <div class="col-sm-4 col-lg-2"><button type="button" id="reportLoad" class="btn btn-primary w-100">Tampilkan</button></div>
          <div class="col-lg-4 text-lg-end"><a class="btn btn-outline-success w-100 disabled" id="reportExport" href="#" aria-disabled="true" download>Unduh CSV transaksi</a></div>
        </div>
        <div id="reportMessage" class="small text-muted mb-2" role="status"></div>
        <div id="reportContent"></div>
      </div>
    </div>
  </div>
</div>

<script>window.POS_CONFIG = <?= $cfgJson ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  'use strict';

  // ===== Konfigurasi & konstanta =====
  const CFG = window.POS_CONFIG || {};
  const BASE = String(CFG.baseUrl || '').replace(/\/$/, '');
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  const MODULES = new Set(CFG.modules || []);
  const ORDER_TYPES = [
    { v: 'retail',   m: 'retail',   l: 'Retail' },
    { v: 'dine_in',  m: 'fnb',      l: 'Makan di tempat' },
    { v: 'takeaway', m: 'fnb',      l: 'Bawa pulang' },
    { v: 'pharmacy', m: 'pharmacy', l: 'Apotek' },
    { v: 'laundry',  m: 'laundry',  l: 'Laundry' },
    { v: 'printing', m: 'printing', l: 'Percetakan' },
    { v: 'service',  m: 'service',  l: 'Jasa' }
  ].filter(function (o) { return MODULES.has(o.m); });
  const PRODUCT_MODULE = { goods: 'retail', menu: 'fnb', medicine: 'pharmacy', laundry: 'laundry', print: 'printing', service: 'service' };
  const PRODUCT_MODULE_LABELS = { retail: 'Retail', fnb: 'Makanan', pharmacy: 'Apotek', laundry: 'Laundry', printing: 'Percetakan', service: 'Jasa' };
  const PAY_METHODS = [['cash', 'Tunai'], ['qris', 'QRIS'], ['transfer', 'Transfer'], ['debit', 'Debit'], ['credit', 'Kredit'], ['other', 'Lainnya']];
  const FIELD_LABELS = { payments: 'Pembayaran', table_id: 'Meja', discount_amount: 'Diskon', order_type: 'Tipe pesanan',
    'meta.prescription_id': 'Resep', stock: 'Stok tidak cukup', upload_id: 'File desain', idempotency_key: 'Kunci transaksi', items: 'Keranjang' };
  const fmtIDR = new Intl.NumberFormat('id-ID', { style: 'currency', currency: CFG.currency || 'IDR', maximumFractionDigits: 0 });
  const money = function (cents) { return fmtIDR.format(cents / 100); };

  const state = {
    cart: [], products: [], productFilter: ORDER_TYPES.length ? ORDER_TYPES[0].m : 'all',
    orderType: ORDER_TYPES.length ? ORDER_TYPES[0].v : '', tableId: '',
    customerId: null, customerName: '',
    payments: [], discount: '', notes: '', rx: '', onAccount: false, dueAt: '', idemKey: null, busy: false, lineErrors: {}
  };
  let seq = 0;
  const uid = function () { return 'k' + (++seq); };

  // ===== Util =====
  const $ = function (id) { return document.getElementById(id); };

  // Pembuat elemen aman: semua teks lewat textContent / text node (tanpa innerHTML).
  function h(tag, props) {
    const el = document.createElement(tag);
    let pendingValue = null;
    if (props) {
      Object.keys(props).forEach(function (k) {
        const v = props[k];
        if (v === null || v === undefined || v === false) return;
        if (k === 'class') el.className = v;
        else if (k === 'text') el.textContent = v;
        else if (k === 'value') pendingValue = String(v);
        else if (k === 'checked' || k === 'selected' || k === 'disabled') el[k] = !!v;
        else if (k.indexOf('on') === 0 && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
        else el.setAttribute(k, v === true ? '' : String(v));
      });
    }
    for (let i = 2; i < arguments.length; i++) {
      const kids = Array.isArray(arguments[i]) ? arguments[i] : [arguments[i]];
      kids.forEach(function (kid) {
        if (kid === null || kid === undefined || kid === false) return;
        el.append(kid.nodeType ? kid : document.createTextNode(String(kid)));
      });
    }
    if (pendingValue !== null) el.value = pendingValue; // setelah <option> terpasang
    return el;
  }
  function clear(node) { while (node.firstChild) node.removeChild(node.firstChild); }
  function toCents(v) {
    const n = parseFloat(v);
    return isFinite(n) && n > 0 ? Math.round(n * 100) : 0;
  }
  function uuid() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    const b = new Uint8Array(16);
    crypto.getRandomValues(b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const x = Array.prototype.map.call(b, function (n) { return ('0' + n.toString(16)).slice(-2); }).join('');
    return x.slice(0, 8) + '-' + x.slice(8, 12) + '-' + x.slice(12, 16) + '-' + x.slice(16, 20) + '-' + x.slice(20);
  }
  // Setiap perubahan isi transaksi membuat kunci idempotensi baru; retry tanpa perubahan memakai kunci yang sama.
  function touch() { state.idemKey = null; }

  async function api(path, opts) {
    const o = opts || {};
    const headers = { 'Accept': 'application/json', 'X-CSRF-Token': CSRF };
    if (o.body) headers['Content-Type'] = 'application/json';
    const res = await fetch(BASE + path, { method: o.method || 'GET', body: o.body, signal: o.signal, credentials: 'same-origin', headers: headers });
    let json = null;
    try { json = await res.json(); } catch (_) { /* respons bukan JSON */ }
    if (!json) throw { status: res.status, message: 'Respons server tidak valid (HTTP ' + res.status + ').' };
    if (!res.ok || !json.success) throw { status: res.status, message: json.message || 'Terjadi kesalahan.', errors: json.errors || null };
    return json;
  }

  let customerSearchTimer = null;
  let customerSearchVersion = 0;
  function searchCustomers(query) {
    const results = $('customerResults');
    clear(results);
    if (query.trim().length < 2) {
      results.classList.add('d-none');
      return;
    }
    const version = ++customerSearchVersion;
    window.clearTimeout(customerSearchTimer);
    customerSearchTimer = window.setTimeout(async function () {
      try {
        const json = await api('/api/customers?q=' + encodeURIComponent(query.trim()));
        if (version !== customerSearchVersion) return;
        const customers = (json.data && json.data.items) || [];
        clear(results);
        if (!customers.length) {
          results.append(h('div', { class: 'list-group-item small text-muted', text: 'Pelanggan tidak ditemukan. Tambahkan melalui menu Kelola toko.' }));
        } else {
          customers.forEach(function (customer) {
            results.append(h('button', { type: 'button', class: 'list-group-item list-group-item-action small',
              onclick: function () {
                state.customerId = Number(customer.id);
                state.customerName = String(customer.name || '');
                $('customerSearch').value = state.customerName;
                $('customerSelected').textContent = 'Pelanggan terpilih: ' + state.customerName + (customer.phone ? ' · ' + customer.phone : '');
                results.classList.add('d-none');
                touch();
              } },
              h('span', { class: 'fw-semibold', text: customer.name }),
              h('span', { class: 'text-muted ms-2', text: customer.phone || customer.email || '' })));
          });
        }
        results.classList.remove('d-none');
      } catch (err) {
        if (version !== customerSearchVersion) return;
        clear(results);
        results.append(h('div', { class: 'list-group-item small text-danger', text: err.message || 'Pencarian pelanggan gagal.' }));
        results.classList.remove('d-none');
      }
    }, 180);
  }

  // ===== Harga estimasi (tampilan saja) =====
  function estimate(l) {
    const p = l.p, a = p.attributes || {}, at = l.attrs || {};
    let unit = p.price;
    if (p.product_type === 'laundry' && at.express && a.express_surcharge_pct != null) {
      unit = Math.round(unit * (100 + Number(a.express_surcharge_pct)) / 100);
    }
    if (p.pricing_model === 'per_kg') {
      return { unit: unit, qty: at.weight_kg, gross: Math.round(unit * at.weight_kg) };
    }
    if (p.pricing_model === 'per_m2') {
      const area = (at.width_cm * at.height_cm) / 10000;
      const bill = Math.max(area, Number(a.min_area_m2 || 0));
      const per = Math.round(bill * (p.price_per_m2 != null ? p.price_per_m2 : p.price));
      return { unit: per, qty: l.qty, gross: per * l.qty };
    }
    if (p.pricing_model === 'per_hour') {
      const r = Math.max(1, Number(a.rounding_minutes || 1));
      let b = Math.max(at.duration_minutes, Number(a.min_duration_minutes || 0));
      b = Math.ceil(b / r) * r;
      return { unit: unit, qty: b / 60, gross: Math.round(unit * b / 60) };
    }
    return { unit: unit, qty: l.qty, gross: unit * l.qty };
  }
  function lineNet(l) { return Math.max(0, estimate(l).gross - toCents(l.discount)); }
  function totals() {
    let gross = 0, itemDisc = 0;
    state.cart.forEach(function (l) { gross += estimate(l).gross; itemDisc += toCents(l.discount); });
    const disc = itemDisc + toCents(state.discount);
    const base = Math.max(0, gross - disc);
    const svc = state.orderType === 'dine_in' ? Math.round(base * Number(CFG.servicePct || 0) / 100) : 0;
    const taxBase = base + svc;
    const taxPct = Number(CFG.taxPct || 0);
    const inclusiveTax = CFG.taxMode === 'inclusive';
    const tax = inclusiveTax
      ? Math.round(taxBase * taxPct / (100 + taxPct))
      : Math.round(taxBase * taxPct / 100);
    return { gross: gross, disc: disc, base: base, svc: svc, tax: tax, grand: inclusiveTax ? taxBase : taxBase + tax };
  }
  function paidSums() {
    let paid = 0, nonCash = 0;
    state.payments.forEach(function (p) {
      const c = toCents(p.amount);
      paid += c;
      if (p.method !== 'cash') nonCash += c;
    });
    return { paid: paid, nonCash: nonCash };
  }
  function needsRx() {
    return state.cart.some(function (l) { return (l.p.attributes || {}).requires_prescription === true; });
  }

  // ===== Produk =====
  let searchTimer = null, searchCtl = null;

  function setGridState(msg) {
    const g = $('gridState');
    if (!msg) { g.classList.add('d-none'); return; }
    g.textContent = msg;
    g.classList.remove('d-none');
  }

  function normalizeProduct(p) {
    p.price = toCents(p.price);
    p.price_per_m2 = p.price_per_m2 != null ? toCents(p.price_per_m2) : null;
    p.attributes = (p.attributes && typeof p.attributes === 'object') ? p.attributes : {};
    p.batches = Array.isArray(p.batches) ? p.batches : [];
    return p;
  }

  function renderProductFilters() {
    const box = $('productFilters');
    clear(box);
    const modules = Array.from(new Set(ORDER_TYPES.map(function (type) { return type.m; })));
    const filters = [{ id: 'all', label: 'Semua' }].concat(modules.map(function (module) {
      return { id: module, label: PRODUCT_MODULE_LABELS[module] || module };
    }));
    filters.forEach(function (filter) {
      box.append(h('button', {
        type: 'button',
        class: 'product-filter' + (state.productFilter === filter.id ? ' active' : ''),
        'aria-pressed': state.productFilter === filter.id,
        text: filter.label,
        onclick: function () {
          state.productFilter = filter.id;
          renderProductFilters();
          renderGrid();
        }
      }));
    });
  }

  async function loadProducts(value, byBarcode) {
    if (searchCtl) searchCtl.abort();
    searchCtl = new AbortController();
    setGridState('Memuat produk...');
    try {
      const qs = new URLSearchParams();
      if (value) qs.set(byBarcode ? 'barcode' : 'q', value);
      const json = await api('/api/products?' + qs.toString(), { signal: searchCtl.signal });
      const items = (json.data && json.data.items) || [];
      state.products = items.filter(function (p) { return MODULES.has(PRODUCT_MODULE[p.product_type] || 'retail'); }).map(normalizeProduct);
      renderGrid();
      return state.products;
    } catch (err) {
      if (err && err.name === 'AbortError') return null;
      clear($('productGrid'));
      $('productCount').textContent = '';
      setGridState((err && err.message) || 'Gagal memuat produk.');
      return null;
    }
  }

  function priceSuffix(p) {
    return { per_kg: '/kg', per_m2: '/m\u00B2', per_hour: '/jam' }[p.pricing_model] || (p.unit ? '/' + p.unit : '');
  }

  function renderGrid() {
    const grid = $('productGrid');
    clear(grid);
    const visibleProducts = state.productFilter === 'all'
      ? state.products
      : state.products.filter(function (p) { return PRODUCT_MODULE[p.product_type] === state.productFilter; });
    $('productCount').textContent = visibleProducts.length === state.products.length
      ? state.products.length + ' produk'
      : visibleProducts.length + ' dari ' + state.products.length + ' produk';
    if (!visibleProducts.length) {
      setGridState(state.products.length ? 'Tidak ada produk pada bidang usaha ini.' : 'Produk tidak ditemukan.');
      return;
    }
    setGridState('');
    visibleProducts.forEach(function (p) {
      const out = p.stock !== null && p.stock !== undefined && parseFloat(p.stock) <= 0;
      const title = p.name + (p.variant_name && p.variant_name !== 'Default' ? ' \u00B7 ' + p.variant_name : '');
      grid.append(h('div', { class: 'col' },
        h('button', { type: 'button', class: 'btn w-100 h-100 text-start p-3 product-card', disabled: out, onclick: function () { pickProduct(p); } },
          h('div', { class: 'd-flex align-items-start gap-2' },
            h('span', { class: 'product-mark', 'aria-hidden': 'true', text: String(p.name || '?').trim().slice(0, 1).toUpperCase() }),
            h('span', { class: 'product-card-name fw-semibold small lh-sm', text: title })),
          h('div', { class: 'd-flex justify-content-between align-items-end gap-2 mt-3' },
            h('span', { class: 'text-primary fw-bold small', text: money(p.price) + priceSuffix(p) }),
            out ? h('span', { class: 'badge text-bg-danger', text: 'Habis' })
                : (p.stock != null ? h('span', { class: 'badge text-bg-light border', text: 'Stok ' + parseFloat(p.stock) }) : null)))));
    });
  }

  function needsModal(p) {
    const a = p.attributes || {};
    return ['per_kg', 'per_m2', 'per_hour'].indexOf(p.pricing_model) >= 0
      || p.product_type === 'laundry' || p.product_type === 'medicine'
      || (p.product_type === 'menu' && Array.isArray(a.modifier_groups) && a.modifier_groups.length > 0);
  }

  function pickProduct(p) {
    if (needsModal(p)) { openItemModal(p, null); return; }
    addLine(p, 1, {});
  }

  // ===== Keranjang =====
  function addLine(p, qty, attrs) {
    touch();
    const simple = !attrs || Object.keys(attrs).length === 0;
    if (simple) {
      const ex = state.cart.find(function (l) { return l.p.variant_id === p.variant_id && Object.keys(l.attrs).length === 0; });
      if (ex) { ex.qty += qty; renderAll(); return; }
    }
    state.cart.push({ key: uid(), p: p, qty: qty, attrs: attrs || {}, discount: '', group: '' });
    state.lineErrors = {};
    renderAll();
  }

  function describe(l) {
    const p = l.p, at = l.attrs, parts = [];
    if (p.pricing_model === 'per_kg') parts.push(at.weight_kg + ' kg');
    if (p.pricing_model === 'per_m2') {
      parts.push(at.width_cm + '\u00D7' + at.height_cm + ' cm', l.qty + ' lembar');
      if (at.finishing && at.finishing.length) parts.push(at.finishing.join(', '));
      if (at.upload_id) parts.push('desain #' + at.upload_id);
    }
    if (p.pricing_model === 'per_hour') {
      parts.push(at.duration_minutes + ' menit');
      if (at.staff) parts.push(at.staff);
    }
    if (p.product_type === 'laundry') {
      if (at.service) parts.push(at.service.replace(/_/g, ' '));
      if (at.express) parts.push('ekspres');
      if (at.pieces) parts.push(at.pieces + ' pcs');
    }
    if (at.modifiers && at.modifiers.length) parts.push(at.modifiers.join(', '));
    if (p.product_type === 'medicine') {
      parts.push(at.batch_id ? 'batch dipilih' : 'batch FEFO');
      if (at.dosage_instruction) parts.push(at.dosage_instruction);
    }
    return parts.join(' \u00B7 ');
  }

  const lineTotalEls = {};

  function renderCart() {
    const list = $('cartList');
    clear(list);
    Object.keys(lineTotalEls).forEach(function (k) { delete lineTotalEls[k]; });
    $('cartEmpty').classList.toggle('d-none', state.cart.length > 0);

    state.cart.forEach(function (l, idx) {
      const p = l.p;
      const simpleQty = ['unit', 'fixed'].indexOf(p.pricing_model) >= 0 && p.product_type !== 'laundry' && p.product_type !== 'medicine';
      const errs = state.lineErrors[String(idx)];
      const totalEl = h('div', { class: 'fw-semibold', text: money(lineNet(l)) });
      lineTotalEls[l.key] = totalEl;

      const qtyCtl = simpleQty
        ? h('div', { class: 'input-group input-group-sm', style: 'width:110px' },
            h('button', { type: 'button', class: 'btn btn-outline-secondary', 'aria-label': 'Kurangi', onclick: function () { changeQty(l, -1); } }, '\u2212'),
            h('input', { type: 'number', min: '1', step: '1', class: 'form-control text-center px-1', value: l.qty, 'aria-label': 'Jumlah',
              onchange: function (ev) { setQty(l, parseInt(ev.target.value, 10)); } }),
            h('button', { type: 'button', class: 'btn btn-outline-secondary', 'aria-label': 'Tambah', onclick: function () { changeQty(l, 1); } }, '+'))
        : h('button', { type: 'button', class: 'btn btn-outline-primary btn-sm', onclick: function () { openItemModal(p, l); } }, 'Ubah');

      const extras = [];
      if (CFG.canDiscount) {
        extras.push(h('input', { type: 'number', min: '0', step: '1', inputmode: 'decimal', class: 'form-control form-control-sm', style: 'width:110px',
          placeholder: 'Diskon Rp', value: l.discount, 'aria-label': 'Diskon item',
          oninput: function (ev) { l.discount = ev.target.value; touch(); totalEl.textContent = money(lineNet(l)); renderTotals(); } }));
      }
      if (state.orderType === 'dine_in') {
        extras.push(h('select', { class: 'form-select form-select-sm', style: 'width:120px', 'aria-label': 'Grup tagihan (split bill)',
          onchange: function (ev) { l.group = ev.target.value; touch(); } },
          h('option', { value: '' }, 'Tagihan: semua'),
          [1, 2, 3, 4, 5, 6].map(function (n) { return h('option', { value: String(n), selected: String(l.group) === String(n) }, 'Tagihan ' + n); })));
      }

      list.append(h('div', { class: 'border rounded-3 p-2 mb-2' + (errs ? ' line-error' : '') },
        h('div', { class: 'd-flex justify-content-between gap-2' },
          h('div', { class: 'min-w-0' },
            h('div', { class: 'fw-semibold small lh-sm', text: p.name + (p.variant_name && p.variant_name !== 'Default' ? ' \u00B7 ' + p.variant_name : '') }),
            h('div', { class: 'text-muted small', text: describe(l) })),
          h('button', { type: 'button', class: 'btn-close', 'aria-label': 'Hapus item', onclick: function () { removeLine(l); } })),
        h('div', { class: 'd-flex justify-content-between align-items-center mt-2 flex-wrap gap-2' }, qtyCtl, totalEl),
        extras.length ? h('div', { class: 'd-flex gap-2 mt-2 flex-wrap' }, extras) : null,
        errs ? h('div', { class: 'text-danger small mt-1', text: errs.join(' ') }) : null));
    });

    const count = state.cart.length;
    $('cartCount').textContent = count;
    $('fabCount').textContent = count;
    $('rxGroup').classList.toggle('d-none', !needsRx());
  }

  function changeQty(l, d) { setQty(l, l.qty + d); }
  function setQty(l, q) {
    if (!(q >= 1)) q = 1;
    l.qty = q;
    touch();
    renderAll();
  }
  function removeLine(l) {
    state.cart = state.cart.filter(function (x) { return x !== l; });
    state.lineErrors = {};
    touch();
    renderAll();
  }

  // ===== Pembayaran =====
  function addPayRow(method) {
    state.payments.push({ id: uid(), method: method || 'cash', amount: '', reference_no: '', el: null });
  }

  function renderPayments() {
    const box = $('payRows');
    clear(box);
    state.payments.forEach(function (row) {
      const amount = h('input', { type: 'number', min: '0', step: '1', inputmode: 'decimal', class: 'form-control', placeholder: 'Nominal (Rp)',
        value: row.amount, 'aria-label': 'Nominal pembayaran',
        oninput: function (ev) { row.amount = ev.target.value; touch(); renderTotals(); } });
      const reference = row.method === 'cash' ? null : h('input', { type: 'text', maxlength: '80', class: 'form-control form-control-sm mt-1',
        placeholder: 'No. referensi (opsional)', value: row.reference_no || '', 'aria-label': 'Nomor referensi pembayaran',
        oninput: function (ev) { row.reference_no = ev.target.value; touch(); } });
      row.el = amount;
      box.append(h('div', { class: 'd-flex flex-column' },
        h('div', { class: 'input-group input-group-sm' },
          h('select', { class: 'form-select', style: 'max-width:120px', 'aria-label': 'Metode pembayaran',
            onchange: function (ev) { row.method = ev.target.value; touch(); renderPayments(); renderTotals(); } },
            PAY_METHODS.map(function (m) { return h('option', { value: m[0], selected: row.method === m[0] }, m[1]); })),
          amount,
          state.payments.length > 1
            ? h('button', { type: 'button', class: 'btn btn-outline-danger', 'aria-label': 'Hapus metode',
                onclick: function () { state.payments = state.payments.filter(function (r) { return r !== row; }); touch(); renderPayments(); renderTotals(); } }, '\u00D7')
            : null),
        reference));
    });
  }

  // ===== Ringkasan & tombol =====
  function renderTotals() {
    const t = totals();
    $('tSub').textContent = money(t.gross);
    $('tDisc').textContent = '\u2212' + money(t.disc);
    $('tSvc').textContent = money(t.svc);
    $('tTaxL').textContent = CFG.taxMode === 'inclusive' ? 'Pajak (termasuk harga)' : 'Pajak (tambahan)';
    $('tTax').textContent = money(t.tax);
    $('tSvcL').classList.toggle('d-none', t.svc === 0);
    $('tSvc').classList.toggle('d-none', t.svc === 0);
    $('tTaxL').classList.toggle('d-none', t.tax === 0);
    $('tTax').classList.toggle('d-none', t.tax === 0);
    $('tGrand').textContent = money(t.grand);
    $('fabTotal').textContent = money(t.grand);

    const s = paidSums();
    const st = $('payStatus');
    st.className = 'small mb-2';
    let ok = state.cart.length > 0 && t.grand > 0 && (state.onAccount ? (s.paid <= t.grand && !!state.customerId && !!state.dueAt) : s.paid >= t.grand) && !state.busy && ORDER_TYPES.length > 0;
    if (!state.cart.length) {
      st.textContent = '';
    } else if (state.onAccount && s.paid < t.grand) {
      st.textContent = 'Piutang ' + money(t.grand - s.paid) + (state.customerId && state.dueAt ? ' · jatuh tempo ' + state.dueAt : ' · pilih pelanggan dan jatuh tempo');
      st.classList.add(state.customerId && state.dueAt ? 'text-warning' : 'text-danger');
    } else if (state.onAccount && s.paid > t.grand) {
      st.textContent = 'Pembayaran penjualan tempo tidak boleh melebihi total tagihan.';
      st.classList.add('text-danger');
    } else if (s.paid < t.grand) {
      st.textContent = 'Kurang ' + money(t.grand - s.paid);
      st.classList.add('text-danger');
    } else if (s.nonCash > t.grand) {
      st.textContent = 'Pembayaran non-tunai melebihi total tagihan.';
      st.classList.add('text-danger');
      ok = false;
    } else {
      st.textContent = 'Kembalian ' + money(s.paid - t.grand);
      st.classList.add('text-success');
    }
    if (state.orderType === 'dine_in' && !state.tableId) ok = false;
    if (needsRx() && !(parseInt(state.rx, 10) > 0)) ok = false;
    if (!CFG.shiftOpen) ok = false;
    $('btnCheckout').disabled = !ok;
  }

  function renderAll() {
    renderCart();
    renderTotals();
  }

  // ===== Modal item dinamis =====
  let itemModal = null;

  function buildForm(p, init) {
    const a = p.attributes || {};
    const at = (init && init.attrs) || {};
    const refs = {};
    const nodes = [];
    const field = function (label, control, hint) {
      nodes.push(h('div', { class: 'mb-3' }, h('label', { class: 'form-label fw-semibold small mb-1' }, label), control, hint ? h('div', { class: 'form-text', text: hint }) : null));
    };
    const input = function (key, props) {
      refs[key] = h('input', Object.assign({ class: 'form-control' }, props));
      return refs[key];
    };
    const model = p.pricing_model;

    if (model === 'per_kg') {
      field('Berat (kg)', input('weight', { type: 'number', min: '0.001', step: '0.001', inputmode: 'decimal', value: at.weight_kg != null ? at.weight_kg : '' }));
      field('Jumlah potong (opsional)', input('pieces', { type: 'number', min: '1', step: '1', inputmode: 'numeric', value: at.pieces != null ? at.pieces : '' }));
    } else if (model === 'per_m2') {
      field('Lebar (cm)', input('w', { type: 'number', min: '1', step: '0.01', inputmode: 'decimal', value: at.width_cm != null ? at.width_cm : '' }),
        a.max_width_cm ? 'Lebar bahan maks ' + a.max_width_cm + ' cm' : null);
      field('Tinggi (cm)', input('hgt', { type: 'number', min: '1', step: '0.01', inputmode: 'decimal', value: at.height_cm != null ? at.height_cm : '' }));
      field('Jumlah lembar', input('qty', { type: 'number', min: '1', step: '1', inputmode: 'numeric', value: init ? init.qty : 1 }));
      if (Array.isArray(a.finishing_options) && a.finishing_options.length) {
        refs.fin = [];
        nodes.push(h('div', { class: 'mb-3' }, h('div', { class: 'form-label fw-semibold small mb-1' }, 'Finishing'),
          a.finishing_options.map(function (f, i) {
            const cb = h('input', { type: 'checkbox', class: 'form-check-input', id: 'fin' + i, value: f, checked: (at.finishing || []).indexOf(f) >= 0 });
            refs.fin.push(cb);
            return h('div', { class: 'form-check' }, cb, h('label', { class: 'form-check-label', for: 'fin' + i }, f.replace(/_/g, ' ')));
          })));
      }
      field('ID file desain (opsional)', input('upload', { type: 'number', min: '1', step: '1', inputmode: 'numeric', value: at.upload_id != null ? at.upload_id : '' }),
        'Isi bila file desain sudah diunggah dan belum terpakai.');
    } else if (model === 'per_hour') {
      field('Durasi (menit)', input('minutes', { type: 'number', min: '1', max: '1440', step: '1', inputmode: 'numeric', value: at.duration_minutes != null ? at.duration_minutes : '' }),
        a.min_duration_minutes ? 'Minimal ' + a.min_duration_minutes + ' menit' + (a.rounding_minutes ? ', pembulatan ' + a.rounding_minutes + ' menit' : '') : null);
      if (a.requires_staff === true || at.staff) {
        field('Petugas' + (a.requires_staff === true ? '' : ' (opsional)'), input('staff', { type: 'text', maxlength: '100', value: at.staff || '' }));
      }
    } else {
      field('Jumlah', input('qty', { type: 'number', min: '1', step: '1', inputmode: 'numeric', value: init ? init.qty : 1 }));
    }

    if (p.product_type === 'laundry') {
      if (Array.isArray(a.services) && a.services.length) {
        refs.service = h('select', { class: 'form-select', value: at.service || '' },
          h('option', { value: '' }, 'Pilih layanan'),
          a.services.map(function (s) { return h('option', { value: s }, s.replace(/_/g, ' ')); }));
        field('Layanan', refs.service);
      }
      if (a.express_surcharge_pct != null) {
        refs.express = h('input', { type: 'checkbox', class: 'form-check-input', id: 'expr', checked: !!at.express });
        nodes.push(h('div', { class: 'form-check mb-3' }, refs.express,
          h('label', { class: 'form-check-label', for: 'expr' }, 'Ekspres (+' + a.express_surcharge_pct + '%)')));
      }
    }

    if (p.product_type === 'menu') {
      field('Modifier / catatan dapur', input('mods', { type: 'text', maxlength: '300', value: (at.modifiers || []).join(', ') }),
        'Pisahkan dengan koma, maks 10. Contoh: pedas level 2, tanpa telur');
    }

    if (p.product_type === 'medicine') {
      refs.batch = h('select', { class: 'form-select', value: at.batch_id != null ? at.batch_id : '' },
        h('option', { value: '' }, 'Otomatis (FEFO: kedaluwarsa terdekat)'),
        p.batches.map(function (b) {
          return h('option', { value: String(b.id) }, b.batch_number + ' \u00B7 exp ' + b.expired_at + ' \u00B7 sisa ' + parseFloat(b.qty_remaining));
        }));
      field('Batch', refs.batch);
      field('Aturan pakai (opsional)', input('dose', { type: 'text', maxlength: '150', placeholder: '3x1 sesudah makan', value: at.dosage_instruction || '' }));
    }

    function collect() {
      const attrs = {};
      let qty = 1;
      const num = function (r) { return parseFloat(r.value); };
      if (model === 'per_kg') {
        const w = num(refs.weight);
        if (!(w > 0)) throw new Error('Berat (kg) wajib diisi.');
        attrs.weight_kg = w;
        const pcs = parseInt(refs.pieces.value, 10);
        if (pcs > 0) attrs.pieces = pcs;
      } else if (model === 'per_m2') {
        const w = num(refs.w), hh = num(refs.hgt);
        if (!(w > 0) || !(hh > 0)) throw new Error('Lebar dan tinggi wajib diisi.');
        attrs.width_cm = w;
        attrs.height_cm = hh;
        qty = parseInt(refs.qty.value, 10);
        if (!(qty >= 1)) throw new Error('Jumlah lembar minimal 1.');
        if (refs.fin) {
          const fin = refs.fin.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
          if (fin.length) attrs.finishing = fin;
        }
        const up = parseInt(refs.upload.value, 10);
        if (up > 0) attrs.upload_id = up;
      } else if (model === 'per_hour') {
        const m = parseInt(refs.minutes.value, 10);
        if (!(m >= 1 && m <= 1440)) throw new Error('Durasi 1-1440 menit.');
        attrs.duration_minutes = m;
        if (refs.staff) {
          const s = refs.staff.value.trim();
          if (a.requires_staff === true && !s) throw new Error('Petugas wajib diisi.');
          if (s) attrs.staff = s;
        }
      } else {
        qty = parseInt(refs.qty.value, 10);
        if (!(qty >= 1)) throw new Error('Jumlah minimal 1.');
      }
      if (p.product_type === 'laundry') {
        if (refs.service) {
          if (!refs.service.value) throw new Error('Pilih layanan laundry.');
          attrs.service = refs.service.value;
        }
        if (refs.express && refs.express.checked) attrs.express = true;
      }
      if (p.product_type === 'menu') {
        const mods = refs.mods.value.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
        if (mods.length > 10) throw new Error('Maksimal 10 modifier.');
        if (mods.length) attrs.modifiers = mods;
      }
      if (p.product_type === 'medicine') {
        const b = parseInt(refs.batch.value, 10);
        if (b > 0) attrs.batch_id = b;
        const d = refs.dose.value.trim();
        if (d) attrs.dosage_instruction = d;
      }
      return { qty: qty, attrs: attrs };
    }

    return { nodes: nodes, collect: collect };
  }

  function openItemModal(p, existing) {
    if (!itemModal) itemModal = new bootstrap.Modal($('itemModal'));
    const form = buildForm(p, existing);
    const body = $('itemModalBody'), err = $('itemModalError'), est = $('itemModalEst');
    clear(body);
    form.nodes.forEach(function (n) { body.append(n); });
    err.classList.add('d-none');
    est.textContent = '';
    $('itemModalTitle').textContent = p.name + (p.variant_name && p.variant_name !== 'Default' ? ' \u00B7 ' + p.variant_name : '');
    $('itemModalOk').textContent = existing ? 'Simpan' : 'Tambahkan';

    const updateEst = function () {
      try {
        const r = form.collect();
        est.textContent = 'Estimasi: ' + money(estimate({ p: p, qty: r.qty, attrs: r.attrs, discount: '' }).gross);
      } catch (_) { est.textContent = ''; }
    };
    body.oninput = updateEst;
    body.onchange = updateEst;
    updateEst();

    $('itemModalOk').onclick = function () {
      try {
        const r = form.collect();
        err.classList.add('d-none');
        if (existing) {
          existing.qty = r.qty;
          existing.attrs = r.attrs;
          touch();
          state.lineErrors = {};
          renderAll();
        } else {
          addLine(p, r.qty, r.attrs);
        }
        itemModal.hide();
      } catch (e) {
        err.textContent = e.message;
        err.classList.remove('d-none');
      }
    };
    itemModal.show();
  }

  // ===== Checkout =====
  function showAlert(msg, list) {
    const box = $('alertBox');
    clear(box);
    if (!msg) { box.classList.add('d-none'); return; }
    box.append(h('div', { class: 'fw-semibold', text: msg }));
    if (list && list.length) box.append(h('ul', { class: 'mb-0 ps-3' }, list.map(function (t) { return h('li', { text: t }); })));
    box.classList.remove('d-none');
  }

  function handleError(err) {
    state.lineErrors = {};
    const general = [];
    Object.keys((err && err.errors) || {}).forEach(function (k) {
      const m = err.errors[k];
      const mt = /^items\.(\d+)/.exec(k);
      if (mt) { (state.lineErrors[mt[1]] = state.lineErrors[mt[1]] || []).push(m); }
      else if (k === 'stock') { general.push('Stok tidak cukup: ' + m); }
      else { general.push((FIELD_LABELS[k] || k) + ': ' + m); }
    });
    let msg = (err && err.message) || 'Gagal memproses transaksi.';
    if (err && err.status === 401) msg = 'Sesi berakhir. Muat ulang halaman dan login kembali.';
    showAlert(msg, general);
    renderCart();
    const panel = document.querySelector('#cartPanel .cart-body');
    if (panel) panel.scrollTop = 0;
  }

  function buildPayload() {
    const items = state.cart.map(function (l) {
      const it = { variant_id: l.p.variant_id, qty: l.qty, attributes: l.attrs };
      const d = toCents(l.discount);
      if (d > 0) it.discount_amount = (d / 100).toFixed(2);
      if (state.orderType === 'dine_in' && l.group) it.split_group = parseInt(l.group, 10);
      return it;
    });
    const payments = state.payments.filter(function (p) { return toCents(p.amount) > 0; })
      .map(function (p) {
        const payment = { method: p.method, amount: (toCents(p.amount) / 100).toFixed(2) };
        if (p.reference_no && p.reference_no.trim()) payment.reference_no = p.reference_no.trim();
        return payment;
      });
    const hd = toCents(state.discount);
    return {
      idempotency_key: state.idemKey,
      order_type: state.orderType,
      customer_id: state.customerId || undefined,
      on_account: state.onAccount,
      due_at: state.onAccount && state.dueAt ? state.dueAt + ' 23:59' : undefined,
      table_id: state.orderType === 'dine_in' ? parseInt(state.tableId, 10) : undefined,
      discount_amount: hd > 0 ? (hd / 100).toFixed(2) : undefined,
      notes: state.notes.trim() || undefined,
      meta: needsRx() ? { prescription_id: parseInt(state.rx, 10) } : undefined,
      items: items,
      payments: payments
    };
  }

  function setBusy(b) {
    state.busy = b;
    $('btnCheckoutSpin').classList.toggle('d-none', !b);
    $('btnCheckoutText').textContent = b ? 'Memproses...' : 'Checkout';
    $('btnClear').disabled = b;
    renderTotals();
  }

  async function checkout() {
    if (state.busy || $('btnCheckout').disabled) return;
    showAlert('');
    state.lineErrors = {};
    if (!state.idemKey) state.idemKey = uuid();
    setBusy(true);
    try {
      const json = await api('/api/checkout', { method: 'POST', body: JSON.stringify(buildPayload()) });
      showReceipt(json.data, json.message);
      resetAll();
    } catch (err) {
      handleError(err);
    } finally {
      setBusy(false);
    }
  }

  let currentReceiptData = null;
  let receiptSerialPort = null;
  function showReceipt(data, message) {
    currentReceiptData = data;
    const t = (data && data.transaction) || {};
    const body = $('receiptBody');
    clear(body);
    body.append(
      h('div', { class: 'text-center mb-3' },
        h('div', { class: 'fw-bold', text: CFG.tenantName || 'Usaha' }),
        CFG.branchName ? h('div', { class: 'small', text: CFG.branchName }) : null,
        CFG.branchAddress ? h('div', { class: 'small text-muted', text: CFG.branchAddress }) : null,
        CFG.branchPhone || CFG.businessPhone ? h('div', { class: 'small text-muted', text: 'Tel. ' + (CFG.branchPhone || CFG.businessPhone) }) : null,
        h('hr', { class: 'my-2' }),
        t.customer_name ? h('div', { class: 'small', text: 'Pelanggan: ' + t.customer_name + (t.customer_phone ? ' · ' + t.customer_phone : '') }) : null,
        h('div', { class: 'h5 mb-0', text: t.invoice_no || '-' }),
        h('div', { class: 'text-muted small', text: message || '' }),
        data && data.duplicate ? h('div', { class: 'badge text-bg-warning mt-1', text: 'Transaksi sudah tercatat sebelumnya' }) : null,
        t.fulfillment_status ? h('div', { class: 'badge text-bg-info mt-1', text: 'Status: ' + t.fulfillment_status }) : null),
      h('table', { class: 'table table-sm small' }, h('tbody', null,
        ((data && data.items) || []).map(function (it) {
          return h('tr', null,
            h('td', null, h('div', { text: it.item_name }), h('div', { class: 'text-muted', text: parseFloat(it.qty) + ' ' + it.unit + ' \u00D7 ' + fmtIDR.format(parseFloat(it.unit_price)) })),
            h('td', { class: 'text-end', text: fmtIDR.format(parseFloat(it.line_total)) }));
        }))),
      h('dl', { class: 'row small mb-0' },
        [['Subtotal', t.subtotal], ['Diskon', t.discount_amount], ['Service', t.service_charge], [t.tax_mode === 'inclusive' ? 'Pajak (termasuk harga)' : 'Pajak', t.tax_amount]].map(function (r) {
          return parseFloat(r[1]) ? h('div', { class: 'row' },
            h('dt', { class: 'col-6 fw-normal', text: r[0] }),
            h('dd', { class: 'col-6 text-end', text: fmtIDR.format(parseFloat(r[1])) })) : null;
        }),
        h('dt', { class: 'col-6 h6', text: 'Total' }), h('dd', { class: 'col-6 text-end h6', text: fmtIDR.format(parseFloat(t.grand_total || 0)) }),
        h('dt', { class: 'col-6 fw-normal', text: 'Dibayar' }), h('dd', { class: 'col-6 text-end', text: fmtIDR.format(parseFloat(t.paid_total || 0)) }),
        parseFloat(t.grand_total || 0) > parseFloat(t.paid_total || 0) ? h('div', { class: 'row' },
          h('dt', { class: 'col-6 fw-semibold', text: 'Sisa piutang' }),
          h('dd', { class: 'col-6 text-end fw-semibold' },
            h('div', { text: fmtIDR.format(parseFloat(t.grand_total || 0) - parseFloat(t.paid_total || 0)) }),
            t.due_at ? h('small', { class: 'd-block fw-normal text-muted', text: 'Jatuh tempo: ' + t.due_at }) : null)) : null,
        h('dt', { class: 'col-6 fw-normal', text: 'Kembalian' }), h('dd', { class: 'col-6 text-end', text: fmtIDR.format(parseFloat(t.change_amount || 0)) })));
    bootstrap.Modal.getOrCreateInstance($('receiptModal')).show();
  }

  const receiptPaperStorageKey = 'pos-receipt-paper';
  const receiptPaperSize = $('receiptPaperSize');
  let savedReceiptPaper = '80';
  try {
    savedReceiptPaper = localStorage.getItem(receiptPaperStorageKey) || savedReceiptPaper;
  } catch (err) {
    showAlert('Preferensi ukuran kertas tidak dapat dibaca dari browser ini.');
  }
  receiptPaperSize.value = ['58', '80', 'a4'].indexOf(savedReceiptPaper) !== -1 ? savedReceiptPaper : '80';

  const serialConnectButton = $('receiptSerialConnect');
  const serialPrintButton = $('receiptSerialPrint');
  const serialStatus = $('receiptSerialStatus');
  const usbConnectButton = $('receiptUsbConnect');
  const usbPrintButton = $('receiptUsbPrint');
  const usbStatus = $('receiptUsbStatus');
  let receiptUsbDevice = null;
  let receiptUsbInterface = null;
  let receiptUsbEndpoint = null;
  if (!navigator.serial) {
    serialConnectButton.disabled = true;
    serialPrintButton.disabled = true;
    serialStatus.textContent = 'Web Serial tidak didukung browser ini; gunakan Cetak browser.';
  }
  if (!navigator.usb) {
    usbConnectButton.disabled = true;
    usbPrintButton.disabled = true;
    usbStatus.textContent = 'WebUSB tidak didukung browser ini; gunakan Cetak browser atau serial.';
  }

  function serialReceiptBytes(data) {
    const t = data.transaction || {};
    const width = receiptPaperSize.value === '58' ? 32 : 42;
    const encoder = new TextEncoder();
    const chunks = [];
    const command = (...values) => chunks.push(new Uint8Array(values));
    const line = (value = '') => chunks.push(encoder.encode(String(value) + '\n'));
    const safe = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '')
      .replace(/[^\x20-\x7e]/g, '?').replace(/[\r\n\t]/g, ' ').trim();
    const wrap = value => {
      const words = safe(value).split(/\s+/).filter(Boolean);
      const lines = [];
      let current = '';
      words.forEach(word => {
        while (word.length > width) {
          if (current) { lines.push(current); current = ''; }
          lines.push(word.slice(0, width));
          word = word.slice(width);
        }
        if (current && current.length + word.length + 1 > width) {
          lines.push(current);
          current = word;
        } else current = current ? current + ' ' + word : word;
      });
      if (current) lines.push(current);
      return lines;
    };
    const amount = value => 'Rp' + new Intl.NumberFormat('id-ID', { maximumFractionDigits: 0 }).format(Number(value) || 0);
    const pair = (label, value) => {
      const right = safe(value);
      const left = safe(label).slice(0, Math.max(1, width - right.length - 1));
      line(left + ' '.repeat(Math.max(1, width - left.length - right.length)) + right);
    };
    command(0x1b, 0x40);
    command(0x1b, 0x61, 0x01);
    command(0x1b, 0x45, 0x01);
    wrap(CFG.tenantName || 'Usaha').forEach(line);
    command(0x1b, 0x45, 0x00);
    if (CFG.branchName) wrap(CFG.branchName).forEach(line);
    if (CFG.branchAddress) wrap(CFG.branchAddress).forEach(line);
    if (CFG.branchPhone || CFG.businessPhone) line('Tel. ' + safe(CFG.branchPhone || CFG.businessPhone));
    line('='.repeat(width));
    command(0x1b, 0x61, 0x00);
    line('Invoice: ' + safe(t.invoice_no || '-'));
    line('Waktu: ' + safe(t.created_at || ''));
    if (t.customer_name) line('Pelanggan: ' + safe(t.customer_name));
    line('-'.repeat(width));
    (data.items || []).forEach(item => {
      wrap(item.item_name).forEach(line);
      pair((Number(item.qty) || 0) + ' ' + item.unit + ' x ' + amount(item.unit_price), amount(item.line_total));
    });
    line('-'.repeat(width));
    pair('Subtotal', amount(t.subtotal));
    if (Number(t.discount_amount) > 0) pair('Diskon', '-' + amount(t.discount_amount));
    if (Number(t.service_charge) > 0) pair('Service', amount(t.service_charge));
    if (Number(t.tax_amount) > 0) pair('Pajak', amount(t.tax_amount));
    command(0x1b, 0x45, 0x01);
    pair('TOTAL', amount(t.grand_total));
    command(0x1b, 0x45, 0x00);
    pair('Dibayar', amount(t.paid_total));
    if (Number(t.grand_total) > Number(t.paid_total)) {
      pair('Sisa piutang', amount(Number(t.grand_total) - Number(t.paid_total)));
      if (t.due_at) line('Jatuh tempo: ' + safe(t.due_at));
    }
    if (Number(t.change_amount) > 0) pair('Kembalian', amount(t.change_amount));
    (data.payments || []).forEach(payment => pair('Bayar ' + payment.method, amount(payment.amount)));
    command(0x1b, 0x61, 0x01);
    line('');
    line('Terima kasih');
    line('');
    line('');
    command(0x1d, 0x56, 0x01);
    const byteLength = chunks.reduce((total, chunk) => total + chunk.length, 0);
    const output = new Uint8Array(byteLength);
    let offset = 0;
    chunks.forEach(chunk => { output.set(chunk, offset); offset += chunk.length; });
    return output;
  }

  serialConnectButton.addEventListener('click', async function () {
    if (!navigator.serial) return;
    try {
      if (receiptSerialPort) {
        await receiptSerialPort.close();
        receiptSerialPort = null;
        serialConnectButton.textContent = 'Hubungkan serial';
        serialPrintButton.disabled = true;
        serialStatus.textContent = 'Printer serial terputus.';
        return;
      }
      receiptSerialPort = await navigator.serial.requestPort();
      await receiptSerialPort.open({ baudRate: Number($('receiptSerialBaud').value) });
      serialConnectButton.textContent = 'Putuskan serial';
      serialPrintButton.disabled = receiptPaperSize.value === 'a4';
      serialStatus.textContent = 'Printer serial terhubung.';
    } catch (error) {
      receiptSerialPort = null;
      serialPrintButton.disabled = true;
      serialStatus.textContent = 'Gagal menghubungkan printer: ' + (error.message || error);
    }
  });
  serialPrintButton.addEventListener('click', async function () {
    if (receiptPaperSize.value === 'a4') {
      serialStatus.textContent = 'Cetak ESC/POS hanya tersedia untuk nota thermal 58/80 mm.';
      return;
    }
    if (!receiptSerialPort || !currentReceiptData) {
      serialStatus.textContent = 'Hubungkan printer dan buka nota transaksi terlebih dahulu.';
      return;
    }
    let writer;
    try {
      writer = receiptSerialPort.writable.getWriter();
      await writer.write(serialReceiptBytes(currentReceiptData));
      serialStatus.textContent = 'Nota ESC/POS berhasil dikirim ke printer.';
    } catch (error) {
      serialStatus.textContent = 'Gagal mengirim nota: ' + (error.message || error);
    } finally {
      if (writer) writer.releaseLock();
    }
  });
  usbConnectButton.addEventListener('click', async function () {
    if (!navigator.usb) return;
    if (receiptUsbDevice) {
      const oldDevice = receiptUsbDevice;
      const oldInterface = receiptUsbInterface;
      receiptUsbDevice = null;
      receiptUsbInterface = null;
      receiptUsbEndpoint = null;
      try {
        await oldDevice.releaseInterface(oldInterface);
        await oldDevice.close();
        usbConnectButton.textContent = 'Hubungkan USB';
        usbPrintButton.disabled = true;
        usbStatus.textContent = 'Printer USB terputus.';
      } catch (error) {
        receiptUsbDevice = oldDevice;
        receiptUsbInterface = oldInterface;
        usbStatus.textContent = 'Gagal memutus USB: ' + (error.message || error);
      }
      return;
    }
    let candidate = null;
    try {
      candidate = await navigator.usb.requestDevice({ filters: [{ classCode: 7 }] });
      await candidate.open();
      if (!candidate.configuration) {
        const configuration = candidate.configurations[0];
        if (!configuration) throw new Error('Perangkat tidak menyediakan konfigurasi USB.');
        await candidate.selectConfiguration(configuration.configurationValue);
      }
      let selected = null;
      candidate.configuration.interfaces.some(function (usbInterface) {
        return usbInterface.alternates.some(function (alternate) {
          if (alternate.interfaceClass !== 7) return false;
          const endpoint = alternate.endpoints.find(item => item.direction === 'out' && item.type === 'bulk');
          if (!endpoint) return false;
          selected = {
            interfaceNumber: usbInterface.interfaceNumber,
            alternateSetting: alternate.alternateSetting,
            endpointNumber: endpoint.endpointNumber
          };
          return true;
        });
      });
      if (!selected) throw new Error('Antarmuka USB bulk-out kelas printer tidak ditemukan.');
      await candidate.claimInterface(selected.interfaceNumber);
      if (selected.alternateSetting !== 0) {
        await candidate.selectAlternateInterface(selected.interfaceNumber, selected.alternateSetting);
      }
      receiptUsbDevice = candidate;
      receiptUsbInterface = selected.interfaceNumber;
      receiptUsbEndpoint = selected.endpointNumber;
      usbConnectButton.textContent = 'Putuskan USB';
      usbPrintButton.disabled = receiptPaperSize.value === 'a4';
      usbStatus.textContent = 'Printer USB ESC/POS terhubung.';
    } catch (error) {
      let cleanup = '';
      if (candidate && candidate.opened) {
        try { await candidate.close(); } catch (closeError) { cleanup = ' Gagal menutup perangkat: ' + closeError.message; }
      }
      usbStatus.textContent = 'Gagal menghubungkan USB: ' + (error.message || error) + cleanup;
    }
  });
  usbPrintButton.addEventListener('click', async function () {
    if (receiptPaperSize.value === 'a4') {
      usbStatus.textContent = 'Cetak ESC/POS hanya tersedia untuk nota thermal 58/80 mm.';
      return;
    }
    if (!receiptUsbDevice || !currentReceiptData || receiptUsbEndpoint === null) {
      usbStatus.textContent = 'Hubungkan printer USB dan buka nota transaksi terlebih dahulu.';
      return;
    }
    try {
      const result = await receiptUsbDevice.transferOut(receiptUsbEndpoint, serialReceiptBytes(currentReceiptData));
      if (result.status !== 'ok') throw new Error('Transfer USB berstatus ' + result.status + '.');
      usbStatus.textContent = 'Nota ESC/POS berhasil dikirim ke printer USB.';
    } catch (error) {
      usbStatus.textContent = 'Gagal mengirim nota USB: ' + (error.message || error);
    }
  });

  function printReceipt() {
    const paper = receiptPaperSize.value;
    document.body.classList.remove('receipt-paper-58', 'receipt-paper-80', 'receipt-paper-a4');
    document.body.classList.add(paper === 'a4' ? 'receipt-paper-a4' : 'receipt-paper-' + paper);

    let pageRule = document.getElementById('receiptPrintPageRule');
    if (!pageRule) {
      pageRule = document.createElement('style');
      pageRule.id = 'receiptPrintPageRule';
      document.head.appendChild(pageRule);
    }
    pageRule.textContent = paper === 'a4'
      ? '@page { size: A4 portrait; margin: 10mm; }'
      : '@page { size: ' + paper + 'mm auto; margin: 0; }';

    window.print();
  }

  receiptPaperSize.addEventListener('change', function () {
    serialPrintButton.disabled = !receiptSerialPort || receiptPaperSize.value === 'a4';
    usbPrintButton.disabled = !receiptUsbDevice || receiptPaperSize.value === 'a4';
    if (receiptPaperSize.value === 'a4' && receiptSerialPort) {
      serialStatus.textContent = 'Cetak ESC/POS hanya tersedia untuk nota thermal 58/80 mm.';
    } else if (receiptPaperSize.value !== 'a4' && receiptSerialPort) {
      serialStatus.textContent = 'Printer serial terhubung.';
    }
    if (receiptPaperSize.value === 'a4' && receiptUsbDevice) {
      usbStatus.textContent = 'Cetak ESC/POS hanya tersedia untuk nota thermal 58/80 mm.';
    } else if (receiptPaperSize.value !== 'a4' && receiptUsbDevice) {
      usbStatus.textContent = 'Printer USB ESC/POS terhubung.';
    }
    try {
      localStorage.setItem(receiptPaperStorageKey, receiptPaperSize.value);
    } catch (err) {
      showAlert('Preferensi ukuran kertas tidak dapat disimpan di browser ini.');
    }
  });
  $('printReceiptButton').addEventListener('click', printReceipt);

  const holdStorageKey = 'pos-holds:' + String(CFG.tenantId || '') + ':' + String(CFG.branchId || '');

  function readHolds() {
    const raw = localStorage.getItem(holdStorageKey);
    if (!raw) return [];
    const saved = JSON.parse(raw);
    if (!Array.isArray(saved)) throw new Error('Data transaksi ditahan tidak valid.');
    return saved;
  }

  function writeHolds(saved) {
    localStorage.setItem(holdStorageKey, JSON.stringify(saved));
    $('holdCount').textContent = String(saved.length);
  }

  function renderHolds() {
    const list = $('holdsList');
    clear(list);
    try {
      const holds = readHolds();
      $('holdCount').textContent = String(holds.length);
      if (!holds.length) {
        list.append(h('div', { class: 'text-center text-muted py-4', text: 'Belum ada transaksi yang ditahan di perangkat ini.' }));
        return;
      }
      holds.slice().reverse().forEach(function (record) {
        const label = record.label || 'Transaksi';
        const count = (record.cart || []).length;
        list.append(h('div', { class: 'd-flex justify-content-between align-items-center gap-2 border rounded-3 p-3 mb-2' },
          h('div', { class: 'min-w-0' },
            h('div', { class: 'fw-semibold text-truncate', text: label }),
            h('div', { class: 'small text-muted', text: count + ' item · ' + new Date(record.createdAt).toLocaleString('id-ID') })),
          h('div', { class: 'd-flex gap-2 flex-shrink-0' },
            h('button', { type: 'button', class: 'btn btn-sm btn-primary', onclick: function () { resumeHold(record.id); } }, 'Lanjutkan'),
            h('button', { type: 'button', class: 'btn btn-sm btn-outline-danger', 'aria-label': 'Hapus transaksi ditahan', onclick: function () { deleteHold(record.id); } }, 'Hapus'))));
      });
    } catch (err) {
      list.append(h('div', { class: 'alert alert-danger small mb-0', role: 'alert', text: 'Data transaksi tertahan tidak dapat dibaca: ' + err.message }));
    }
  }

  function holdCurrent() {
    if (!state.cart.length || state.busy) return;
    try {
      const holds = readHolds();
      if (holds.length >= 20) {
        showAlert('Maksimal 20 transaksi dapat ditahan pada perangkat ini.');
        return;
      }
      holds.push({
        id: uuid(),
        label: state.notes.trim() || ('Pesanan ' + new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })),
        createdAt: new Date().toISOString(),
        cart: state.cart.map(function (line) {
          return { key: line.key, p: line.p, qty: line.qty, attrs: line.attrs, discount: line.discount, group: line.group };
        }),
        orderType: state.orderType,
        tableId: state.tableId,
        customerId: state.customerId,
        onAccount: state.onAccount,
        dueAt: state.dueAt,
        payments: state.payments.map(function (payment) { return { method: payment.method, amount: payment.amount, reference_no: payment.reference_no || '' }; }),
        discount: state.discount,
        notes: state.notes,
        rx: state.rx,
        customerName: state.customerName
      });
      writeHolds(holds);
      resetAll();
      renderHolds();
      showAlert('');
    } catch (err) {
      showAlert('Transaksi belum ditahan. Penyimpanan perangkat gagal: ' + err.message);
    }
  }

  function resumeHold(id) {
    try {
      const holds = readHolds();
      const index = holds.findIndex(function (record) { return record.id === id; });
      if (index < 0) throw new Error('Transaksi tidak ditemukan.');
      if (state.cart.length && !window.confirm('Ganti keranjang saat ini dengan transaksi yang ditahan?')) return;
      const record = holds[index];
      if (!Array.isArray(record.cart) || !record.cart.every(function (line) {
        return line && line.p && Number.isInteger(Number(line.p.variant_id))
          && Number(line.qty) > 0 && line.attrs && typeof line.attrs === 'object';
      })) throw new Error('Isi transaksi tertahan rusak.');
      holds.splice(index, 1);
      writeHolds(holds);
      state.cart = record.cart || [];
      state.orderType = ORDER_TYPES.some(function (type) { return type.v === record.orderType; })
        ? record.orderType : (ORDER_TYPES.length ? ORDER_TYPES[0].v : '');
      const selectedOrderType = ORDER_TYPES.find(function (type) { return type.v === state.orderType; });
      state.productFilter = selectedOrderType ? selectedOrderType.m : 'all';
      state.tableId = record.tableId || '';
      state.customerId = record.customerId || null;
      state.onAccount = !!record.onAccount;
      state.dueAt = record.dueAt || '';
      state.payments = Array.isArray(record.payments) ? record.payments : [];
      if (!state.payments.length) addPayRow('cash');
      state.discount = record.discount || '';
      state.notes = record.notes || '';
      state.rx = record.rx || '';
      state.customerId = Number(record.customerId) > 0 ? Number(record.customerId) : null;
      state.customerName = typeof record.customerName === 'string' ? record.customerName : '';
      $('customerSearch').value = state.customerName;
      $('customerSelected').textContent = state.customerId
        ? 'Pelanggan terpilih: ' + state.customerName
        : 'Transaksi tanpa pilihan akan dicatat sebagai pelanggan umum.';
      state.lineErrors = {};
      touch();
      $('orderType').value = state.orderType;
      renderProductFilters();
      renderGrid();
      $('tableSelect').value = state.tableId;
      $('tableSelect').classList.toggle('d-none', state.orderType !== 'dine_in');
      const onAccountToggle = $('onAccountToggle');
      const creditDueAt = $('creditDueAt');
      const creditSaleFields = $('creditSaleFields');
      if (onAccountToggle) onAccountToggle.checked = state.onAccount;
      if (creditDueAt) {
        creditDueAt.value = state.dueAt;
        creditDueAt.required = state.onAccount;
      }
      if (creditSaleFields) creditSaleFields.classList.toggle('d-none', !state.onAccount);
      $('discInput').value = state.discount;
      $('notesInput').value = state.notes;
      $('rxInput').value = state.rx;
      renderPayments();
      renderAll();
      bootstrap.Modal.getOrCreateInstance($('holdsModal')).hide();
      loadProducts('', false);
    } catch (err) {
      showAlert('Transaksi belum dapat dilanjutkan: ' + err.message);
    }
  }

  function deleteHold(id) {
    try {
      writeHolds(readHolds().filter(function (record) { return record.id !== id; }));
      renderHolds();
    } catch (err) {
      showAlert('Transaksi tertahan tidak dapat dihapus: ' + err.message);
    }
  }

  function localDate() {
    const now = new Date();
    return now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(now.getDate()).padStart(2, '0');
  }

  function loadHistory() {
    const message = $('historyMessage');
    message.textContent = 'Memuat transaksi...';
    const qs = new URLSearchParams({ from: $('historyFrom').value, to: $('historyTo').value, q: $('historySearch').value.trim() });
    api('/api/transactions?' + qs.toString()).then(function (json) {
      const list = $('historyList');
      clear(list);
      const items = (json.data && json.data.items) || [];
      message.textContent = items.length ? 'Menampilkan ' + items.length + ' transaksi terbaru.' : 'Tidak ada transaksi pada rentang ini.';
      if (!items.length) return;
      const body = h('tbody', null, items.map(function (item) {
        return h('tr', null,
          h('td', null, h('div', { class: 'fw-semibold', text: item.invoice_no }), h('div', { class: 'small text-muted', text: new Date(String(item.created_at).replace(' ', 'T')).toLocaleString('id-ID') })),
          h('td', { class: 'text-nowrap', text: item.order_type }),
          h('td', { class: 'text-nowrap', text: item.cashier_name || '-' }),
          h('td', { class: 'text-end text-nowrap', text: fmtIDR.format(Number(item.grand_total || 0)) }),
          h('td', null, h('span', { class: 'badge ' + (item.status === 'refunded' ? 'text-bg-warning' : 'text-bg-success'), text: item.status })),
          h('td', null, h('button', { type: 'button', class: 'btn btn-sm btn-outline-primary', onclick: function () { openTransaction(item.id); } }, 'Detail')));
      }));
      list.append(h('div', { class: 'table-responsive' }, h('table', { class: 'table table-hover align-middle mb-0' },
        h('thead', { class: 'table-light' }, h('tr', null, ['Invoice / Waktu', 'Tipe', 'Kasir', 'Total', 'Status', ''].map(function (label) { return h('th', { scope: 'col', class: label === 'Total' ? 'text-end' : '', text: label }); }))),
        body)));
    }).catch(function (err) { message.textContent = err.message || 'Riwayat gagal dimuat.'; });
  }

  async function openTransaction(id) {
    $('historyMessage').textContent = 'Memuat detail transaksi...';
    try {
      const json = await api('/api/transactions?id=' + encodeURIComponent(id));
      renderTransactionDetail(json.data.transaction);
    } catch (err) {
      $('historyMessage').textContent = err.message || 'Detail transaksi gagal dimuat.';
    }
  }

  function renderTransactionDetail(transaction) {
    const list = $('historyList');
    clear(list);
    $('historyMessage').textContent = '';
    const remainingQty = function (item) {
      return Math.max(0, Number(item.qty || 0) - Number(item.returned_qty || 0));
    };
    const rows = transaction.items.map(function (item) {
      const remain = remainingQty(item);
      return h('tr', null,
        h('td', null, h('div', { class: 'fw-semibold', text: item.item_name }), h('div', { class: 'small text-muted', text: Number(item.qty) + ' ' + item.unit + (Number(item.returned_qty) ? ' · sudah retur ' + Number(item.returned_qty) : '') })),
        h('td', { class: 'text-end text-nowrap', text: fmtIDR.format(Number(item.line_total || 0)) }),
        CFG.canReturn && remain > 0
          ? h('td', { style: 'width:150px' }, h('input', { type: 'number', min: '0', max: String(remain), step: '0.001', class: 'form-control form-control-sm return-qty', 'data-item-id': String(item.id), 'data-remain': String(remain), placeholder: 'Jumlah retur' }))
          : h('td', { class: 'small text-muted', text: remain > 0 ? 'Tidak dapat retur' : 'Sudah diretur' }));
    });
    const section = [
      h('button', { type: 'button', class: 'btn btn-sm btn-outline-secondary mb-3', onclick: loadHistory }, '← Kembali ke riwayat'),
      h('div', { class: 'd-flex justify-content-between align-items-start flex-wrap gap-2 mb-3' },
        h('div', null, h('div', { class: 'h5 mb-1', text: transaction.invoice_no }), h('div', { class: 'small text-muted', text: new Date(String(transaction.created_at).replace(' ', 'T')).toLocaleString('id-ID') })),
        h('span', { class: 'badge text-bg-secondary', text: transaction.status })),
      h('div', { class: 'table-responsive mb-3' }, h('table', { class: 'table align-middle mb-0' },
        h('thead', { class: 'table-light' }, h('tr', null, ['Item', 'Nilai', CFG.canReturn ? 'Retur (qty)' : 'Status retur'].map(function (label) { return h('th', { class: label === 'Nilai' ? 'text-end' : '', text: label }); }))),
        h('tbody', null, rows))),
      h('div', { class: 'text-end fw-bold mb-3', text: 'Total transaksi: ' + fmtIDR.format(Number(transaction.grand_total || 0)) })
    ];
    if (CFG.canReturn && transaction.items.some(function (item) { return remainingQty(item) > 0; })) {
      const method = h('select', { class: 'form-select form-select-sm', id: 'returnMethod' },
        PAY_METHODS.map(function (m) { return h('option', { value: m[0] }, m[1]); }));
      const note = h('input', { class: 'form-control form-control-sm', id: 'returnNote', maxlength: '255', placeholder: 'Catatan retur (opsional)' });
      section.push(h('div', { class: 'row g-2 align-items-end' },
        h('div', { class: 'col-md-3' }, h('label', { class: 'form-label small', for: 'returnMethod' }, 'Dana dikembalikan via'), method),
        h('div', { class: 'col-md-6' }, h('label', { class: 'form-label small', for: 'returnNote' }, 'Catatan'), note),
        h('div', { class: 'col-md-3' }, h('button', { type: 'button', class: 'btn btn-danger btn-sm w-100', onclick: function () { submitReturn(transaction.id); } }, 'Proses retur'))));
      section.push(h('div', { class: 'form-text mt-2', text: 'Hanya pemilik/manajer. Pengembalian dana dicatat; stok barang yang terlacak akan ditambahkan kembali.' }));
    }
    list.append(section);
  }

  async function submitReturn(transactionId) {
    const selected = Array.prototype.slice.call(document.querySelectorAll('.return-qty'))
      .map(function (input) { return { item_id: input.getAttribute('data-item-id'), qty: input.value }; })
      .filter(function (item) { return Number(item.qty) > 0; });
    if (!selected.length) {
      $('historyMessage').textContent = 'Isi jumlah retur minimal satu item.';
      return;
    }
    if (!window.confirm('Catat retur item yang dipilih? Proses ini tidak dapat dibatalkan.')) return;
    try {
      const json = await api('/api/returns', {
        method: 'POST',
        body: JSON.stringify({
          transaction_id: transactionId,
          refund_method: $('returnMethod').value,
          note: $('returnNote').value,
          items: selected
        })
      });
      $('historyMessage').textContent = json.message + ' Pengembalian: ' + fmtIDR.format(Number(json.data.refund_amount || 0));
      await openTransaction(transactionId);
      $('historyMessage').textContent = json.message + ' Pengembalian: ' + fmtIDR.format(Number(json.data.refund_amount || 0));
    } catch (err) {
      $('historyMessage').textContent = err.message || 'Retur gagal diproses.';
    }
  }

  async function loadReport() {
    $('reportMessage').textContent = 'Memuat laporan...';
    clear($('reportContent'));
    const qs = new URLSearchParams({ from: $('reportFrom').value, to: $('reportTo').value });
    $('reportExport').classList.add('disabled');
    $('reportExport').setAttribute('aria-disabled', 'true');
    try {
      const json = await api('/api/pos-report?' + qs.toString());
      const data = json.data;
      $('reportExport').href = BASE + '/api/pos-report?' + qs.toString() + '&format=csv';
      $('reportExport').classList.remove('disabled');
      $('reportExport').removeAttribute('aria-disabled');
      const content = $('reportContent');
      clear(content);
      const s = data.summary || {};
      function count(value) { return Number(value || 0).toLocaleString('id-ID'); }
      function currency(value) { return fmtIDR.format(Number(value || 0)); }
      function paymentLabel(method) {
        return (PAY_METHODS.find(function (item) { return item[0] === method; }) || [method, method])[1];
      }
      function orderLabel(type) {
        const labels = { retail: 'Retail', dine_in: 'Makan di tempat', takeaway: 'Bawa pulang', pharmacy: 'Apotek', laundry: 'Laundry', printing: 'Percetakan', service: 'Jasa' };
        return labels[type] || String(type || '-').replace(/_/g, ' ');
      }
      function itemTypeLabel(type) {
        const labels = { goods: 'Produk retail', menu: 'Makanan / minuman', medicine: 'Obat', laundry: 'Laundry', print: 'Percetakan', service: 'Jasa' };
        return labels[type] || type || '-';
      }
      function appendTable(title, headers, rows, emptyMessage) {
        const table = h('table', { class: 'table table-sm table-hover align-middle mb-0' },
          h('thead', { class: 'table-light' }, h('tr', null, headers.map(function (label, index) {
            return h('th', { class: index > 0 ? 'text-end text-nowrap' : '', scope: 'col', text: label });
          }))),
          h('tbody', null, rows.length ? rows.map(function (row) {
            return h('tr', null, row.map(function (cell, index) {
              return h('td', { class: index > 0 ? 'text-end text-nowrap' : '', text: cell });
            }));
          }) : [h('tr', null, h('td', { colspan: String(headers.length), class: 'text-center text-muted py-3', text: emptyMessage }))]));
        content.append(h('section', { class: 'mb-4' }, h('h3', { class: 'h6 fw-bold mb-2', text: title }),
          h('div', { class: 'table-responsive' }, table)));
      }
      const transactionCount = Number(s.transactions || 0);
      content.append(h('div', { class: 'row g-2 mb-4' },
        [
          ['Transaksi lunas', count(transactionCount)],
          ['Omzet', currency(s.gross_sales)],
          ['Diskon', currency(s.discounts)],
          ['Retur', currency(s.refunds)],
          ['Penjualan bersih', currency(s.net_sales)],
          ['Rata-rata transaksi', currency(transactionCount ? Number(s.gross_sales || 0) / transactionCount : 0)]
        ].map(function (stat) {
          return h('div', { class: 'col-6 col-md-4 col-xl-2' },
            h('div', { class: 'stat-card h-100' },
              h('div', { class: 'stat-label', text: stat[0] }),
              h('div', { class: 'stat-value', text: stat[1] })));
        })));
      content.append(h('div', { class: 'alert alert-light border py-2 small mb-4' },
        'Rincian total periode: subtotal ', currency(s.subtotal), ' · diskon ', currency(s.discounts),
        ' · biaya layanan ', currency(s.service_charge), ' · pajak ', currency(s.tax),
        '. Laba produk merupakan estimasi dari modal yang tercatat pada item transaksi.'));

      appendTable('Pembayaran per metode', ['Metode', 'Nominal diterima', 'Jumlah pembayaran'],
        (data.payments || []).map(function (row) { return [paymentLabel(row.method), currency(row.amount), count(row.payments)]; }),
        'Belum ada pembayaran.');
      appendTable('Rekonsiliasi pembayaran', ['Rincian', 'Nominal'],
        [
          ['Uang diterima sebelum kembalian', currency(s.tender_received)],
          ['Kembalian', currency(s.change_given)],
          ['Total pembayaran bersih', currency(s.gross_sales)]
        ], 'Tidak ada transaksi lunas.');
      appendTable('Penjualan per jenis usaha', ['Jenis transaksi', 'Jumlah', 'Omzet', 'Rata-rata'],
        (data.by_order_type || []).map(function (row) {
          return [orderLabel(row.order_type), count(row.transactions), currency(row.sales), currency(row.average_ticket)];
        }), 'Belum ada transaksi.');
      appendTable('Kinerja kasir', ['Kasir', 'Transaksi', 'Omzet', 'Rata-rata transaksi'],
        (data.by_cashier || []).map(function (row) { return [row.cashier_name || '-', count(row.transactions), currency(row.sales), currency(row.average_ticket)]; }),
        'Belum ada transaksi.');
      appendTable('Produk / layanan terlaris (maks. 100)', ['Produk / layanan', 'Jenis', 'Qty terjual', 'Penjualan', 'Modal', 'Laba kotor*'],
        (data.top_products || []).map(function (row) {
          return [row.item_name || '-', itemTypeLabel(row.item_type), count(row.quantity), currency(row.sales), currency(row.cost), currency(row.gross_profit)];
        }), 'Belum ada item terjual.');
      appendTable('Jam sibuk', ['Jam', 'Transaksi', 'Omzet'],
        (data.by_hour || []).map(function (row) { return [String(row.hour).padStart(2, '0') + ':00', count(row.transactions), currency(row.sales)]; }),
        'Belum ada transaksi.');
      appendTable('Ringkasan harian', ['Tanggal', 'Transaksi', 'Omzet', 'Retur', 'Bersih'],
        (data.daily || []).map(function (row) { return [row.day, count(row.transactions), currency(row.gross_sales), currency(row.refunds), currency(row.net_sales)]; }),
        'Belum ada transaksi.');
      appendTable('Pengembalian dana per metode', ['Metode', 'Nominal retur', 'Jumlah retur'],
        (data.refunds_by_method || []).map(function (row) { return [paymentLabel(row.method), currency(row.amount), count(row.returns_count)]; }),
        'Belum ada pengembalian.');
      appendTable('Riwayat retur (maks. 200)', ['Waktu', 'Invoice', 'Diproses oleh', 'Metode', 'Nominal', 'Catatan'],
        (data.returns || []).map(function (row) {
          return [row.returned_at, row.invoice_no || '-', row.cashier_name || '-', paymentLabel(row.refund_method), currency(row.refund_amount), row.note || '-'];
        }), 'Tidak ada retur pada periode ini.');
      appendTable('Peringatan stok minimum (stok saat ini)', ['Produk', 'SKU', 'Stok', 'Batas minimum'],
        (data.low_stock || []).map(function (row) {
          return [row.product_name + (row.variant_name && row.variant_name !== 'Default' ? ' · ' + row.variant_name : ''),
            row.sku || '-', count(row.stock_qty) + ' ' + (row.unit || ''), count(row.min_stock) + ' ' + (row.unit || '')];
        }), 'Tidak ada stok di bawah batas minimum.');
      const movementLabels = { sale: 'Penjualan', purchase: 'Pembelian', adjustment: 'Penyesuaian', transfer_in: 'Transfer masuk', transfer_out: 'Transfer keluar', return: 'Retur', void: 'Pembatalan' };
      appendTable('Pergerakan stok pada periode', ['Jenis pergerakan', 'Jumlah catatan', 'Perubahan qty'],
        (data.stock_movements || []).map(function (row) {
          return [movementLabels[row.movement_type] || row.movement_type, count(row.movements), Number(row.quantity_change || 0).toLocaleString('id-ID', { maximumFractionDigits: 3 })];
        }), 'Tidak ada pergerakan stok.');
      $('reportMessage').textContent = 'Periode ' + data.from + ' sampai ' + data.to;
    } catch (err) {
      $('reportMessage').textContent = err.message || 'Laporan gagal dimuat.';
    }
  }

  function resetAll() {
    state.cart = [];
    state.payments = [];
    addPayRow('cash');
    state.discount = '';
    state.notes = '';
    state.onAccount = false;
    state.dueAt = '';
    const onAccountToggle = $('onAccountToggle');
    const creditDueAt = $('creditDueAt');
    if (onAccountToggle) onAccountToggle.checked = false;
    if (creditDueAt) creditDueAt.value = '';
    const creditSaleFields = $('creditSaleFields');
    if (creditSaleFields) creditSaleFields.classList.add('d-none');
    state.rx = '';
    state.customerId = null;
    state.customerName = '';
    state.lineErrors = {};
    state.idemKey = null;
    $('discInput').value = '';
    $('notesInput').value = '';
    $('rxInput').value = '';
    $('customerSearch').value = '';
    $('customerSelected').textContent = 'Transaksi tanpa pilihan akan dicatat sebagai pelanggan umum.';
    $('customerResults').classList.add('d-none');
    renderPayments();
    renderAll();
    loadProducts($('searchInput').value.trim(), false); // segarkan stok
  }

  // ===== Event =====
  function init() {
    const today = localDate();
    ['historyFrom', 'historyTo', 'reportFrom', 'reportTo'].forEach(function (id) { $(id).value = today; });
    try { $('holdCount').textContent = String(readHolds().length); } catch (err) { $('holdCount').textContent = '!'; }
    $('holdsModal').addEventListener('show.bs.modal', renderHolds);
    $('historyModal').addEventListener('show.bs.modal', loadHistory);
    $('reportModal').addEventListener('show.bs.modal', loadReport);
    $('historyLoad').addEventListener('click', loadHistory);
    $('reportLoad').addEventListener('click', loadReport);
    $('btnHold').addEventListener('click', holdCurrent);
    const ot = $('orderType');
    if (!ORDER_TYPES.length) {
      showAlert('Tidak ada modul aktif untuk toko ini. Hubungi administrator.');
    }
    ORDER_TYPES.forEach(function (o) { ot.append(h('option', { value: o.v }, o.l)); });
    renderProductFilters();
    ot.addEventListener('change', function () {
      state.orderType = ot.value;
      const selectedOrder = ORDER_TYPES.find(function (type) { return type.v === ot.value; });
      if (selectedOrder) {
        state.productFilter = selectedOrder.m;
        renderProductFilters();
        renderGrid();
      }
      $('tableSelect').classList.toggle('d-none', ot.value !== 'dine_in');
      touch();
      renderAll();
    });

    const ts = $('tableSelect');
    ts.append(h('option', { value: '' }, 'Pilih meja'));
    (CFG.tables || []).forEach(function (t) {
      ts.append(h('option', { value: String(t.id), disabled: ['available', 'occupied'].indexOf(t.status) < 0 }, t.name + (t.status !== 'available' ? ' (' + t.status + ')' : '')));
    });
    ts.addEventListener('change', function () { state.tableId = ts.value; touch(); renderTotals(); });
    ts.classList.toggle('d-none', state.orderType !== 'dine_in');

    if (!CFG.canDiscount) $('discGroup').classList.add('d-none');
    $('discInput').addEventListener('input', function (e) { state.discount = e.target.value; touch(); renderTotals(); });
    $('notesInput').addEventListener('input', function (e) { state.notes = e.target.value; touch(); });
    $('rxInput').addEventListener('input', function (e) { state.rx = e.target.value; touch(); renderTotals(); });
    $('customerSearch').addEventListener('input', function (e) {
      if (state.customerId !== null) {
        state.customerId = null;
        state.customerName = '';
        $('customerSelected').textContent = 'Pilih pelanggan dari hasil pencarian atau lanjut sebagai pelanggan umum.';
        touch();
      }
      searchCustomers(e.target.value);
    });
    $('customerClear').addEventListener('click', function () {
      state.customerId = null;
      state.customerName = '';
      $('customerSearch').value = '';
      $('customerResults').classList.add('d-none');
      $('customerSelected').textContent = 'Transaksi tanpa pilihan akan dicatat sebagai pelanggan umum.';
      touch();
    });
    document.addEventListener('click', function (event) {
      if (!$('customerGroup').contains(event.target)) $('customerResults').classList.add('d-none');
    });

    $('btnAddPay').addEventListener('click', function () { addPayRow('qris'); renderPayments(); renderTotals(); });
    const onAccountToggle = $('onAccountToggle');
    const creditDueAt = $('creditDueAt');
    const creditSaleFields = $('creditSaleFields');
    if (onAccountToggle && creditDueAt && creditSaleFields) {
      onAccountToggle.addEventListener('change', function () {
        state.onAccount = onAccountToggle.checked;
        creditSaleFields.classList.toggle('d-none', !state.onAccount);
        creditDueAt.required = state.onAccount;
        if (!state.onAccount) {
          state.dueAt = '';
          creditDueAt.value = '';
        }
        touch();
      });
      creditDueAt.addEventListener('change', function () {
        state.dueAt = creditDueAt.value;
        touch();
      });
    }
    $('btnExact').addEventListener('click', function () {
      if (!state.payments.length) addPayRow('cash');
      const first = state.payments[0];
      const others = state.payments.slice(1).reduce(function (s, p) { return s + toCents(p.amount); }, 0);
      first.amount = String(Math.max(0, totals().grand - others) / 100);
      touch();
      renderPayments();
      renderTotals();
    });
    $('btnClear').addEventListener('click', function () {
      if (!state.cart.length || window.confirm('Kosongkan keranjang?')) resetAll();
    });
    $('btnCheckout').addEventListener('click', checkout);

    const si = $('searchInput');
    si.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () { loadProducts(si.value.trim(), false); }, 300);
    });
    si.addEventListener('keydown', async function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      const v = si.value.trim();
      if (!v) return;
      clearTimeout(searchTimer);
      const exact = await loadProducts(v, true);              // scan barcode: cocokkan persis
      if (exact && exact.length === 1) {
        pickProduct(exact[0]);
        si.value = '';
        loadProducts('', false);
      } else if (exact && exact.length === 0) {
        loadProducts(v, false);                               // bukan barcode: cari biasa
      }
    });

    addPayRow('cash');
    renderPayments();
    renderAll();
    loadProducts('', false);
  }

  init();
})();
</script>
</body>
</html>
