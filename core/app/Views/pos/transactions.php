<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sidebarBase = $base;
$sidebarBusiness = $context['tenant_name'];
$sidebarBranch = $context['branch_name'];
$sidebarRole = $context['role'];
$sidebarUser = \App\Core\Auth::name();
$sidebarPage = 'transactions';
$sidebarActive = 'transactions';
$today = date('Y-m-d');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Transaksi - <?= $e($context['tenant_name']) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root{--ink:#17243b;--muted:#738096;--blue:#3157d5}
    body{min-height:100vh;margin:0;background:#f3f5fa;color:var(--ink);font-family:system-ui,-apple-system,"Segoe UI",sans-serif}
    .app-shell{min-height:100vh;display:grid;grid-template-columns:250px minmax(0,1fr)}
    .app-content{min-width:0;display:flex;flex-direction:column}.app-content>main{flex:1}
    .app-sidebar{position:sticky;top:0;height:100vh;padding:20px 14px;display:flex;flex-direction:column;gap:8px;overflow:auto;background:#111d36;color:#e8eeff;z-index:1040}
    .app-sidebar-brand{display:flex;align-items:center;gap:11px;padding:3px 5px 18px;color:inherit;text-decoration:none;min-width:0}
    .app-sidebar-mark{flex:0 0 40px;width:40px;height:40px;display:grid;place-items:center;border-radius:13px;background:#4269e8;color:#fff;font-weight:800}
    .app-sidebar-brand-copy{min-width:0;display:grid}.app-sidebar-brand-copy strong,.app-sidebar-brand-copy small{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .app-sidebar-brand-copy small{color:#aab6d1}.app-sidebar-caption{padding:8px 10px 4px;color:#8493b2;font-size:.68rem;font-weight:750;letter-spacing:.1em}
    .app-sidebar-nav{display:grid;gap:5px}.app-sidebar-link{display:flex;align-items:center;gap:11px;padding:10px 11px;border-radius:10px;text-decoration:none;color:#c7d1e7;font-size:.9rem;font-weight:600}
    .app-sidebar-link:hover{background:#ffffff12;color:#fff}.app-sidebar-link.active{background:#3157d5;color:#fff}
    .app-sidebar-icon{flex:0 0 25px;width:25px;height:25px;display:grid;place-items:center;border:1px solid #ffffff20;border-radius:8px}
    .app-sidebar-icon svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
    .app-sidebar-footer{display:grid;gap:3px;margin-top:auto;padding:13px 10px 3px;border-top:1px solid #ffffff1c;color:#aab6d1;font-size:.75rem}
    .app-sidebar-user{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#fff;font-weight:650}
    .page-wrap{max-width:1500px}.surface{background:#fff;border:1px solid #e5e9f2;border-radius:16px}
    .muted{color:var(--muted)}.table thead th{white-space:nowrap;color:#64728a;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em}
    .footer{border-top:1px solid #e5e9f2;background:#fff;color:var(--muted)}
    @media(max-width:991.98px){.app-shell{display:flex;flex-direction:column}.app-sidebar{position:sticky;top:0;width:100%;height:auto;padding:8px 12px;overflow:hidden}.app-sidebar-brand,.app-sidebar-caption,.app-sidebar-footer{display:none}.app-sidebar-nav{display:flex;gap:6px;overflow-x:auto}.app-sidebar-link{flex:0 0 auto;padding:8px 11px;font-size:.82rem}.app-sidebar-icon{display:none}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php require dirname(__DIR__) . '/partials/sidebar.php'; ?>
  <div class="app-content">
    <main class="container-fluid page-wrap py-4 px-3 px-lg-4">
      <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">
        <div><div class="text-primary text-uppercase fw-bold small">Kasir / Riwayat</div><h1 class="h3 fw-bold mb-1">Transaksi</h1>
          <div class="muted"><?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></div></div>
        <a class="btn btn-primary" href="<?= $e($base) ?>/pos">Buka kasir</a>
      </div>
      <section class="surface p-3 p-lg-4 mb-4">
        <form id="filters" class="row g-3 align-items-end">
          <div class="col-6 col-lg-3"><label class="form-label" for="from">Dari tanggal</label><input class="form-control" type="date" id="from" value="<?= $e($today) ?>" required></div>
          <div class="col-6 col-lg-3"><label class="form-label" for="to">Sampai tanggal</label><input class="form-control" type="date" id="to" value="<?= $e($today) ?>" required></div>
          <div class="col-12 col-lg-4"><label class="form-label" for="search">Invoice / ID transaksi</label><input class="form-control" id="search" maxlength="80" placeholder="Cari nomor invoice"></div>
          <div class="col-12 col-lg-2 d-grid"><button class="btn btn-dark" type="submit">Tampilkan</button></div>
        </form>
      </section>
      <div id="message" class="alert d-none" role="status"></div>
      <section class="surface p-3 p-lg-4">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
          <h2 class="h5 fw-bold mb-0">Daftar transaksi</h2><span id="resultCount" class="muted small"></span>
        </div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
          <thead><tr><th>Waktu</th><th>Invoice</th><th>Kasir</th><th>Jenis</th><th>Status</th><th class="text-end">Total</th><th></th></tr></thead>
          <tbody id="rows"><tr><td colspan="7" class="text-center py-5 muted">Memuat transaksi…</td></tr></tbody>
        </table></div>
        <div class="small muted mt-3">Menampilkan maksimal 100 transaksi terbaru untuk rentang yang dipilih.</div>
      </section>
      <section id="detailPanel" class="surface p-3 p-lg-4 mt-4 d-none">
        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
          <div><div class="text-primary text-uppercase fw-bold small">Rincian</div><h2 id="detailTitle" class="h5 fw-bold mb-0"></h2></div>
          <button id="closeDetail" class="btn btn-outline-secondary btn-sm" type="button">Tutup</button>
        </div>
        <div id="detailBody"></div>
      </section>
    </main>
    <footer class="footer py-3 px-4 small">© <?= date('Y') ?> <?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></footer>
  </div>
</div>
<script>
(() => {
  const base = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
  const csrf = <?= json_encode($csrf) ?>;
  const canReturn = <?= $canReturn ? 'true' : 'false' ?>;
  const money = value => new Intl.NumberFormat('id-ID', {style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(value)||0);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const rows = document.getElementById('rows'), message = document.getElementById('message');
  const panel = document.getElementById('detailPanel'), detailBody = document.getElementById('detailBody');
  let current = null;
  function showMessage(text, type='danger') {
    message.className = `alert alert-${type}`; message.textContent = text;
  }
  async function request(url, options={}) {
    const response = await fetch(url, {credentials:'same-origin', ...options});
    const json = await response.json();
    if (!response.ok || !json.success) throw new Error(json.message || 'Permintaan gagal.');
    return json.data;
  }
  async function load() {
    message.className = 'alert d-none';
    rows.innerHTML = '<tr><td colspan="7" class="text-center py-5 muted">Memuat transaksi…</td></tr>';
    const params = new URLSearchParams({from:document.getElementById('from').value,to:document.getElementById('to').value,q:document.getElementById('search').value.trim()});
    try {
      const data = await request(`${base}/api/transactions?${params}`);
      document.getElementById('resultCount').textContent = `${data.items.length} transaksi`;
      rows.innerHTML = data.items.length ? data.items.map(item => `<tr>
        <td>${esc(new Date(String(item.created_at).replace(' ','T')).toLocaleString('id-ID'))}</td>
        <td class="fw-semibold">${esc(item.invoice_no)}</td><td>${esc(item.cashier_name)}</td>
        <td>${esc(item.order_type)}</td><td><span class="badge text-bg-${item.status === 'paid' ? 'success' : 'secondary'}">${esc(item.status)}</span>${item.payment_status !== 'paid' ? `<small class="d-block text-danger">${esc(item.payment_status)} · sisa ${money(item.receivable_balance)}</small>` : ''}</td>
        <td class="text-end fw-semibold">${money(item.grand_total)}</td>
        <td class="text-end"><button class="btn btn-sm btn-outline-primary detail-button" data-id="${esc(item.id)}">Rincian</button></td>
      </tr>`).join('') : '<tr><td colspan="7" class="text-center py-5 muted">Tidak ada transaksi pada periode ini.</td></tr>';
      rows.querySelectorAll('.detail-button').forEach(button => button.addEventListener('click', () => showDetail(button.dataset.id)));
    } catch (error) {
      rows.innerHTML = '<tr><td colspan="7" class="text-center py-5 text-danger">Transaksi gagal dimuat.</td></tr>';
      showMessage(error.message);
    }
  }
  async function showDetail(id) {
    panel.classList.remove('d-none');
    detailBody.textContent = 'Memuat rincian…';
    panel.scrollIntoView({behavior:'smooth',block:'start'});
    try {
      const data = await request(`${base}/api/transactions?id=${encodeURIComponent(id)}`);
      current = data.transaction;
      document.getElementById('detailTitle').textContent = `${current.invoice_no} · ${money(current.grand_total)}`;
      detailBody.innerHTML = `<div class="table-responsive"><table class="table align-middle">
        <thead><tr><th>Item</th><th class="text-end">Jumlah</th><th class="text-end">Harga</th><th class="text-end">Total</th>${canReturn?'<th class="text-end">Retur qty</th>':''}</tr></thead>
        <tbody>${current.items.map(item => {
          const remaining = Math.max(0, Number(item.qty)-Number(item.returned_qty));
          return `<tr><td>${esc(item.item_name)}<div class="small muted">${esc(item.unit||'')}</div></td>
            <td class="text-end">${esc(item.qty)}</td><td class="text-end">${money(item.unit_price)}</td><td class="text-end">${money(item.line_total)}</td>
            ${canReturn?`<td class="text-end"><input class="form-control form-control-sm return-qty" type="number" min="0" max="${remaining}" step="0.001" value="0" data-item="${esc(item.id)}" aria-label="Jumlah retur ${esc(item.item_name)}" ${remaining<=0?'disabled':''}></td>`:''}
          </tr>`;
        }).join('')}</tbody></table></div>
        <div class="d-flex flex-wrap gap-3 small muted"><span>Status: ${esc(current.status)}</span><span>Status pembayaran: ${esc(current.payment_status)}</span><span>Pelanggan: ${esc(current.customer_name||'Umum')}</span><span>Dibayar: ${money(current.paid_total-current.change_amount)}</span><span>Sisa piutang: ${money(current.receivable_balance)}</span><span>Jatuh tempo: ${esc(current.due_at||'—')}</span><span>${esc(current.created_at)}</span></div>
        ${Number(current.receivable_balance)>0?`<div class="border-top mt-3 pt-3"><h3 class="h6 fw-bold">Terima pelunasan piutang</h3><div class="row g-2 align-items-end">
          <div class="col-12 col-md-3"><label class="form-label" for="receivableAmount">Nominal (maks. ${money(current.receivable_balance)})</label><input id="receivableAmount" class="form-control" type="number" min="0.01" max="${esc(current.receivable_balance)}" step="0.01" required></div>
          <div class="col-12 col-md-3"><label class="form-label" for="receivableMethod">Metode</label><select id="receivableMethod" class="form-select"><option value="cash">Tunai</option><option value="qris">QRIS</option><option value="transfer">Transfer</option><option value="debit">Debit</option><option value="credit">Kredit</option><option value="other">Lainnya</option></select></div>
          <div class="col-12 col-md-4"><label class="form-label" for="receivableReference">Referensi (opsional)</label><input id="receivableReference" class="form-control" maxlength="80"></div>
          <div class="col-12 col-md-2 d-grid"><button id="submitReceivable" class="btn btn-success">Catat bayar</button></div>
        </div></div>`:''}
        ${canReturn?`<div class="border-top mt-3 pt-3"><div class="row g-2 align-items-end">
          <div class="col-12 col-md-3"><label class="form-label" for="refundMethod">Metode refund</label><select id="refundMethod" class="form-select"><option value="cash">Tunai</option><option value="qris">QRIS</option><option value="transfer">Transfer</option><option value="debit">Debit</option><option value="credit">Kredit</option><option value="other">Lainnya</option></select></div>
          <div class="col-12 col-md-7"><label class="form-label" for="returnNote">Catatan (opsional)</label><input id="returnNote" class="form-control" maxlength="255"></div>
          <div class="col-12 col-md-2 d-grid"><button id="submitReturn" class="btn btn-outline-danger">Proses retur</button></div>
        </div></div>`:''}`;
      const submitReceivableButton = document.getElementById('submitReceivable');
      if (submitReceivableButton) submitReceivableButton.addEventListener('click', submitReceivable);
      if (canReturn) document.getElementById('submitReturn').addEventListener('click', submitReturn);
    } catch (error) {
      detailBody.textContent = error.message;
      showMessage(error.message);
    }
    async function submitReceivable() {
      const amount = Number(document.getElementById('receivableAmount').value);
      if (!(amount > 0) || amount > Number(current.receivable_balance)) {
        showMessage('Nominal harus lebih dari nol dan tidak melebihi saldo piutang.');
        return;
      }
      const button = document.getElementById('submitReceivable'); button.disabled = true;
      try {
        const result = await request(`${base}/api/receivables/pay`, {
          method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
          body:JSON.stringify({
            transaction_id:current.id, amount:amount.toFixed(2),
            method:document.getElementById('receivableMethod').value,
            reference_no:document.getElementById('receivableReference').value
          })
        });
        showMessage('Pelunasan dicatat. Sisa piutang: ' + money(result.remaining), 'success');
        await showDetail(current.id); await load();
      } catch (error) { showMessage(error.message); button.disabled = false; }
    }
  }
  async function submitReturn() {
    const items = [...detailBody.querySelectorAll('.return-qty')].map(input => ({item_id:Number(input.dataset.item),qty:Number(input.value)})).filter(item => item.qty > 0);
    if (!items.length) { showMessage('Isi jumlah retur minimal satu item.'); return; }
    const button = document.getElementById('submitReturn'); button.disabled = true;
    try {
      await request(`${base}/api/returns`, {method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({
        transaction_id:current.id,refund_method:document.getElementById('refundMethod').value,
        note:document.getElementById('returnNote').value,items
      })});
      showMessage('Retur berhasil diproses.','success');
      await showDetail(current.id); await load();
    } catch (error) { showMessage(error.message); button.disabled = false; }
  }
  document.getElementById('filters').addEventListener('submit', event => {event.preventDefault();load();});
  document.getElementById('closeDetail').addEventListener('click', () => panel.classList.add('d-none'));
  load();
})();
</script>
</body></html>
