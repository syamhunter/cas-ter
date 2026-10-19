<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sidebarBase = $base;
$sidebarBusiness = $context['tenant_name'];
$sidebarBranch = $context['branch_name'];
$sidebarRole = $context['role'];
$sidebarUser = \App\Core\Auth::name();
$sidebarPage = 'reports';
$sidebarActive = 'reports';
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Laporan - <?= $e($context['tenant_name']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root{--ink:#17243b;--muted:#738096;--blue:#3157d5}
    body{min-height:100vh;margin:0;background:#f3f5fa;color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
    .app-shell{min-height:100vh;display:grid;grid-template-columns:250px minmax(0,1fr)}.app-content{min-width:0;display:flex;flex-direction:column}.app-content>main{flex:1}
    .app-sidebar{position:sticky;top:0;height:100vh;padding:20px 14px;display:flex;flex-direction:column;gap:8px;overflow:auto;background:#111d36;color:#e8eeff;z-index:1040}
    .app-sidebar-brand{display:flex;align-items:center;gap:11px;padding:3px 5px 18px;color:inherit;text-decoration:none;min-width:0}.app-sidebar-mark{flex:0 0 40px;width:40px;height:40px;display:grid;place-items:center;border-radius:13px;background:#4269e8;color:#fff;font-weight:800}
    .app-sidebar-brand-copy{min-width:0;display:grid}.app-sidebar-brand-copy strong,.app-sidebar-brand-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.app-sidebar-brand-copy small{color:#aab6d1}
    .app-sidebar-caption{padding:8px 10px 4px;color:#8493b2;font-size:.68rem;font-weight:750;letter-spacing:.1em}.app-sidebar-nav{display:grid;gap:5px}
    .app-sidebar-link{display:flex;align-items:center;gap:11px;padding:10px 11px;border-radius:10px;text-decoration:none;color:#c7d1e7;font-size:.9rem;font-weight:600}
    .app-sidebar-link:hover{background:#ffffff12;color:#fff}.app-sidebar-link.active{background:#3157d5;color:#fff}.app-sidebar-icon{flex:0 0 25px;width:25px;height:25px;display:grid;place-items:center;border:1px solid #ffffff20;border-radius:8px}
    .app-sidebar-icon svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
    .app-sidebar-footer{display:grid;gap:3px;margin-top:auto;padding:13px 10px 3px;border-top:1px solid #ffffff1c;color:#aab6d1;font-size:.75rem}.app-sidebar-user{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff;font-weight:650}
    .page-wrap{max-width:1500px}.surface{background:#fff;border:1px solid #e5e9f2;border-radius:16px}.muted{color:var(--muted)}
    .metric{height:100%;padding:17px;border:1px solid #e5e9f2;border-radius:14px;background:#fff}.metric span{display:block;color:var(--muted);font-size:.82rem}.metric strong{display:block;margin-top:8px;font-size:1.35rem;overflow-wrap:anywhere}
    .table thead th{white-space:nowrap;color:#64728a;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em}.footer{border-top:1px solid #e5e9f2;background:#fff;color:var(--muted)}
    @media(max-width:991.98px){.app-shell{display:flex;flex-direction:column}.app-sidebar{position:sticky;top:0;width:100%;height:auto;padding:8px 12px;overflow:hidden}.app-sidebar-brand,.app-sidebar-caption,.app-sidebar-footer{display:none}.app-sidebar-nav{display:flex;gap:6px;overflow-x:auto}.app-sidebar-link{flex:0 0 auto;padding:8px 11px;font-size:.82rem}.app-sidebar-icon{display:none}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php require dirname(__DIR__) . '/partials/sidebar.php'; ?>
  <div class="app-content">
    <main class="container-fluid page-wrap py-4 px-3 px-lg-4">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><div class="text-primary text-uppercase fw-bold small">Analisis usaha</div><h1 class="h3 fw-bold mb-1">Laporan penjualan</h1>
          <div class="muted"><?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></div></div>
      </div>
      <section class="surface p-3 p-lg-4 mb-4">
        <form id="filters" class="row g-3 align-items-end">
          <div class="col-6 col-lg-4"><label class="form-label" for="from">Dari tanggal</label><input class="form-control" type="date" id="from" value="<?= $e(substr($today,0,8).'01') ?>" required></div>
          <div class="col-6 col-lg-4"><label class="form-label" for="to">Sampai tanggal</label><input class="form-control" type="date" id="to" value="<?= $e($today) ?>" required></div>
          <div class="col-12 col-lg-4 d-flex flex-wrap gap-2">
            <button class="btn btn-dark flex-grow-1" type="submit">Tampilkan laporan</button>
            <a id="export" class="btn btn-outline-primary" href="#">CSV transaksi</a>
            <button id="printReport" class="btn btn-outline-secondary" type="button" disabled>PDF laporan</button>
            <?php if (in_array($context['role'], ['owner', 'manager'], true)): ?>
              <a id="purchaseExport" class="btn btn-outline-primary" href="#">CSV pembelian</a>
              <button id="printPurchases" class="btn btn-outline-secondary" type="button" disabled>PDF pembelian</button>
            <?php endif; ?>
          </div>
          <div class="col-12"><div class="d-flex flex-wrap align-items-center gap-2"><span class="small muted me-1">Periode cepat:</span>
            <button class="btn btn-sm btn-outline-secondary range-preset" data-range="7" type="button">7 hari</button>
            <button class="btn btn-sm btn-outline-secondary range-preset" data-range="month" type="button">Bulan ini</button>
            <button class="btn btn-sm btn-outline-secondary range-preset" data-range="previous-month" type="button">Bulan lalu</button>
            <button class="btn btn-sm btn-outline-secondary range-preset" data-range="year" type="button">Tahun ini</button>
          </div></div>
        </form>
      </section>
      <div id="message" class="alert d-none" role="status"></div>
      <div id="loading" class="surface p-5 text-center muted">Memuat laporan…</div>
      <div id="report" class="d-none">
        <section id="metrics" class="row g-3 mb-4"></section>
        <?php if ($context['role'] === 'owner'): ?>
        <section class="surface p-3 p-lg-4 mb-4">
          <h2 class="h5 fw-bold mb-1">Ringkasan seluruh cabang</h2>
          <div class="small muted mb-3">Penjualan, retur, pembelian, dan pengeluaran mengikuti periode pilihan. Utang pemasok adalah saldo terbuka saat ini.</div>
          <div id="branchSummary"></div>
        </section>
        <section class="surface p-3 p-lg-4 mb-4">
          <h2 class="h5 fw-bold mb-1">Audit aktivitas penting</h2>
          <div class="small muted mb-3">Aktivitas keuangan, shift, pembelian, retur, dan pelunasan pada periode pilihan (maksimal 200 catatan terbaru).</div>
          <div id="auditActivity"></div>
        </section>
        <?php endif; ?>
        <div class="row g-4 mb-4">
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><h2 class="h5 fw-bold mb-1">Rincian penjualan & pajak</h2><div class="small muted">Nilai tercatat pada transaksi di periode pilihan.</div></div><span class="badge text-bg-primary">Penjualan</span></div>
            <div id="salesBreakdown"></div>
            <h3 class="h6 fw-bold mt-4">Rincian pajak per hari</h3><div id="taxDaily"></div>
            <div id="receivablePanel">
              <h3 class="h6 fw-bold mt-4">Piutang pelanggan &amp; umur tagihan</h3>
              <div id="receivableSummary"></div><div id="receivableAging" class="mt-2"></div>
            </div>
            <div class="alert alert-warning small mb-0 mt-3">Penjualan dihitung berdasarkan tanggal transaksi, sedangkan refund dihitung berdasarkan tanggal retur. Penjualan bersih dan laba kotor adalah estimasi nilai barang setelah diskon, tanpa pajak dan biaya layanan. Pajak bukan laporan pajak resmi; verifikasi dengan pembukuan Anda.</div>
          </section></div>
          <div id="purchaseSummaryPanel" class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100">
            <div class="d-flex justify-content-between align-items-start gap-3 mb-3"><div><h2 class="h5 fw-bold mb-1">Pembelian & penerimaan barang</h2><div class="small muted">Pembelian berdasarkan tanggal penerimaan; pesanan berdasarkan tanggal dibuat.</div></div><span class="badge text-bg-secondary">Persediaan</span></div>
            <div id="purchaseSummary"></div>
            <h3 class="h6 fw-bold mt-4">Utang pemasok &amp; umur tagihan</h3><div id="supplierAging"></div>
            <h3 class="h6 fw-bold mt-4">Tren penerimaan harian</h3><div id="purchasesDaily"></div>
            <div class="alert alert-info small mb-0 mt-3">Utang dihitung dari penerimaan pembelian setelah fitur ini aktif. Nilai pembelian bukan otomatis pengeluaran kas atau beban laba rugi; laporan pajak masukan resmi belum tersedia.</div>
          </section></div>
        </div>
        <div class="row g-4">
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Penjualan harian</h2><div id="daily"></div></section></div>
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Metode pembayaran</h2><div id="payments"></div></section></div>
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Performa kasir</h2><div id="cashiers"></div></section></div>
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Jenis pesanan</h2><div id="orders"></div></section></div>
          <div id="expenseReport" class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Pengeluaran per kategori</h2><div id="expenses"></div></section></div>
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Aktivitas penjualan per jam</h2><div id="hours"></div></section></div>
          <div id="purchaseBySupplierPanel" class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Pembelian per pemasok (barang diterima)</h2><div id="purchaseSuppliers"></div></section></div>
          <div id="purchaseByProductPanel" class="col-12"><section class="surface p-3 p-lg-4"><h2 class="h5 fw-bold">Pembelian per produk / varian</h2><div id="purchaseProducts"></div></section></div>
          <div id="inventoryPanel" class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Posisi persediaan saat ini</h2><div id="inventoryValue"></div><div class="small muted mt-2">Nilai perkiraan menggunakan biaya modal varian saat ini, bukan nilai historis akhir periode.</div></section></div>
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Pergerakan persediaan</h2><div id="stockMovements"></div></section></div>
          <div id="shiftPanel" class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Rekonsiliasi shift kas</h2><div id="shifts"></div><div class="small muted mt-2">Akumulasi shift yang dibuka pada periode laporan; shift yang masih terbuka belum memiliki hasil hitung kas.</div></section></div>
          <div class="col-12"><section class="surface p-3 p-lg-4"><h2 class="h5 fw-bold">Penjualan produk &amp; laba kotor</h2><div id="products"></div></section></div>
          <div id="purchaseOrdersPanel" class="col-12"><section class="surface p-3 p-lg-4"><h2 class="h5 fw-bold">Riwayat pesanan pembelian</h2><div id="purchaseOrders"></div><div class="small muted mt-2">Maksimal 200 pesanan terbaru yang dibuat pada periode pilihan.</div></section></div>
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Pengembalian dana</h2><div id="returns"></div></section></div>
          <div class="col-12 col-xl-6"><section class="surface p-3 p-lg-4 h-100"><h2 class="h5 fw-bold">Stok menipis</h2><div id="stock"></div></section></div>
        </div>
      </div>
    </main>
    <footer class="footer py-3 px-4 small">© <?= date('Y') ?> <?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></footer>
  </div>
</div>
<script>
(() => {
  const base = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
  const canViewProfit = <?= in_array($context['role'], ['owner', 'manager'], true) ? 'true' : 'false' ?>;
  const canViewAllBranches = <?= $context['role'] === 'owner' ? 'true' : 'false' ?>;
  const money = value => new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(value)||0);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const message = document.getElementById('message');
  let reportData = null;
  const paymentLabels={cash:'Tunai',qris:'QRIS',transfer:'Transfer',debit:'Kartu debit',credit:'Kartu kredit',other:'Lainnya'};
  const orderLabels={retail:'Ritel',dine_in:'Makan di tempat',takeaway:'Bawa pulang',pharmacy:'Apotek',laundry:'Laundry',printing:'Percetakan',service:'Jasa'};
  const itemLabels={goods:'Barang',menu:'Menu',medicine:'Obat',laundry:'Laundry',print:'Percetakan',service:'Jasa'};
  const movementLabels={sale:'Penjualan',purchase:'Pembelian',adjustment:'Penyesuaian',transfer_in:'Transfer masuk',transfer_out:'Transfer keluar',return:'Retur',void:'Pembatalan'};
  const purchaseStatus={ordered:'Menunggu barang',received:'Diterima',cancelled:'Dibatalkan'};
  const auditLabels={'sale.checked_out':'Checkout penjualan','sale.returned':'Retur penjualan','receivable.payment_received':'Pelunasan piutang',
    'expense.created':'Pengeluaran dicatat','shift.opened':'Shift dibuka','shift.closed':'Shift ditutup','shift.cash_movement':'Pergerakan kas',
    'purchase_order.created':'Pesanan pembelian dibuat','purchase_order.received':'Pembelian diterima',
    'supplier_payment.created':'Pembayaran pemasok','purchase_return.created':'Retur pembelian'};
  const auditName=code=>auditLabels[code]||code;
  function table(headers, rows) {
    return rows.length ? `<div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.join('')}</tbody></table></div>` : '<div class="py-3 muted">Tidak ada data pada periode ini.</div>';
  }
  function printTable(headers, rows) {
    return `<table><thead><tr>${headers.map(value=>`<th>${esc(value)}</th>`).join('')}</tr></thead><tbody>${rows.length?rows.map(row=>`<tr>${row.map(value=>`<td>${esc(value)}</td>`).join('')}</tr>`).join(''):`<tr><td colspan="${headers.length}" class="empty">Tidak ada data pada periode ini.</td></tr>`}</tbody></table>`;
  }
  function printSection(title, headers, rows) {
    return `<section><h2>${esc(title)}</h2>${printTable(headers,rows)}</section>`;
  }
  function printDocument(title, sections, note='') {
    if (!reportData) { message.className='alert alert-warning'; message.textContent='Muat laporan terlebih dahulu sebelum membuat PDF.'; return; }
    const popup=window.open('','_blank');
    if (!popup) { message.className='alert alert-danger'; message.textContent='Jendela cetak diblokir browser. Izinkan pop-up untuk aplikasi ini, lalu coba lagi.'; return; }
    const brand=<?= json_encode($context['tenant_name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const branch=<?= json_encode($context['branch_name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const from=esc(reportData.from), to=esc(reportData.to);
    popup.document.open();
    popup.document.write(`<!doctype html><html lang="id"><head><meta charset="utf-8"><title>${esc(title)} - ${from} - ${to}</title><style>
      @page{size:A4 landscape;margin:13mm}*{box-sizing:border-box}body{font:10px/1.4 Arial,sans-serif;color:#17243b;margin:0}
      header{border-bottom:2px solid #263b68;padding-bottom:10px;margin-bottom:16px}h1{font-size:20px;margin:0 0 4px}
      .meta{color:#5c687a;font-size:11px}.note{margin-top:10px;padding:8px;background:#f1f4f9;color:#46546b}
      section{margin:0 0 18px;break-inside:avoid}h2{font-size:13px;margin:0 0 7px}
      table{width:100%;border-collapse:collapse;font-size:9px}th,td{padding:5px 6px;border:1px solid #cfd6e2;text-align:left;vertical-align:top}
      th{background:#edf1f7;font-weight:700}.empty{text-align:center;color:#68758a;padding:12px}
      footer{margin-top:18px;border-top:1px solid #ccd3df;padding-top:6px;color:#68758a;font-size:8px}
      @media screen{body{padding:18px}.toolbar{position:sticky;top:0;background:white;padding:8px 0 12px}button{border:0;border-radius:5px;padding:8px 14px;background:#3157d5;color:white;font-weight:bold;cursor:pointer}}
      @media print{.toolbar{display:none}section{break-inside:auto}thead{display:table-header-group}tr{break-inside:avoid}}
    </style></head><body><div class="toolbar"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
      <header><h1>${esc(title)}</h1><div><strong>${esc(brand)}</strong> · ${esc(branch)}</div><div class="meta">Periode laporan: ${from} s.d. ${to} · Dicetak: ${esc(new Date().toLocaleString('id-ID'))}</div></header>
      ${sections.join('')}${note?`<div class="note">${esc(note)}</div>`:''}<footer>Dokumen dibuat dari data transaksi yang tercatat di aplikasi. Gunakan fungsi cetak browser untuk menyimpan atau mencetak PDF.</footer>
      <script>window.addEventListener('load',function(){setTimeout(function(){window.print()},300)});<\/script></body></html>`);
    popup.document.close();
  }
  function printSalesReport() {
    if (!reportData) { printDocument('Laporan penjualan', []); return; }
    const d=reportData,s=d.summary, sections=[];
    sections.push(printSection('Ringkasan penjualan', ['Indikator','Nilai'],[
      ['Jumlah transaksi',s.transactions],['Subtotal item',money(s.subtotal)],['Diskon',money(s.discounts)],
      ['Biaya layanan',money(s.service_charge)],['Pajak tercatat',money(s.tax)],
      ['Total transaksi termasuk pajak/layanan',money(s.gross_sales)],['Refund tercatat',money(s.refunds)],
      ['Penjualan item bersih (tanpa pajak/biaya layanan)',money(s.net_revenue)],['Total setelah refund termasuk pajak/layanan',money(s.net_sales)],
      ['Penjualan item bersih periode sebelumnya',money(s.previous_period?.net_revenue)],
      ...(canViewProfit?[['Laba kotor estimasi setelah retur',money(s.gross_profit)],['Pengeluaran operasional',money(s.expenses)],['Laba bersih estimasi',money(s.net_profit)]]:[])
    ]));
    if(canViewProfit) sections.push(printSection('Umur piutang pelanggan',['Pelanggan','Belum jatuh tempo','1–30 hari','31–60 hari','61–90 hari','>90 hari','Total'],
      (d.receivable_aging||[]).map(x=>[x.customer_name,money(x.not_due),money(x.days_1_30),money(x.days_31_60),money(x.days_61_90),money(x.days_over_90),money(x.outstanding)])));
    if(canViewAllBranches) sections.push(printSection('Ringkasan seluruh cabang',['Cabang','Penjualan kotor','Retur','Penjualan bersih','Pembelian diterima','Pengeluaran','Utang pemasok','Piutang pelanggan'],
      (d.branch_summary||[]).map(x=>[x.branch_name,money(x.gross_sales),money(x.refunds),money(x.net_sales),money(x.received_purchases),money(x.expenses),money(x.supplier_payables),money(x.customer_receivables)])));
    if(canViewAllBranches) sections.push(printSection('Audit aktivitas penting',['Waktu','Cabang','Pengguna','Aktivitas','Entitas','Rincian'],
      (d.audit_activity||[]).map(x=>[x.created_at,x.branch_name,x.user_name,auditName(x.action_code),x.entity_type+' #'+x.entity_id,
        Object.entries(x.details||{}).map(([key,value])=>key+': '+value).join(' · ')])));
    sections.push(printSection('Pajak tercatat per hari',['Tanggal','Transaksi','Subtotal','Diskon','Biaya layanan','Pajak','Total termasuk pajak'],
      d.tax_by_day.map(x=>[x.day,x.transactions,money(x.subtotal),money(x.discounts),money(x.service_charge),money(x.tax),money(x.gross_sales)])));
    sections.push(printSection('Penjualan harian',['Tanggal','Transaksi','Total termasuk pajak','Refund','Total setelah refund'],
      d.daily.map(x=>[x.day,x.transactions,money(x.gross_sales),money(x.refunds),money(x.net_sales)])));
    sections.push(printSection('Pembayaran per metode',['Metode','Nominal','Jumlah pembayaran'],
      d.payments.map(x=>[paymentLabels[x.method]||x.method,money(x.amount),x.payments])));
    sections.push(printSection('Kinerja kasir',['Kasir','Transaksi','Penjualan','Rata-rata transaksi'],
      d.by_cashier.map(x=>[x.cashier_name,x.transactions,money(x.sales),money(x.average_ticket)])));
    sections.push(printSection('Penjualan per jenis pesanan',['Jenis pesanan','Transaksi','Penjualan','Rata-rata'],
      d.by_order_type.map(x=>[orderLabels[x.order_type]||x.order_type,x.transactions,money(x.sales),money(x.average_ticket)])));
    sections.push(printSection('Penjualan produk & laba kotor',['Produk','Kategori','Qty terjual','Qty retur','Penjualan item bersih','HPP setelah retur','Laba kotor'],
      d.top_products.map(x=>[x.item_name,itemLabels[x.item_type]||x.item_type,x.quantity_sold,x.quantity_returned,money(x.sales),money(x.cost),money(x.gross_profit)])));
    sections.push(printSection('Pengeluaran per kategori',['Kategori','Jumlah catatan','Nominal'],
      canViewProfit?d.expenses_by_category.map(x=>[x.category,x.entries,money(x.amount)]):[]));
    sections.push(printSection('Riwayat retur',['Waktu','Invoice','Diproses oleh','Metode','Nominal','Catatan'],
      d.returns.map(x=>[x.returned_at,x.invoice_no,x.cashier_name,paymentLabels[x.refund_method]||x.refund_method,money(x.refund_amount),x.note||'—'])));
    sections.push(printSection('Pergerakan persediaan',['Jenis pergerakan','Jumlah catatan','Perubahan qty'],
      d.stock_movements.map(x=>[movementLabels[x.movement_type]||x.movement_type,x.movements,x.quantity_change])));
    sections.push(printSection('Stok minimum',['Produk','Varian','SKU','Stok','Batas minimum'],
      d.low_stock.map(x=>[x.product_name,x.variant_name,x.sku||'—',`${x.stock_qty} ${x.unit}`,`${x.min_stock} ${x.unit}`])));
    if(canViewProfit) sections.push(printSection('Posisi persediaan saat ini (estimasi)',['Indikator','Nilai'],[
      ['Jumlah stok (gabungan unit)',d.stock_value.inventory_units],['Nilai modal persediaan',money(d.stock_value.inventory_cost_value)],
      ['Varian di bawah batas minimum',d.stock_value.low_stock_variants]
    ]));
    const note='Penjualan dicatat pada tanggal transaksi dan refund pada tanggal retur. Laba kotor mengurangi diskon satu kali, tidak memasukkan pajak/biaya layanan, dan memperhitungkan HPP barang terlacak yang dikembalikan ke stok. Pajak bukan laporan resmi; laba dan nilai persediaan merupakan estimasi.';
    printDocument('Laporan penjualan dan operasional',sections,note);
  }
  function printPurchaseReport() {
    if (!canViewProfit) return;
    if (!reportData) { printDocument('Laporan pembelian', []); return; }
    const d=reportData,sections=[];
    sections.push(printSection('Ringkasan pembelian',['Ukuran','Jumlah'],[
      ['Barang diterima dalam periode',`${d.purchase_summary.received_in_period_orders} pesanan · ${money(d.purchase_summary.received_in_period_total)}`],
      ['Pesanan terbuka dibuat dalam periode',`${d.purchase_summary.pending_orders} pesanan · ${money(d.purchase_summary.pending_total)}`],
      ['Pesanan dibatalkan dibuat dalam periode',d.purchase_summary.cancelled_orders]
    ]));
    const ap=d.supplier_payable_summary||{}, sr=d.supplier_returns_period||{};
    sections.push(printSection('Utang pemasok',['Indikator','Nominal'],[
      ['Total utang terbuka',money(ap.outstanding)],
      ['Lewat jatuh tempo',money(ap.overdue)],
      ['Kredit pemasok tersedia',money(ap.available_credit)],
      ['Pembayaran pemasok periode ini',money((d.supplier_payments_period||[]).reduce((sum,x)=>sum+Number(x.amount||0),0))],
      ['Nilai retur pembelian periode ini',money(sr.return_value)]
    ]));
    sections.push(printSection('Umur utang pemasok',['Pemasok','Belum jatuh tempo','1–30 hari','31–60 hari','61–90 hari','>90 hari','Total'],
      (d.supplier_aging||[]).map(x=>[x.supplier_name,money(x.not_due),money(x.days_1_30),money(x.days_31_60),money(x.days_61_90),money(x.days_over_90),money(x.outstanding)])));
    sections.push(printSection('Penerimaan harian',['Tanggal diterima','Pesanan','Nilai diterima'],
      d.purchases_by_day.map(x=>[x.day,x.orders_count,money(x.received_total)])));
    sections.push(printSection('Pembelian per pemasok',['Pemasok','Pesanan diterima','Nilai diterima'],
      d.purchases_by_supplier.map(x=>[x.supplier_name,x.orders_count,money(x.received_total)])));
    sections.push(printSection('Pembelian per produk / varian',['Produk','Varian','SKU','Qty diterima','Biaya diterima'],
      d.purchases_by_product.map(x=>[x.product_name,x.variant_name,x.sku||'—',x.qty_received,money(x.received_cost)])));
    sections.push(printSection('Daftar pesanan pembelian',['No. pesanan','Pemasok','Status','Tanggal pesan','Tanggal terima','Nilai','Catatan'],
      d.purchases.map(x=>[x.order_no,x.supplier_name,purchaseStatus[x.status]||x.status,x.ordered_at,x.received_at||'—',money(x.total_amount),x.notes||'—'])));
    printDocument('Laporan pembelian dan utang pemasok',sections,'Saldo utang hanya mencakup pesanan yang diterima setelah fitur utang diaktifkan. Verifikasi saldo pembuka dengan pemasok.');
  }
  async function load() {
    document.getElementById('loading').classList.remove('d-none'); document.getElementById('report').classList.add('d-none'); message.className='alert d-none';
    reportData=null;
    document.getElementById('printReport').disabled=true;
    const purchasePrint=document.getElementById('printPurchases');
    if(purchasePrint) purchasePrint.disabled=true;
    const from=document.getElementById('from').value, to=document.getElementById('to').value;
    const params=new URLSearchParams({from,to});
    document.getElementById('export').href=`${base}/api/pos-report?${params}&format=csv`;
    const purchaseExport=document.getElementById('purchaseExport');
    if(purchaseExport) purchaseExport.href=`${base}/api/pos-report?${params}&format=purchase_csv`;
    try {
      const response=await fetch(`${base}/api/pos-report?${params}`,{credentials:'same-origin'});
      const json=await response.json(); if(!response.ok||!json.success) throw new Error(json.message||'Laporan gagal dimuat.');
      const data=json.data, s=data.summary;
      reportData=data;
      document.getElementById('printReport').disabled=false;
      if(purchasePrint) purchasePrint.disabled=false;
      const prior=Number(s.previous_period?.net_revenue)||0, current=Number(s.net_revenue)||0;
      const change=prior===0?(current===0?'—':'Baru'):((current-prior)/Math.abs(prior)*100).toFixed(1)+'%';
      const metrics=[['Total transaksi termasuk pajak',s.gross_sales],['Refund tercatat',s.refunds],['Penjualan item bersih',s.net_revenue],['Perubahan penjualan item vs periode sebelumnya',change]];
      if(canViewProfit) metrics.push(['Laba kotor (estimasi setelah retur)',s.gross_profit],['Pengeluaran',s.expenses],['Laba bersih (estimasi)',s.net_profit]);
      metrics.push(['Transaksi',s.transactions],['Rata-rata transaksi',Number(s.transactions)?Number(s.gross_sales)/Number(s.transactions):0]);
      document.getElementById('metrics').innerHTML=metrics.map(([label,value])=>`<div class="col-6 col-md-4 col-xl-3"><div class="metric"><span>${esc(label)}</span><strong>${label==='Transaksi'?Number(value)||0:(label.startsWith('Perubahan')?esc(value):money(value))}</strong>${label.startsWith('Perubahan')?`<small class="muted">Periode pembanding: ${money(s.previous_period?.net_revenue)}</small>`:''}</div></div>`).join('');
      document.getElementById('salesBreakdown').innerHTML=table(['Komponen','Nominal'],[
        `<tr><td>Subtotal item</td><td class="text-end">${money(s.subtotal)}</td></tr>`,
        `<tr><td>Diskon</td><td class="text-end">− ${money(s.discounts)}</td></tr>`,
        `<tr><td>Biaya layanan</td><td class="text-end">${money(s.service_charge)}</td></tr>`,
        `<tr><td>Pajak tercatat</td><td class="text-end">${money(s.tax)}</td></tr>`,
        `<tr><td>Total transaksi (termasuk pajak/layanan)</td><td class="text-end fw-semibold">${money(s.gross_sales)}</td></tr>`,
        `<tr><td>Refund tercatat</td><td class="text-end">− ${money(s.refunds)}</td></tr>`,
        `<tr><td>Penjualan item bersih (tanpa pajak/biaya layanan)</td><td class="text-end fw-bold">${money(s.net_revenue)}</td></tr>`,
        `<tr><td>Total transaksi setelah refund (termasuk pajak/layanan)</td><td class="text-end">${money(s.net_sales)}</td></tr>`
      ]);
      document.getElementById('taxDaily').innerHTML=table(['Tanggal','Transaksi','Subtotal','Diskon','Biaya layanan','Pajak tercatat'],data.tax_by_day.map(x=>`<tr><td>${esc(x.day)}</td><td>${esc(x.transactions)}</td><td>${money(x.subtotal)}</td><td>${money(x.discounts)}</td><td>${money(x.service_charge)}</td><td class="fw-semibold">${money(x.tax)}</td></tr>`));
      document.getElementById('receivableSummary').innerHTML=table(['Indikator','Nilai'],[
        `<tr><td>Piutang terbuka saat ini</td><td class="text-end fw-semibold">${money(data.receivable_summary?.outstanding||0)}</td></tr>`,
        `<tr><td>Lewat jatuh tempo</td><td class="text-end text-danger">${money(data.receivable_summary?.overdue||0)}</td></tr>`,
        `<tr><td>Transaksi belum lunas</td><td class="text-end">${esc(data.receivable_summary?.invoices||0)}</td></tr>`
      ]);
      document.getElementById('receivableAging').innerHTML=table(['Pelanggan','Belum jatuh tempo','1–30 hari','31–60 hari','61–90 hari','>90 hari','Total'],(data.receivable_aging||[]).map(x=>`<tr><td>${esc(x.customer_name)}</td><td>${money(x.not_due)}</td><td>${money(x.days_1_30)}</td><td>${money(x.days_31_60)}</td><td>${money(x.days_61_90)}</td><td>${money(x.days_over_90)}</td><td class="fw-semibold">${money(x.outstanding)}</td></tr>`));
      document.getElementById('purchaseSummary').innerHTML=table(['Ringkasan','Jumlah'],[
        `<tr><td>Barang diterima pada periode ini</td><td class="text-end">${esc(data.purchase_summary.received_in_period_orders||0)} pesanan · ${money(data.purchase_summary.received_in_period_total)}</td></tr>`,
        `<tr><td>Pesanan terbuka (dibuat pada periode)</td><td class="text-end">${esc(data.purchase_summary.pending_orders||0)} pesanan · ${money(data.purchase_summary.pending_total)}</td></tr>`,
        `<tr><td>Pesanan dibatalkan (dibuat pada periode)</td><td class="text-end">${esc(data.purchase_summary.cancelled_orders||0)}</td></tr>`,
        `<tr><td>Total utang pemasok terbuka</td><td class="text-end fw-semibold">${money(data.supplier_payable_summary?.outstanding||0)}</td></tr>`,
        `<tr><td>Lewat jatuh tempo</td><td class="text-end text-danger">${money(data.supplier_payable_summary?.overdue||0)}</td></tr>`,
        `<tr><td>Kredit pemasok tersedia</td><td class="text-end">${money(data.supplier_payable_summary?.available_credit||0)}</td></tr>`,
        `<tr><td>Pembayaran pemasok periode ini</td><td class="text-end">${money((data.supplier_payments_period||[]).reduce((sum,x)=>sum+Number(x.amount||0),0))}</td></tr>`,
        `<tr><td>Nilai retur pembelian periode ini</td><td class="text-end">${money(data.supplier_returns_period?.return_value||0)}</td></tr>`
      ]);
      if(canViewAllBranches) document.getElementById('branchSummary').innerHTML=table(
        ['Cabang','Penjualan kotor','Retur','Penjualan bersih','Pembelian diterima','Pengeluaran','Utang pemasok','Piutang pelanggan'],
        (data.branch_summary||[]).map(x=>`<tr><td>${esc(x.branch_name)}</td><td>${money(x.gross_sales)}</td><td>${money(x.refunds)}</td><td class="fw-semibold">${money(x.net_sales)}</td><td>${money(x.received_purchases)}</td><td>${money(x.expenses)}</td><td>${money(x.supplier_payables)}</td><td>${money(x.customer_receivables)}</td></tr>`)
      );
      if(canViewAllBranches) document.getElementById('auditActivity').innerHTML=table(
        ['Waktu','Cabang','Pengguna','Aktivitas','Entitas','Rincian'],
        (data.audit_activity||[]).map(x=>`<tr><td>${esc(x.created_at)}</td><td>${esc(x.branch_name)}</td><td>${esc(x.user_name)}</td><td>${esc(auditName(x.action_code))}</td><td>${esc(x.entity_type)} #${esc(x.entity_id)}</td><td>${esc(Object.entries(x.details||{}).map(([key,value])=>key+': '+value).join(' · '))}</td></tr>`)
      );
      document.getElementById('supplierAging').innerHTML=table(['Pemasok','Belum jatuh tempo','1–30 hari','31–60 hari','61–90 hari','>90 hari','Total'],(data.supplier_aging||[]).map(x=>`<tr><td>${esc(x.supplier_name)}</td><td>${money(x.not_due)}</td><td>${money(x.days_1_30)}</td><td>${money(x.days_31_60)}</td><td>${money(x.days_61_90)}</td><td>${money(x.days_over_90)}</td><td class="fw-semibold">${money(x.outstanding)}</td></tr>`));
      document.getElementById('purchasesDaily').innerHTML=table(['Tanggal diterima','Pesanan','Nilai diterima'],data.purchases_by_day.map(x=>`<tr><td>${esc(x.day)}</td><td>${esc(x.orders_count)}</td><td>${money(x.received_total)}</td></tr>`));
      document.getElementById('purchaseSummaryPanel').classList.toggle('d-none',!canViewProfit);
      document.getElementById('receivablePanel').classList.toggle('d-none',!canViewProfit);
      document.getElementById('purchaseBySupplierPanel').classList.toggle('d-none',!canViewProfit);
      document.getElementById('purchaseByProductPanel').classList.toggle('d-none',!canViewProfit);
      document.getElementById('inventoryPanel').classList.toggle('d-none',!canViewProfit);
      document.getElementById('purchaseOrdersPanel').classList.toggle('d-none',!canViewProfit);
      document.getElementById('shiftPanel').classList.toggle('d-none',!canViewProfit);
      document.getElementById('expenseReport').classList.toggle('d-none',!canViewProfit);
      const paymentLabels={cash:'Tunai',qris:'QRIS',transfer:'Transfer',debit:'Kartu debit',credit:'Kartu kredit',other:'Lainnya'};
      const orderLabels={retail:'Ritel',dine_in:'Makan di tempat',takeaway:'Bawa pulang',pharmacy:'Apotek',laundry:'Laundry',printing:'Percetakan',service:'Jasa'};
      const itemLabels={goods:'Barang',menu:'Menu',medicine:'Obat',laundry:'Laundry',print:'Percetakan',service:'Jasa'};
      const movementLabels={sale:'Penjualan',purchase:'Pembelian',adjustment:'Penyesuaian',transfer_in:'Transfer masuk',transfer_out:'Transfer keluar',return:'Retur',void:'Pembatalan'};
      const purchaseStatus={ordered:'Menunggu barang',received:'Diterima',cancelled:'Dibatalkan'};
      document.getElementById('daily').innerHTML=table(['Tanggal','Transaksi','Total termasuk pajak','Refund tercatat','Total setelah refund'],data.daily.map(x=>`<tr><td>${esc(x.day)}</td><td>${esc(x.transactions)}</td><td>${money(x.gross_sales)}</td><td>${money(x.refunds)}</td><td class="fw-semibold">${money(x.net_sales)}</td></tr>`));
      document.getElementById('payments').innerHTML=table(['Metode','Nominal','Jumlah pembayaran'],data.payments.map(x=>`<tr><td>${esc(paymentLabels[x.method]||x.method)}</td><td>${money(x.amount)}</td><td>${esc(x.payments)}</td></tr>`));
      document.getElementById('cashiers').innerHTML=table(['Kasir','Transaksi','Penjualan','Rata-rata'],data.by_cashier.map(x=>`<tr><td>${esc(x.cashier_name)}</td><td>${esc(x.transactions)}</td><td>${money(x.sales)}</td><td>${money(x.average_ticket)}</td></tr>`));
      document.getElementById('orders').innerHTML=table(['Jenis','Transaksi','Total penjualan','Rata-rata'],data.by_order_type.map(x=>`<tr><td>${esc(orderLabels[x.order_type]||x.order_type)}</td><td>${esc(x.transactions)}</td><td>${money(x.sales)}</td><td>${money(x.average_ticket)}</td></tr>`));
      document.getElementById('expenses').innerHTML=table(['Kategori','Catatan','Nominal'],data.expenses_by_category.map(x=>`<tr><td>${esc(x.category)}</td><td>${esc(x.entries)}</td><td>${money(x.amount)}</td></tr>`));
      document.getElementById('hours').innerHTML=table(['Jam','Transaksi','Penjualan'],data.by_hour.map(x=>`<tr><td>${String(x.hour).padStart(2,'0')}:00</td><td>${esc(x.transactions)}</td><td>${money(x.sales)}</td></tr>`));
      document.getElementById('purchaseSuppliers').innerHTML=table(['Pemasok','Pesanan diterima','Total diterima'],data.purchases_by_supplier.map(x=>`<tr><td>${esc(x.supplier_name)}</td><td>${esc(x.orders_count)}</td><td>${money(x.received_total)}</td></tr>`));
      document.getElementById('purchaseProducts').innerHTML=table(['Produk','Varian','SKU','Qty diterima','Nilai modal'],data.purchases_by_product.map(x=>`<tr><td>${esc(x.product_name)}</td><td>${esc(x.variant_name)}</td><td>${esc(x.sku||'—')}</td><td>${esc(x.qty_received)}</td><td>${money(x.received_cost)}</td></tr>`));
      document.getElementById('purchaseOrders').innerHTML=table(['No. pesanan','Pemasok','Status','Tanggal pesan','Tanggal terima','Nilai'],data.purchases.map(x=>`<tr><td>${esc(x.order_no)}</td><td>${esc(x.supplier_name)}</td><td>${esc(purchaseStatus[x.status]||x.status)}</td><td>${esc(x.ordered_at)}</td><td>${esc(x.received_at||'—')}</td><td class="text-end">${money(x.total_amount)}</td></tr>`));
      document.getElementById('inventoryValue').innerHTML=table(['Indikator','Nilai'],[
        `<tr><td>Jumlah persediaan (gabungan unit)</td><td class="text-end">${esc(data.stock_value.inventory_units||0)}</td></tr>`,
        `<tr><td>Nilai modal persediaan (estimasi)</td><td class="text-end fw-semibold">${money(data.stock_value.inventory_cost_value)}</td></tr>`,
        `<tr><td>Varian di bawah stok minimum</td><td class="text-end">${esc(data.stock_value.low_stock_variants||0)}</td></tr>`
      ]);
      document.getElementById('stockMovements').innerHTML=table(['Jenis pergerakan','Jumlah catatan','Perubahan kuantitas'],data.stock_movements.map(x=>`<tr><td>${esc(movementLabels[x.movement_type]||x.movement_type)}</td><td>${esc(x.movements)}</td><td>${esc(x.quantity_change)}</td></tr>`));
      document.getElementById('shifts').innerHTML=table(['Ukuran kontrol kas','Nilai'],[
        `<tr><td>Shift dibuka pada periode</td><td class="text-end">${esc(data.shift_summary.shifts||0)}</td></tr>`,
        `<tr><td>Shift masih terbuka</td><td class="text-end">${esc(data.shift_summary.open_shifts||0)}</td></tr>`,
        `<tr><td>Kas seharusnya (shift ditutup)</td><td class="text-end">${money(data.shift_summary.expected_cash)}</td></tr>`,
        `<tr><td>Kas dihitung (shift ditutup)</td><td class="text-end">${money(data.shift_summary.counted_cash)}</td></tr>`,
        `<tr><td>Selisih kas tercatat</td><td class="text-end fw-semibold">${money(data.shift_summary.cash_difference)}</td></tr>`
      ]);
      document.getElementById('products').innerHTML=table(['Produk / layanan','Kategori','Qty terjual','Qty retur','Penjualan item bersih','HPP setelah retur','Laba kotor'],data.top_products.map(x=>`<tr><td>${esc(x.item_name)}</td><td>${esc(itemLabels[x.item_type]||x.item_type)}</td><td>${esc(x.quantity_sold)}</td><td>${esc(x.quantity_returned)}</td><td>${money(x.sales)}</td><td>${money(x.cost)}</td><td>${money(x.gross_profit)}</td></tr>`));
      document.getElementById('returns').innerHTML=table(['Tanggal','Invoice','Diproses oleh','Metode refund','Nominal refund','Catatan'],data.returns.map(x=>`<tr><td>${esc(x.returned_at)}</td><td>${esc(x.invoice_no)}</td><td>${esc(x.cashier_name)}</td><td>${esc(paymentLabels[x.refund_method]||x.refund_method)}</td><td>${money(x.refund_amount)}</td><td>${esc(x.note||'—')}</td></tr>`));
      document.getElementById('stock').innerHTML=table(['Produk','Varian','SKU','Stok','Batas minimum'],data.low_stock.map(x=>`<tr><td>${esc(x.product_name)}</td><td>${esc(x.variant_name)}</td><td>${esc(x.sku||'—')}</td><td>${esc(x.stock_qty)} ${esc(x.unit)}</td><td>${esc(x.min_stock)} ${esc(x.unit)}</td></tr>`));
      document.getElementById('loading').classList.add('d-none'); document.getElementById('report').classList.remove('d-none');
    } catch(error) { document.getElementById('loading').classList.add('d-none'); message.className='alert alert-danger'; message.textContent=error.message; }
  }
  document.getElementById('filters').addEventListener('submit',event=>{event.preventDefault();load();});
  document.getElementById('printReport').addEventListener('click',printSalesReport);
  const purchasePrint=document.getElementById('printPurchases');
  if(purchasePrint) purchasePrint.addEventListener('click',printPurchaseReport);
  document.querySelectorAll('.range-preset').forEach(button=>button.addEventListener('click',()=>{
    const today=new Date(), to=new Date(today), from=new Date(today), range=button.dataset.range;
    if(range==='7') from.setDate(from.getDate()-6);
    if(range==='month') from.setDate(1);
    if(range==='previous-month'){from.setDate(1);from.setMonth(from.getMonth()-1);to.setDate(0);}
    if(range==='year'){from.setMonth(0,1);}
    const iso=date=>`${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
    document.getElementById('from').value=iso(from);document.getElementById('to').value=iso(to);load();
  }));
  load();
})();
</script>
</body></html>
