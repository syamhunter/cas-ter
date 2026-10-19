<?php
/** @var array $summary @var array $activity */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$labels = [
    'tenant.created' => 'Pelanggan dibuat', 'tenant.status_changed' => 'Status pelanggan diubah',
    'tenant.plan_changed' => 'Paket pelanggan diubah', 'tenant.subscription_extended' => 'Langganan diperpanjang',
    'plan.created' => 'Paket dibuat', 'plan.updated' => 'Paket diperbarui', 'plan.status_changed' => 'Status paket diubah',
    'billing.invoice_created' => 'Invoice dibuat', 'billing.invoice_auto_created' => 'Invoice otomatis dibuat',
    'billing.invoice_paid' => 'Invoice dilunasi', 'billing.invoice_voided' => 'Invoice dibatalkan',
    'billing.proof_approved' => 'Bukti transfer disetujui', 'billing.proof_rejected' => 'Bukti transfer ditolak',
    'owner.password_reset' => 'Password owner direset',
];
?>
<p class="text-secondary mb-3">Ringkasan layanan dan tindak lanjut yang perlu diperhatikan.</p>
<div class="row g-2 mb-4">
  <?php foreach ([
      'total' => ['Semua usaha', 'primary'], 'active' => ['Aktif', 'success'], 'trial' => ['Trial', 'info'],
      'suspended' => ['Ditangguhkan', 'danger'], 'expired' => ['Kedaluwarsa', 'secondary'],
      'expiring' => ['Berakhir &lt; 14 hari', 'warning'], 'plans' => ['Paket aktif', 'secondary'],
  ] as $key => [$label, $tone]): ?>
    <div class="col-6 col-md-4 col-xl-3"><div class="metric-card"<?= $tone === 'warning' ? ' data-tone="warm"' : '' ?>><div class="metric-label"><span class="status-dot text-<?= $e($tone) ?>"></span><?= $label ?></div><div class="metric-value"><?= (int) ($summary[$key] ?? 0) ?></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <section class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center"><div><strong>Aktivitas terbaru</strong><div class="small text-muted">8 perubahan terakhir oleh admin</div></div><a class="btn btn-outline-primary btn-sm" href="<?= $e($base) ?>/admin/activity">Lihat semua</a></div>
      <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Waktu</th><th>Admin</th><th>Aktivitas</th><th>Objek</th></tr></thead><tbody>
        <?php foreach ($activity as $item): ?>
          <tr><td class="small text-nowrap"><?= $e($item['created_at']) ?></td><td><?= $e($item['admin_name']) ?></td><td><?= $e($labels[$item['action_code']] ?? $item['action_code']) ?></td><td><?= $e($item['entity_type']) ?><?= $item['entity_id'] !== null ? ' #' . (int) $item['entity_id'] : '' ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$activity): ?><tr><td colspan="4" class="text-center text-muted py-4">Belum ada aktivitas.</td></tr><?php endif; ?>
      </tbody></table></div>
    </div>
  </section>
  <aside class="col-lg-4"><div class="card"><div class="card-header fw-semibold">Akses cepat</div><div class="list-group list-group-flush">
    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= $e($base) ?>/admin/tenants">Kelola pelanggan <span aria-hidden="true">→</span></a>
    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= $e($base) ?>/admin/plans">Atur paket <span aria-hidden="true">→</span></a>
    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= $e($base) ?>/admin/billing">Catatan pembayaran <span aria-hidden="true">→</span></a>
    <a class="list-group-item list-group-item-action d-flex justify-content-between" href="<?= $e($base) ?>/admin/support">Inbox dukungan <span aria-hidden="true">→</span></a>
  </div></div></aside>
</div>