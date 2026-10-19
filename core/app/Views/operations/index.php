<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($value): string => 'Rp' . number_format((float) $value, 2, ',', '.');
$qty = static fn($value): string => number_format((float) $value, 3, ',', '.');
$canManage = in_array($context['role'], ['owner', 'manager'], true);
$sidebarBase = $base;
$sidebarBusiness = $context['tenant_name'];
$sidebarBranch = $context['branch_name'];
$sidebarRole = $context['role'];
$sidebarUser = \App\Core\Auth::name();
$sidebarPage = 'operations';
$sidebarActive = 'shift';
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Operasional kasir - <?= $e($context['tenant_name']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root { --ink:#17243b; --muted:#738096; --blue:#3157d5; }
    body { min-height:100vh; display:flex; flex-direction:column; background:#f3f5fa; color:var(--ink); }
    main { flex:1 0 auto; width:100%; }
    .page-wrap { max-width:1480px; }
    .surface { min-width:0; background:#fff; border:1px solid #e5e9f2; border-radius:16px; }
    .brand-mark { display:grid; place-items:center; width:38px; height:38px; border-radius:12px; color:#fff; background:var(--blue); font-weight:800; }
    .kicker { color:var(--blue); font-size:.72rem; font-weight:750; letter-spacing:.08em; text-transform:uppercase; }
    .section-title { font-size:1.05rem; font-weight:750; }
    .operation-card { border:1px solid #e5e9f2; border-radius:13px; background:#fbfcff; }
    .table-responsive { border:1px solid #e8ecf4; border-radius:12px; }
    .stat { border:1px solid #e5e9f2; border-radius:12px; padding:12px; background:#f8f9fc; }
    .stat-label { font-size:.77rem; color:var(--muted); }
    .stat-value { font-size:1.2rem; font-weight:750; overflow-wrap:anywhere; }
    .footer { border-top:1px solid #e5e9f2; background:#fff; color:var(--muted); }
    .count-list { max-height:420px; overflow:auto; }
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
    @media(max-width:575.98px) { main.container-fluid { padding-left:12px; padding-right:12px; } }
  </style>
</head>
<body>
<div class="app-shell">
<?php require __DIR__ . '/../partials/sidebar.php'; ?>
<div class="app-content">
<nav class="navbar bg-white border-bottom shadow-sm">
  <div class="container-fluid page-wrap d-flex justify-content-between gap-3 py-2">
    <a class="d-flex align-items-center gap-2 text-decoration-none text-dark" href="<?= $e($base) ?>/operations">
      <span class="brand-mark" aria-hidden="true">K</span>
      <span class="text-truncate"><span class="fw-bold d-block text-truncate"><?= $e($context['tenant_name']) ?></span><span class="small text-muted"><?= $e($context['branch_name']) ?> · Operasional</span></span>
    </a>
    <div class="d-flex gap-2">
      <a class="btn btn-primary btn-sm" href="<?= $e($base) ?>/pos">Buka kasir</a>
      <?php if ($context['role'] === 'owner'): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/owner">Dashboard</a><?php elseif ($canManage): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/store">Katalog</a><?php endif; ?>
    </div>
  </div>
</nav>
<main class="container-fluid page-wrap py-4">
  <header class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
    <div><div class="kicker">Operasional &amp; kontrol toko</div><h1 class="h3 fw-bold mb-1">Kas, pembelian, dan persediaan</h1><p class="text-muted mb-0">Data terpisah untuk <?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?>.</p></div>
    <span class="badge text-bg-light border text-dark"><?= $e(ucfirst($context['role'])) ?></span>
  </header>
  <?php if ($flash): ?><div class="alert alert-<?= $e($flash['type']) ?> py-2" role="status"><?= $e($flash['message']) ?></div><?php endif; ?>
  <section id="shift" class="surface p-3 p-lg-4 mb-3">
    <div class="mb-3"><h2 class="section-title mb-1">Shift kas bersama &amp; rekonsiliasi laci</h2><p class="small text-muted mb-0">Satu shift aktif untuk satu laci di cabang ini. Semua kasir dapat bertransaksi pada shift bersama; sistem tetap mencatat nama kasir di setiap penjualan.</p></div>
    <?php if (!$openShift): ?>
      <form method="post" action="<?= $e($base) ?>/operations/shifts/open" class="operation-card p-3">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <div class="row g-3 align-items-end">
          <div class="col-md-4"><label class="form-label small fw-semibold" for="openingCash">Modal awal laci (Rp)</label><input class="form-control" id="openingCash" name="opening_cash" type="number" min="0" step="0.01" required value="0"></div>
          <div class="col-md-6"><label class="form-label small fw-semibold" for="openingNote">Catatan (opsional)</label><input class="form-control" id="openingNote" name="note" maxlength="255"></div>
          <div class="col-md-2"><button class="btn btn-success w-100" type="submit">Buka shift</button></div>
        </div>
      </form>
    <?php else: ?>
      <div class="row g-2 mb-3">
        <div class="col-sm-4"><div class="stat"><div class="stat-label">Dibuka oleh · <?= $e($openShift['cashier_name']) ?></div><div class="stat-value"><?= $e(date('d M Y H:i', strtotime((string) $openShift['opened_at']))) ?></div></div></div>
        <div class="col-sm-4"><div class="stat"><div class="stat-label">Modal awal</div><div class="stat-value"><?= $money($openShift['opening_cash']) ?></div></div></div>
        <div class="col-sm-4"><div class="stat"><div class="stat-label">Saldo kas seharusnya</div><div class="stat-value text-primary"><?= $money($openShift['expected_cash']) ?></div></div></div>
      </div>
      <div class="row g-3">
        <div class="col-lg-6">
          <form method="post" action="<?= $e($base) ?>/operations/shifts/movement" class="operation-card p-3 h-100">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><h3 class="h6 fw-bold">Kas masuk / keluar</h3>
            <div class="row g-2">
              <div class="col-sm-4"><label class="form-label small" for="cashMovementType">Jenis</label><select class="form-select" id="cashMovementType" name="movement_type"><option value="cash_in">Kas masuk</option><option value="cash_out">Kas keluar</option></select></div>
              <div class="col-sm-4"><label class="form-label small" for="cashMovementAmount">Nominal (Rp)</label><input class="form-control" id="cashMovementAmount" name="amount" type="number" min="0.01" step="0.01" required></div>
              <div class="col-sm-4"><label class="form-label small" for="cashMovementReason">Alasan</label><input class="form-control" id="cashMovementReason" name="reason" minlength="3" maxlength="255" required></div>
              <div class="col-12 text-end"><button class="btn btn-outline-primary" type="submit">Catat pergerakan</button></div>
            </div>
          </form>
        </div>
        <?php $canCloseShift = $canManage || (int) $openShift['cashier_id'] === (int) $context['user_id']; ?>
        <div class="col-lg-6">
          <?php if ($canCloseShift): ?>
          <form method="post" action="<?= $e($base) ?>/operations/shifts/close" class="operation-card p-3 h-100">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><h3 class="h6 fw-bold">Hitung dan tutup shift</h3>
            <div class="row g-2 align-items-end">
              <div class="col-sm-5"><label class="form-label small" for="countedCash">Uang fisik aktual (Rp)</label><input class="form-control" id="countedCash" name="counted_cash" type="number" min="0" step="0.01" required></div>
              <div class="col-sm-4"><label class="form-label small" for="closingNote">Catatan selisih</label><input class="form-control" id="closingNote" name="note" maxlength="255"></div>
              <div class="col-sm-3"><button class="btn btn-danger w-100" type="submit" onclick="return confirm('Tutup shift sekarang? Tindakan ini tidak dapat dibatalkan.');">Tutup shift</button></div>
            </div>
            <div class="form-text mt-2">Selisih dihitung otomatis: uang aktual dikurangi saldo kas seharusnya.</div>
          </form>
          <?php else: ?>
          <div class="operation-card p-3 h-100 d-flex align-items-center"><p class="small text-muted mb-0">Shift bersama ini dibuka oleh <?= $e($openShift['cashier_name']) ?>. Hanya pembuka shift atau pemilik/manajer yang dapat menutupnya.</p></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($openShift['movements']): ?><div class="table-responsive mt-3"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Waktu</th><th>Jenis</th><th>Alasan</th><th class="text-end">Nominal</th></tr></thead><tbody>
        <?php foreach ($openShift['movements'] as $movement): ?><tr><td><?= $e(date('d M H:i', strtotime((string) $movement['created_at']))) ?></td><td><?= $movement['movement_type'] === 'cash_in' ? 'Kas masuk' : 'Kas keluar' ?></td><td><?= $e($movement['reason']) ?></td><td class="text-end"><?= $money($movement['amount']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    <?php endif; ?>
    <h3 class="h6 fw-bold mt-4">Riwayat shift cabang</h3>
    <?php if ($shifts): ?><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Pembuka shift</th><th>Dibuka</th><th>Ditutup</th><th>Status</th><th class="text-end">Seharusnya</th><th class="text-end">Aktual</th><th class="text-end">Selisih</th></tr></thead><tbody>
      <?php foreach ($shifts as $shift): ?><tr><td><?= $e($shift['cashier_name']) ?></td><td class="text-nowrap"><?= $e(date('d M H:i', strtotime((string) $shift['opened_at']))) ?></td><td class="text-nowrap"><?= $shift['closed_at'] ? $e(date('d M H:i', strtotime((string) $shift['closed_at']))) : '-' ?></td><td><span class="badge text-bg-<?= $shift['status'] === 'open' ? 'success' : 'secondary' ?>"><?= $shift['status'] === 'open' ? 'Terbuka' : 'Ditutup' ?></span></td><td class="text-end"><?= $shift['expected_cash'] === null ? '-' : $money($shift['expected_cash']) ?></td><td class="text-end"><?= $shift['counted_cash'] === null ? '-' : $money($shift['counted_cash']) ?></td><td class="text-end"><?= $shift['cash_difference'] === null ? '-' : $money($shift['cash_difference']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><div class="small text-muted">Belum ada riwayat shift.</div><?php endif; ?>
  </section>

  <?php if ($canManage): ?>
  <section id="purchases" class="surface p-3 p-lg-4 mb-3">
    <div class="mb-3"><h2 class="section-title mb-1">Pemasok &amp; pembelian</h2><p class="small text-muted mb-0">Buat pesanan pembelian lalu catat penerimaan untuk menambah stok dan memperbarui harga modal rata-rata.</p></div>
    <div class="row g-3 mb-4">
      <div class="col-xl-5"><form method="post" action="<?= $e($base) ?>/operations/suppliers" class="operation-card p-3 h-100">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><h3 class="h6 fw-bold">Tambah pemasok</h3>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small" for="supplierName">Nama pemasok</label><input class="form-control" id="supplierName" name="name" minlength="2" maxlength="150" required></div>
          <div class="col-md-6"><label class="form-label small" for="supplierPhone">Telepon</label><input class="form-control" id="supplierPhone" name="phone" maxlength="30"></div>
          <div class="col-md-6"><label class="form-label small" for="supplierEmail">Email</label><input class="form-control" id="supplierEmail" name="email" type="email" maxlength="190"></div>
          <div class="col-md-6"><label class="form-label small" for="supplierAddress">Alamat</label><input class="form-control" id="supplierAddress" name="address" maxlength="2000"></div>
          <div class="col-12"><label class="form-label small" for="supplierNotes">Catatan</label><input class="form-control" id="supplierNotes" name="notes" maxlength="255"></div>
          <div class="col-12 text-end"><button class="btn btn-outline-primary" type="submit">Simpan pemasok</button></div>
        </div>
      </form></div>
      <div class="col-xl-7"><form method="post" action="<?= $e($base) ?>/operations/purchases" class="operation-card p-3 h-100">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><h3 class="h6 fw-bold">Buat pesanan pembelian</h3>
        <?php if ($suppliers && $products): ?>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small" for="purchaseSupplier">Pemasok</label><select class="form-select" id="purchaseSupplier" name="supplier_id" required><option value="">Pilih pemasok</option><?php foreach ($suppliers as $supplier): ?><?php if ((int) $supplier['is_active'] === 1): ?><option value="<?= (int) $supplier['id'] ?>"><?= $e($supplier['name']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label small" for="purchaseProduct">Produk</label><select class="form-select" id="purchaseProduct" name="variant_id" required><option value="">Pilih produk</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['variant_id'] ?>" data-track-batch="<?= (int) $product['track_batch'] ?>"><?= $e($product['name']) ?><?= $product['sku'] ? ' · ' . $e($product['sku']) : '' ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label small" for="purchaseQty">Jumlah (unit produk)</label><input class="form-control" id="purchaseQty" name="quantity" type="number" min="0.001" step="0.001" required></div>
          <div class="col-md-4"><label class="form-label small" for="purchaseCost">Harga modal / unit (Rp)</label><input class="form-control" id="purchaseCost" name="unit_cost" type="number" min="0" step="0.01" required></div>
          <div class="col-md-4"><label class="form-label small" for="purchaseNotes">Catatan</label><input class="form-control" id="purchaseNotes" name="notes" maxlength="255"></div>
          <div class="col-md-6"><label class="form-label small" for="purchaseDueAt">Jatuh tempo tagihan (opsional)</label><input class="form-control" id="purchaseDueAt" name="due_at" type="date" min="<?= $e(date('Y-m-d')) ?>"><div class="form-text">Tagihan pemasok dibuat saat barang diterima.</div></div>
          <div id="purchaseBatchFields" class="col-12 d-none"><div class="row g-2">
            <div class="col-md-6"><label class="form-label small" for="purchaseBatch">Nomor batch obat</label><input class="form-control" id="purchaseBatch" name="batch_number" maxlength="60"></div>
            <div class="col-md-6"><label class="form-label small" for="purchaseExpiry">Tanggal kedaluwarsa</label><input class="form-control" id="purchaseExpiry" name="expired_at" type="date" min="<?= $e(date('Y-m-d')) ?>"></div>
          </div>
          </div>
          <div class="col-12 text-end"><button class="btn btn-primary" type="submit">Buat pesanan</button></div>
        </div>
        <?php else: ?><div class="alert alert-warning mb-0">Tambahkan pemasok dan produk stok aktif terlebih dahulu.</div><?php endif; ?>
      </form></div>
    </div>
    <h3 class="h6 fw-bold">Daftar pesanan pembelian</h3>
    <?php if ($orders): ?><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>Nomor</th><th>Pemasok</th><th>Dibuat</th><th>Item / Qty</th><th class="text-end">Total</th><th>Tempo</th><th>Status / tindakan</th></tr></thead><tbody>
      <?php foreach ($orders as $order): ?><tr><td class="fw-semibold"><?= $e($order['order_no']) ?></td><td><?= $e($order['supplier_name']) ?></td><td class="text-nowrap"><?= $e(date('d M Y H:i', strtotime((string) $order['ordered_at']))) ?></td><td><?= (int) $order['item_count'] ?> item · <?= $qty($order['qty_ordered']) ?></td><td class="text-end"><?= $money($order['total_amount']) ?></td><td><?= $order['due_at'] ? $e(date('d M Y', strtotime((string) $order['due_at']))) : '—' ?></td><td><div class="d-flex flex-wrap align-items-center gap-2"><span class="badge text-bg-<?= $order['status'] === 'received' ? 'success' : 'primary' ?>"><?= $order['status'] === 'received' ? 'Diterima' : 'Menunggu barang' ?></span><?php if ($order['status'] === 'ordered'): ?><form method="post" action="<?= $e($base) ?>/operations/purchases/receive" onsubmit="return confirm('Konfirmasi penerimaan semua barang dalam pesanan ini?');"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>"><button class="btn btn-sm btn-success" type="submit">Terima barang</button></form><?php elseif (!empty($order['purchase_item_id']) && (float) $order['qty_received'] > 0): ?><details><summary class="btn btn-sm btn-outline-danger">Retur pemasok</summary><form method="post" action="<?= $e($base) ?>/operations/purchase-returns" class="operation-card p-2 mt-2" onsubmit="return confirm('Catat retur barang ke pemasok? Stok akan dikurangi dan kredit pemasok ditambahkan.');"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="purchase_item_id" value="<?= (int) $order['purchase_item_id'] ?>"><label class="form-label small mb-1">Jumlah · <?= $e($order['product_names'] ?? 'Produk') ?></label><input class="form-control form-control-sm mb-2" name="quantity" type="number" min="0.001" max="<?= $e($order['qty_received']) ?>" step="0.001" required><input class="form-control form-control-sm mb-2" name="note" maxlength="255" placeholder="Alasan retur"><button class="btn btn-sm btn-danger" type="submit">Simpan retur</button></form></details><?php endif; ?></div></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><div class="small text-muted">Belum ada pesanan pembelian.</div><?php endif; ?>
    <section id="payables" class="operation-card p-3 mt-4">
      <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h3 class="h6 fw-bold mb-1">Utang dan pembayaran pemasok</h3><div class="small text-muted">Tagihan terbentuk saat penerimaan barang. Retur menambah kredit pemasok, termasuk bila tagihan terkait sudah lunas.</div></div><div class="text-end"><span class="small text-muted d-block">Kredit pemasok yang tersedia</span><strong><?= $money($supplierCredit ?? 0) ?></strong></div></div>
      <?php if ($payables): ?><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead class="table-light"><tr><th>Pesanan</th><th>Pemasok</th><th>Jatuh tempo</th><th class="text-end">Tagihan</th><th class="text-end">Dibayar</th><th class="text-end">Kredit retur</th><th class="text-end">Sisa / kredit</th><th>Tindakan</th></tr></thead><tbody>
        <?php foreach ($payables as $payable): $balance = (float) $payable['balance']; ?><tr><td><?= $e($payable['order_no']) ?></td><td><?= $e($payable['supplier_name']) ?></td><td><?= $payable['due_at'] ? $e(date('d M Y', strtotime((string) $payable['due_at']))) : '—' ?><?= (int) $payable['overdue'] === 1 ? ' · <span class="badge text-bg-danger">Terlambat</span>' : '' ?></td><td class="text-end"><?= $money($payable['billed_amount']) ?></td><td class="text-end"><?= $money($payable['paid_amount']) ?></td><td class="text-end"><?= $money($payable['credited_amount']) ?></td><td class="text-end fw-semibold <?= $balance < 0 ? 'text-success' : '' ?>"><?= $balance < 0 ? 'Kredit ' . $money(abs($balance)) : $money($balance) ?></td><td><?php if ($balance > 0): ?><details><summary class="btn btn-sm btn-outline-primary">Bayar</summary><form method="post" action="<?= $e($base) ?>/operations/supplier-payments" class="operation-card p-2 mt-2"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="payable_id" value="<?= (int) $payable['id'] ?>"><label class="form-label small mb-1">Nominal (saldo <?= $money($balance) ?>)</label><input class="form-control form-control-sm mb-2" name="amount" type="number" min="0.01" max="<?= $e(number_format($balance, 2, '.', '')) ?>" step="0.01" required><select class="form-select form-select-sm mb-2" name="payment_method" required><option value="transfer">Transfer</option><option value="cash"<?= empty($openShift) ? ' disabled' : '' ?>>Tunai<?= empty($openShift) ? ' (shift belum dibuka)' : '' ?></option><option value="qris">QRIS</option><option value="debit">Kartu debit</option><option value="credit">Kartu kredit</option><option value="other">Lainnya</option></select><input class="form-control form-control-sm mb-2" name="reference_no" maxlength="80" placeholder="Referensi pembayaran"><input class="form-control form-control-sm mb-2" name="note" maxlength="255" placeholder="Catatan"><button class="btn btn-sm btn-primary" type="submit">Catat pembayaran</button></form></details><?php else: ?><span class="small text-muted">Tidak ada tagihan terbuka</span><?php endif; ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php else: ?><div class="small text-muted">Tagihan baru akan muncul setelah pesanan pembelian diterima. Pembelian historis tidak otomatis dianggap sebagai utang.</div><?php endif; ?>
    </section>
    <div class="row g-3 mt-1">
      <div class="col-12 col-xl-6"><section class="operation-card p-3 h-100"><h3 class="h6 fw-bold">Riwayat pembayaran pemasok</h3><?php if ($supplierPayments): ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Waktu</th><th>Pemasok / pesanan</th><th>Metode</th><th class="text-end">Nominal</th><th>Pencatat</th></tr></thead><tbody><?php foreach ($supplierPayments as $payment): ?><tr><td class="text-nowrap"><?= $e(date('d M Y H:i', strtotime((string) $payment['paid_at']))) ?></td><td><?= $e($payment['supplier_name']) ?><small class="d-block text-muted"><?= $e($payment['order_no']) ?><?= $payment['reference_no'] ? ' · ' . $e($payment['reference_no']) : '' ?></small></td><td><?= $e($payment['payment_method']) ?></td><td class="text-end"><?= $money($payment['amount']) ?></td><td><?= $e($payment['paid_by_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="small text-muted">Belum ada pembayaran pemasok.</div><?php endif; ?></section></div>
      <div class="col-12 col-xl-6"><section class="operation-card p-3 h-100"><h3 class="h6 fw-bold">Riwayat retur pembelian</h3><?php if ($purchaseReturns): ?><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead class="table-light"><tr><th>Waktu</th><th>Pemasok / pesanan</th><th class="text-end">Qty</th><th class="text-end">Kredit</th><th>Pencatat</th></tr></thead><tbody><?php foreach ($purchaseReturns as $return): ?><tr><td class="text-nowrap"><?= $e(date('d M Y H:i', strtotime((string) $return['returned_at']))) ?></td><td><?= $e($return['supplier_name']) ?><small class="d-block text-muted"><?= $e($return['order_no']) ?><?= $return['note'] ? ' · ' . $e($return['note']) : '' ?></small></td><td class="text-end"><?= $qty($return['quantity']) ?></td><td class="text-end"><?= $money($return['total_amount']) ?></td><td><?= $e($return['returned_by_name']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="small text-muted">Belum ada retur pembelian.</div><?php endif; ?></section></div>
    </div>
  </section>

  <section id="counts" class="surface p-3 p-lg-4 mb-3">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3"><div><h2 class="section-title mb-1">Stok opname</h2><p class="small text-muted mb-0">Snapshot menyimpan stok pembanding. Perubahan setelah snapshot ikut diperhitungkan saat hasil fisik diposting.</p></div>
      <form method="post" action="<?= $e($base) ?>/operations/stock-counts" class="d-flex gap-2 align-items-end"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><div><label class="form-label small mb-1" for="countNotes">Catatan (opsional)</label><input class="form-control form-control-sm" id="countNotes" name="notes" maxlength="255" placeholder="Audit bulanan"></div><button class="btn btn-primary btn-sm" type="submit">Buat stok opname</button></form>
    </div>
    <?php if ($counts): ?><div class="d-grid gap-3">
      <?php foreach ($counts as $count): ?><article class="operation-card p-3">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><div><strong>Opname #<?= (int) $count['id'] ?></strong> · <?= $e(date('d M Y H:i', strtotime((string) $count['created_at']))) ?> · <?= (int) $count['item_count'] ?> baris<?php if ($count['notes']): ?><div class="small text-muted"><?= $e($count['notes']) ?></div><?php endif; ?><?php if ($count['status'] === 'posted'): ?><div class="small text-muted">Total selisih absolut: <?= $qty($count['total_variance'] ?? 0) ?> unit</div><?php endif; ?></div><span class="badge text-bg-<?= $count['status'] === 'open' ? 'warning' : 'success' ?>"><?= $count['status'] === 'open' ? 'Perlu dihitung' : 'Sudah diposting' ?></span></div>
        <?php if ($count['status'] === 'open'): $items = $countItems[(int) $count['id']] ?? []; ?>
          <form method="post" action="<?= $e($base) ?>/operations/stock-counts/post" onsubmit="return confirm('Posting hasil fisik dan menyesuaikan stok? Tindakan ini tidak dapat dibatalkan.');">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="count_id" value="<?= (int) $count['id'] ?>">
            <div class="table-responsive count-list"><table class="table table-sm align-middle mb-0"><thead class="table-light sticky-top"><tr><th>Produk / batch</th><th class="text-end">Snapshot</th><th style="min-width:150px">Hitung fisik</th></tr></thead><tbody>
              <?php foreach ($items as $item): ?><tr><td><?= $e($item['product_name']) ?><?= $item['variant_name'] !== 'Default' ? ' · ' . $e($item['variant_name']) : '' ?><?php if ($item['batch_number']): ?><span class="small text-muted d-block">Batch <?= $e($item['batch_number']) ?></span><?php endif; ?></td><td class="text-end"><?= $qty($item['expected_qty']) ?> <?= $e($item['unit']) ?></td><td><input class="form-control form-control-sm" name="counted[<?= (int) $item['id'] ?>]" type="number" min="0" max="999999999.999" step="0.001" required value="<?= $item['counted_qty'] === null ? '' : $e($item['counted_qty']) ?>"></td></tr><?php endforeach; ?>
            </tbody></table></div><div class="text-end mt-2"><button class="btn btn-sm btn-danger" type="submit">Simpan &amp; posting opname</button></div>
          </form>
        <?php endif; ?>
      </article><?php endforeach; ?>
    </div><?php else: ?><div class="small text-muted">Belum ada stok opname.</div><?php endif; ?>
  </section>

  <section id="transfers" class="surface p-3 p-lg-4 mb-3">
    <div class="mb-3"><h2 class="section-title mb-1">Transfer stok antar-cabang</h2><p class="small text-muted mb-0">Pengiriman langsung mengurangi stok asal. Stok tujuan bertambah setelah cabang tujuan mengonfirmasi penerimaan.</p></div>
    <?php if (count($branches) > 1 && $products): ?><form method="post" action="<?= $e($base) ?>/operations/transfers/ship" class="operation-card p-3 mb-4">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small" for="transferDestination">Cabang tujuan</label><select class="form-select" id="transferDestination" name="to_branch_id" required><option value="">Pilih cabang</option><?php foreach ($branches as $branch): ?><?php if ((int) $branch['id'] !== (int) $context['branch_id']): ?><option value="<?= (int) $branch['id'] ?>"><?= $e($branch['name']) ?></option><?php endif; ?><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small" for="transferProduct">Produk stok</label><select class="form-select" id="transferProduct" name="variant_id" required><option value="">Pilih produk</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['variant_id'] ?>" data-track-batch="<?= (int) $product['track_batch'] ?>"><?= $e($product['name']) ?><?= $product['sku'] ? ' · ' . $e($product['sku']) : '' ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small" for="transferQty">Jumlah</label><input class="form-control" id="transferQty" name="quantity" type="number" min="0.001" step="0.001" required></div>
        <div class="col-md-3"><label class="form-label small" for="transferNotes">Catatan</label><input class="form-control" id="transferNotes" name="notes" maxlength="255"></div>
        <div class="col-md-6 d-none" id="transferBatchField"><label class="form-label small" for="transferBatch">Batch obat yang dikirim</label><select class="form-select" id="transferBatch" name="batch_id"><option value="">Pilih batch</option><?php foreach ($batches as $batch): ?><option value="<?= (int) $batch['id'] ?>" data-variant="<?= (int) $batch['variant_id'] ?>" data-qty="<?= $e($batch['qty_remaining']) ?>">Batch <?= $e($batch['batch_number']) ?> · exp <?= $e($batch['expired_at']) ?> · sisa <?= $qty($batch['qty_remaining']) ?></option><?php endforeach; ?></select></div>
        <div class="col-12 text-end"><button class="btn btn-primary" type="submit">Kirim transfer</button></div>
      </div>
    </form><?php else: ?><div class="alert alert-info">Transfer memerlukan minimal dua cabang aktif dan produk yang melacak stok.</div><?php endif; ?>
    <?php if ($transfers): ?><div class="table-responsive"><table class="table table-hover align-middle mb-0"><thead class="table-light"><tr><th>ID</th><th>Asal</th><th>Tujuan</th><th>Dikirim</th><th>Item / qty</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach ($transfers as $transfer): ?><tr><td>#<?= (int) $transfer['id'] ?></td><td><?= $e($transfer['from_name']) ?></td><td><?= $e($transfer['to_name']) ?></td><td><?= $e(date('d M Y H:i', strtotime((string) $transfer['shipped_at']))) ?></td><td><?= (int) $transfer['item_count'] ?> item · <?= $qty($transfer['quantity']) ?></td><td><span class="badge text-bg-<?= $transfer['status'] === 'received' ? 'success' : 'warning' ?>"><?= $transfer['status'] === 'received' ? 'Diterima' : 'Dalam perjalanan' ?></span></td><td><?php if ($transfer['status'] === 'in_transit' && (int) $transfer['to_branch_id'] === (int) $context['branch_id']): ?><form method="post" action="<?= $e($base) ?>/operations/transfers/receive" onsubmit="return confirm('Konfirmasi barang transfer sudah diterima?');"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="transfer_id" value="<?= (int) $transfer['id'] ?>"><button class="btn btn-sm btn-success" type="submit">Konfirmasi terima</button></form><?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><div class="small text-muted">Belum ada transfer stok.</div><?php endif; ?>
  </section>
  <?php endif; ?>
</main>
<footer class="footer mt-4"><div class="container-fluid page-wrap d-flex flex-wrap justify-content-between gap-2 py-3 small"><span><?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></span><span>Setiap transaksi kas dan perubahan stok tercatat untuk audit.</span></div></footer>
</div>
</div>
<script>
(function () {
  'use strict';
  const purchaseProduct = document.getElementById('purchaseProduct');
  const purchaseBatchFields = document.getElementById('purchaseBatchFields');
  function updatePurchaseBatch() {
    if (!purchaseProduct || !purchaseBatchFields) return;
    const selected = purchaseProduct.options[purchaseProduct.selectedIndex];
    const required = selected && selected.dataset.trackBatch === '1';
    purchaseBatchFields.classList.toggle('d-none', !required);
    purchaseBatchFields.querySelectorAll('input').forEach(function (input) {
      input.required = !!required;
      input.disabled = !required;
    });
  }
  if (purchaseProduct) {
    purchaseProduct.addEventListener('change', updatePurchaseBatch);
    updatePurchaseBatch();
  }
  const transferProduct = document.getElementById('transferProduct');
  const transferBatchField = document.getElementById('transferBatchField');
  const transferBatch = document.getElementById('transferBatch');
  function updateTransferBatch() {
    if (!transferProduct || !transferBatchField || !transferBatch) return;
    const selected = transferProduct.options[transferProduct.selectedIndex];
    const required = selected && selected.dataset.trackBatch === '1';
    transferBatchField.classList.toggle('d-none', !required);
    transferBatch.required = !!required;
    transferBatch.disabled = !required;
    Array.from(transferBatch.options).forEach(function (option) {
      if (!option.value) return;
      option.hidden = option.dataset.variant !== transferProduct.value;
      if (option.hidden && option.selected) transferBatch.value = '';
    });
  }
  if (transferProduct) {
    transferProduct.addEventListener('change', updateTransferBatch);
    updateTransferBatch();
  }
  const counted = document.getElementById('countedCash');
  const expected = <?= json_encode($openShift['expected_cash'] ?? null, JSON_THROW_ON_ERROR) ?>;
  if (counted && expected !== null) counted.value = Number(expected).toFixed(2);
})();
</script>
</body>
</html>
