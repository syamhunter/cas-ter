<?php
/** @var string $base @var string $csrf @var array $invoices @var array $proofs @var array $legacyPayments @var array $summary @var int $total @var int $page @var int $pageCount @var string $selectedStatus */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$statusLabels = ['unpaid' => 'Belum lunas', 'paid' => 'Lunas', 'overdue' => 'Terlambat', 'void' => 'Dibatalkan'];
$statusTones = ['unpaid' => 'warning', 'paid' => 'success', 'overdue' => 'danger', 'void' => 'secondary'];
?>
<p class="text-secondary mb-3">Invoice dapat dibuat manual dari detail usaha atau dibuat otomatis menjelang masa aktif berakhir. Bukti transfer Owner menunggu verifikasi Admin.</p>
<div class="row g-2 mb-3">
  <?php foreach ([
      'total' => ['Invoice', 'primary'], 'unpaid_count' => ['Belum jatuh tempo', 'warning'],
      'overdue_count' => ['Terlambat', 'danger'], 'paid_count' => ['Lunas', 'success'],
  ] as $key => [$label, $tone]): ?>
    <div class="col-6 col-xl-3"><div class="metric-card"><div class="metric-label"><span class="status-dot text-<?= $e($tone) ?>"></span><?= $e($label) ?></div><div class="metric-value"><?= number_format((int) ($summary[$key] ?? 0), 0, ',', '.') ?></div></div></div>
  <?php endforeach; ?>
  <div class="col-12"><div class="card"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2 py-2"><span class="small text-muted">Tagihan belum lunas</span><strong class="fs-5">Rp<?= number_format((float) ($summary['outstanding'] ?? 0), 0, ',', '.') ?></strong></div></div></div>
