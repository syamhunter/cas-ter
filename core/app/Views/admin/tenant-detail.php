<?php
/** @var string $base @var string $csrf @var array $tenant @var array $branches @var array $invoices @var string $periodStart @var string $periodEnd @var string $dueDate */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3"><div><a href="<?= $e($base) ?>/admin/tenants" class="small text-decoration-none">← Kembali ke pelanggan</a><h2 class="h4 mt-2 mb-1"><?= $e($tenant['name']) ?></h2><div class="text-muted"><?= $e($tenant['owner_name']) ?> · <?= $e($tenant['email']) ?></div></div><span class="badge text-bg-<?= $e(['trial'=>'info','active'=>'success','suspended'=>'danger','expired'=>'secondary'][$tenant['effective_status']] ?? 'secondary') ?> align-self-center"><?= $e($tenant['effective_status']) ?></span></div>
<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="metric-card"><div class="metric-label">Paket</div><div class="metric-value fs-5"><?= $e($tenant['plan_name']) ?></div><div class="small text-muted">Rp<?= number_format((float) $tenant['price_monthly'], 0, ',', '.') ?>/bulan</div></div></div>
  <div class="col-md-4"><div class="metric-card"><div class="metric-label">Cabang aktif</div><div class="metric-value"><?= (int) $tenant['branch_count'] ?></div><div class="small text-muted">Batas: <?= $tenant['max_branches'] === null ? 'tanpa batas' : (int) $tenant['max_branches'] ?></div></div></div>
  <div class="col-md-4"><div class="metric-card"><div class="metric-label">Pengguna aktif</div><div class="metric-value"><?= (int) $tenant['user_count'] ?></div><div class="small text-muted">Batas: <?= $tenant['max_users'] === null ? 'tanpa batas' : (int) $tenant['max_users'] ?></div></div></div>
</div>
<div class="row g-3">
  <section class="col-xl-7"><div class="card mb-3"><div class="card-header fw-semibold">Informasi usaha</div><div class="card-body row g-3">
    <div class="col-sm-6"><div class="small text-muted">Dibuat</div><strong><?= $e($tenant['created_at']) ?></strong></div>
    <div class="col-sm-6"><div class="small text-muted">Langganan berakhir</div><strong><?= $e($tenant['effective_ends_at'] ?? 'Belum ditentukan') ?></strong></div>
    <div class="col-sm-6"><div class="small text-muted">Telepon</div><strong><?= $e($tenant['phone'] ?: '-') ?></strong></div>
    <div class="col-sm-6"><div class="small text-muted">Maks. transaksi/bulan</div><strong><?= $tenant['max_transactions_month'] === null ? 'Tanpa batas' : number_format((int) $tenant['max_transactions_month'], 0, ',', '.') ?></strong></div>
  </div></div>
  <div class="card"><div class="card-header fw-semibold">Cabang</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Kode</th><th>Nama cabang</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($branches as $branch): ?><tr><td><?= $e($branch['code']) ?></td><td><?= $e($branch['name']) ?></td><td><?= (int) $branch['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></td></tr><?php endforeach; ?>
    <?php if (!$branches): ?><tr><td colspan="3" class="text-muted text-center py-3">Belum ada cabang.</td></tr><?php endif; ?>
  </tbody></table></div></div></section>
  <aside class="col-xl-5">
    <div class="card mb-3"><div class="card-header fw-semibold">Perpanjang langganan</div><form method="post" action="<?= $e($base) ?>/admin/tenants/extend" class="card-body d-flex align-items-end gap-2"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tenant_id" value="<?= (int) $tenant['id'] ?>"><div class="flex-grow-1"><label class="form-label small" for="extendDays">Tambah hari</label><input class="form-control form-control-sm" id="extendDays" name="days" type="number" min="1" max="3650" value="30" required></div><button class="btn btn-primary btn-sm" type="submit">Perpanjang</button></form></div>
    <div class="card"><div class="card-header fw-semibold">Buat invoice langganan</div><form method="post" action="<?= $e($base) ?>/admin/billing/invoices" class="card-body row g-2">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="tenant_id" value="<?= (int) $tenant['id'] ?>">
      <div class="col-12"><div class="small text-muted">Paket saat ini: <strong><?= $e($tenant['plan_name']) ?></strong> · Rp<?= number_format((float) $tenant['price_monthly'], 0, ',', '.') ?></div></div>
      <div class="col-6"><label class="form-label small" for="periodStart">Periode mulai</label><input class="form-control form-control-sm" id="periodStart" name="period_start" type="date" value="<?= $e($periodStart) ?>" required></div>
      <div class="col-6"><label class="form-label small" for="periodEnd">Periode berakhir</label><input class="form-control form-control-sm" id="periodEnd" name="period_end" type="date" value="<?= $e($periodEnd) ?>" required></div>
      <div class="col-6"><label class="form-label small" for="invoiceDue">Jatuh tempo</label><input class="form-control form-control-sm" id="invoiceDue" name="due_at" type="date" value="<?= $e($dueDate) ?>" required></div>
      <div class="col-12"><div class="form-text mb-2">Nominal disalin dari harga paket saat invoice dibuat. Satu invoice berlaku untuk seluruh usaha dalam akun owner yang sama.</div><button class="btn btn-primary btn-sm" type="submit">Buat invoice</button></div>
    </form></div>
  </aside>
</div>
<div class="card mt-3"><div class="card-header fw-semibold">Riwayat invoice</div><div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Periode</th><th>Jatuh tempo</th><th>Nominal</th><th>Status</th><th>Pembayaran</th></tr></thead><tbody>
  <?php foreach ($invoices as $invoice): ?><tr><td><?= $e($invoice['invoice_no']) ?></td><td><?= $e($invoice['period_start']) ?> – <?= $e($invoice['period_end']) ?></td><td><?= $e($invoice['due_at']) ?></td><td>Rp<?= number_format((float) $invoice['amount'], 0, ',', '.') ?></td><td><?= $e($invoice['display_status']) ?></td><td><?= $e($invoice['paid_at'] ?? '-') ?><?= $invoice['reference'] ? ' · ' . $e($invoice['reference']) : '' ?></td></tr><?php endforeach; ?>
  <?php if (!$invoices): ?><tr><td colspan="6" class="text-muted text-center py-3">Belum ada invoice.</td></tr><?php endif; ?>
</tbody></table></div></div>