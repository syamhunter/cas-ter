<?php
declare(strict_types=1);

$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($value): string => 'Rp' . number_format((float) $value, 0, ',', '.');
$sidebarBase = $base;
$sidebarBusiness = $context['tenant_name'];
$sidebarBranch = $context['branch_name'];
$sidebarRole = $context['role'];
$sidebarUser = \App\Core\Auth::name();
$sidebarPage = 'expenses';
$sidebarActive = 'expenses';
$paymentLabels = ['cash' => 'Tunai', 'qris' => 'QRIS', 'transfer' => 'Transfer', 'debit' => 'Kartu debit', 'credit' => 'Kartu kredit', 'other' => 'Lainnya'];
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Pengeluaran - <?= $e($context['tenant_name']) ?></title>
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
    .metric{height:100%;padding:17px;border:1px solid #e5e9f2;border-radius:14px;background:#fff}.metric span{display:block;color:var(--muted);font-size:.82rem}.metric strong{display:block;margin-top:8px;font-size:1.35rem}
    .table thead th{white-space:nowrap;color:#64728a;font-size:.78rem;text-transform:uppercase;letter-spacing:.03em}.footer{border-top:1px solid #e5e9f2;background:#fff;color:var(--muted)}
    @media(max-width:991.98px){.app-shell{display:flex;flex-direction:column}.app-sidebar{position:sticky;top:0;width:100%;height:auto;padding:8px 12px;overflow:hidden}.app-sidebar-brand,.app-sidebar-caption,.app-sidebar-footer{display:none}.app-sidebar-nav{display:flex;gap:6px;overflow-x:auto}.app-sidebar-link{flex:0 0 auto;padding:8px 11px;font-size:.82rem}.app-sidebar-icon{display:none}}
  </style>
</head>
<body>
<div class="app-shell">
  <?php require dirname(__DIR__) . '/partials/sidebar.php'; ?>
  <div class="app-content">
    <main class="container-fluid page-wrap py-4 px-3 px-lg-4">
      <div class="mb-4"><div class="text-primary text-uppercase fw-bold small">Keuangan usaha</div><h1 class="h3 fw-bold mb-1">Pengeluaran</h1>
        <div class="muted"><?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></div></div>
      <?php if ($flash !== null): ?>
        <div class="alert alert-<?= $e($flash['type'] ?? 'info') ?>" role="status"><?= $e($flash['message'] ?? '') ?></div>
      <?php endif; ?>
      <div class="row g-4 mb-4">
        <div class="col-12 col-xl-4">
          <section class="surface p-3 p-lg-4">
            <h2 class="h5 fw-bold mb-3">Catat pengeluaran</h2>
            <form method="post" action="<?= $e($base) ?>/expenses" class="vstack gap-3">
              <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
              <div><label class="form-label" for="expense-date">Tanggal</label><input class="form-control" id="expense-date" type="date" name="expense_date" value="<?= $e(date('Y-m-d')) ?>" max="<?= $e(date('Y-m-d')) ?>" required></div>
              <div><label class="form-label" for="category">Kategori</label><select class="form-select" id="category" name="category" required>
                <?php foreach ($categories as $category): ?><option value="<?= $e($category) ?>"><?= $e($category) ?></option><?php endforeach; ?>
              </select></div>
              <div><label class="form-label" for="amount">Nominal (Rp)</label><input class="form-control" id="amount" type="number" name="amount" min="0.01" max="9999999999999.99" step="0.01" inputmode="decimal" required></div>
              <div><label class="form-label" for="payment">Metode pembayaran</label><select class="form-select" id="payment" name="payment_method" required>
                <?php foreach ($methods as $method): ?><option value="<?= $e($method) ?>"<?= $method === 'cash' && !$cashShiftOpen ? ' disabled' : '' ?>><?= $e($paymentLabels[$method] ?? $method) ?><?= $method === 'cash' && !$cashShiftOpen ? ' (shift belum dibuka)' : '' ?></option><?php endforeach; ?>
              </select></div>
              <div><label class="form-label" for="description">Keterangan</label><input class="form-control" id="description" name="description" maxlength="255" placeholder="Contoh: pembayaran listrik toko" required></div>
              <button class="btn btn-primary" type="submit">Simpan pengeluaran</button>
              <div class="small muted">Pengeluaran tunai otomatis dicatat sebagai kas keluar pada shift aktif. Catatan pengeluaran bersifat permanen untuk menjaga jejak audit.</div>
            </form>
          </section>
        </div>
        <div class="col-12 col-xl-8">
          <section class="surface p-3 p-lg-4 h-100">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
              <div><h2 class="h5 fw-bold mb-1">Ringkasan periode</h2><div class="muted small">Hanya data cabang aktif yang ditampilkan.</div></div>
              <div class="metric"><span>Total pengeluaran</span><strong><?= $money($total['total'] ?? 0) ?></strong></div>
            </div>
            <form method="get" action="<?= $e($base) ?>/expenses" class="row g-2 align-items-end">
              <div class="col-6"><label class="form-label" for="from">Dari</label><input class="form-control" type="date" id="from" name="from" value="<?= $e($from) ?>" required></div>
              <div class="col-6"><label class="form-label" for="to">Sampai</label><input class="form-control" type="date" id="to" name="to" value="<?= $e($to) ?>" required></div>
              <div class="col-12 d-grid"><button class="btn btn-outline-dark">Tampilkan periode</button></div>
            </form>
            <div class="small muted mt-3"><?= (int) ($total['count'] ?? 0) ?> catatan pada periode ini</div>
          </section>
        </div>
      </div>
      <section class="surface p-3 p-lg-4">
        <h2 class="h5 fw-bold mb-3">Riwayat pengeluaran</h2>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
          <thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th>Metode</th><th>Dicatat oleh</th><th class="text-end">Nominal</th></tr></thead>
          <tbody>
            <?php if ($expenses === []): ?><tr><td colspan="6" class="text-center py-5 muted">Belum ada pengeluaran pada periode ini.</td></tr>
            <?php else: foreach ($expenses as $expense): ?>
              <tr><td><?= $e($expense['expense_date']) ?></td><td><span class="badge text-bg-light"><?= $e($expense['category']) ?></span></td>
                <td><?= $e($expense['description']) ?></td><td><?= $e($paymentLabels[$expense['payment_method']] ?? $expense['payment_method']) ?></td>
                <td><?= $e($expense['created_by']) ?></td><td class="text-end fw-semibold"><?= $money($expense['amount']) ?></td></tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table></div>
        <?php if (count($expenses) >= 500): ?><div class="small muted mt-3">Dibatasi 500 catatan terbaru. Persempit rentang tanggal untuk melihat data lain.</div><?php endif; ?>
      </section>
    </main>
    <footer class="footer py-3 px-4 small">© <?= date('Y') ?> <?= $e($context['tenant_name']) ?> · <?= $e($context['branch_name']) ?></footer>
  </div>
</div>
</body></html>