</div>
<?php if ($proofs): ?>
<div class="card mb-3"><div class="card-header"><strong>Bukti transfer menunggu verifikasi</strong><div class="small text-muted"><?= count($proofs) ?> pengajuan teratas</div></div><div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Invoice / owner</th><th>Usaha</th><th>Tanggal transfer</th><th>Nominal</th><th>Referensi</th><th>Bukti</th><th>Keputusan</th></tr></thead><tbody>
  <?php foreach ($proofs as $proof): ?><tr><td><a class="fw-semibold" href="<?= $e($base) ?>/admin/billing/invoice?id=<?= (int)$proof['invoice_id'] ?>"><?= $e($proof['invoice_no']) ?></a><div class="small text-muted"><?= $e($proof['owner_name']) ?> · <?= $e($proof['owner_email']) ?></div></td><td><?= $e($proof['tenant_name']) ?></td><td><?= $e($proof['paid_at']) ?><div class="small text-muted"><?= $e($proof['payment_method']) ?></div></td><td class="text-nowrap">Rp<?= number_format((float)$proof['amount'],0,',','.') ?></td><td><?= $e($proof['reference']) ?></td><td><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/billing/proof/file?id=<?= (int)$proof['id'] ?>">Unduh <?= $e(strtoupper(pathinfo($proof['original_name'], PATHINFO_EXTENSION))) ?></a></td><td><form method="post" action="<?= $e($base) ?>/admin/billing/proof/review" class="d-grid gap-1"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="proof_id" value="<?= (int)$proof['id'] ?>"><select class="form-select form-select-sm" name="decision"><option value="approved">Setujui</option><option value="rejected">Tolak</option></select><label class="visually-hidden" for="reviewNote<?= (int)$proof['id'] ?>">Catatan verifikasi</label><input class="form-control form-control-sm" id="reviewNote<?= (int)$proof['id'] ?>" name="reviewer_note" maxlength="500" placeholder="Catatan (wajib jika ditolak)"><button class="btn btn-primary btn-sm" type="submit" onclick="return confirm('Simpan keputusan verifikasi bukti ini?')">Proses</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php endif; ?>
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <div><strong>Invoice langganan</strong><div class="small text-muted"><?= number_format($total, 0, ',', '.') ?> invoice dalam filter</div></div>
    <form method="get" action="<?= $e($base) ?>/admin/billing" class="d-flex gap-2"><label class="visually-hidden" for="invoiceStatus">Status invoice</label><select class="form-select form-select-sm" id="invoiceStatus" name="status"><option value="">Semua status</option><?php foreach ($statusLabels as $key => $label): ?><option value="<?= $e($key) ?>"<?= $selectedStatus === $key ? ' selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select><button class="btn btn-primary btn-sm" type="submit">Filter</button></form>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Invoice</th><th>Pelanggan / paket</th><th>Periode</th><th>Jatuh tempo</th><th>Nominal</th><th>Status</th><th>Pembayaran / tindakan</th></tr></thead><tbody>
    <?php foreach ($invoices as $invoice): $status = $invoice['display_status']; ?>
      <tr>
        <td class="text-nowrap"><a class="fw-semibold text-decoration-none" href="<?= $e($base) ?>/admin/billing/invoice?id=<?= (int) $invoice['id'] ?>"><?= $e($invoice['invoice_no']) ?></a><div class="small text-muted">Terbit <?= $e($invoice['issued_at']) ?></div></td>
        <td><a class="text-decoration-none fw-semibold" href="<?= $e($base) ?>/admin/tenants/detail?id=<?= (int) $invoice['tenant_id'] ?>"><?= $e($invoice['tenant_name']) ?></a><div class="small text-muted"><?= $e($invoice['plan_name']) ?> · <?= $e($invoice['tenant_email']) ?></div></td>
        <td class="text-nowrap"><?= $e($invoice['period_start']) ?><div class="small text-muted">s.d. <?= $e($invoice['period_end']) ?></div></td>
        <td class="text-nowrap"><?= $e($invoice['due_at']) ?><div class="small text-muted">Tenggang s.d. <?= $e($invoice['grace_until']) ?></div></td>
        <td class="text-nowrap fw-semibold">Rp<?= number_format((float) $invoice['amount'], 0, ',', '.') ?></td>
        <td><span class="badge text-bg-<?= $e($statusTones[$status] ?? 'secondary') ?>"><?= $e($statusLabels[$status] ?? $status) ?></span></td>
        <td>
          <?php if ($status === 'paid'): ?>
            <div class="small">Lunas <?= $e($invoice['paid_at']) ?><div class="text-muted"><?= $e($invoice['payment_method']) ?> · <?= $e($invoice['reference']) ?></div></div>
          <?php elseif (in_array($status, ['unpaid', 'overdue'], true)): ?>
            <details><summary class="btn btn-primary btn-sm">Catat pelunasan</summary>
              <form method="post" action="<?= $e($base) ?>/admin/billing/invoices/pay" class="package-edit mt-2" onsubmit="return confirm('Pastikan dana sebesar Rp<?= number_format((float) $invoice['amount'], 0, ',', '.') ?> sudah diterima.')">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>">
                <label class="form-label small" for="paidAt<?= (int) $invoice['id'] ?>">Tanggal diterima</label><input class="form-control form-control-sm mb-2" id="paidAt<?= (int) $invoice['id'] ?>" name="paid_at" type="date" value="<?= date('Y-m-d') ?>" required>
                <label class="form-label small" for="method<?= (int) $invoice['id'] ?>">Metode</label><select class="form-select form-select-sm mb-2" id="method<?= (int) $invoice['id'] ?>" name="payment_method"><option value="bank_transfer">Transfer bank</option><option value="qris">QRIS</option><option value="cash">Tunai</option><option value="other">Lainnya</option></select>
                <label class="form-label small" for="reference<?= (int) $invoice['id'] ?>">Referensi / nomor bukti</label><input class="form-control form-control-sm mb-2" id="reference<?= (int) $invoice['id'] ?>" name="reference" maxlength="120" required>
                <label class="form-label small" for="notes<?= (int) $invoice['id'] ?>">Catatan</label><input class="form-control form-control-sm mb-2" id="notes<?= (int) $invoice['id'] ?>" name="notes" maxlength="255">
                <button class="btn btn-primary btn-sm" type="submit">Konfirmasi lunas</button>
              </form>
            </details>
            <form method="post" action="<?= $e($base) ?>/admin/billing/invoices/void" class="mt-1" onsubmit="return confirm('Batalkan invoice ini? Invoice yang dibatalkan tidak dapat dilunasi.')"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>"><button class="btn btn-outline-danger btn-sm" type="submit">Batalkan</button></form>
          <?php else: ?><span class="small text-muted">Tidak ada tindakan</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$invoices): ?><tr><td colspan="7" class="text-center text-muted py-4">Belum ada invoice untuk filter ini. Buat invoice dari detail usaha.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-footer d-flex justify-content-between align-items-center"><span class="small">Halaman <?= $page ?> dari <?= $pageCount ?></span><div class="d-flex gap-2">
    <?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/billing?<?= $e(http_build_query(['status' => $selectedStatus, 'page' => $page - 1])) ?>">Sebelumnya</a><?php endif; ?>
    <?php if ($page < $pageCount): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/billing?<?= $e(http_build_query(['status' => $selectedStatus, 'page' => $page + 1])) ?>">Berikutnya</a><?php endif; ?>
  </div></div>
</div>
<details class="card mt-3">
  <summary class="card-header package-summary fw-semibold">Pembayaran lama sebelum sistem invoice (20 terbaru)</summary>
  <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Tanggal</th><th>Usaha</th><th>Periode</th><th>Nominal</th><th>Referensi</th><th>Dicatat oleh</th></tr></thead><tbody>
    <?php foreach ($legacyPayments as $payment): ?><tr><td><?= $e($payment['paid_at']) ?></td><td><?= $e($payment['tenant_name']) ?></td><td><?= $e($payment['period_start']) ?> – <?= $e($payment['period_end']) ?></td><td>Rp<?= number_format((float) $payment['amount'], 0, ',', '.') ?></td><td><?= $e($payment['reference'] ?: '-') ?></td><td><?= $e($payment['admin_name']) ?></td></tr><?php endforeach; ?>
    <?php if (!$legacyPayments): ?><tr><td colspan="6" class="text-center text-muted py-3">Tidak ada catatan lama.</td></tr><?php endif; ?>
  </tbody></table></div>
</details>