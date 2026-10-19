<?php
/** @var string $base @var array $activity @var string $action @var int $page @var int $pageCount @var int $total */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$labels = ['tenant.created'=>'Pelanggan dibuat','tenant.status_changed'=>'Status pelanggan diubah','tenant.plan_changed'=>'Paket pelanggan diubah','tenant.subscription_extended'=>'Langganan diperpanjang','plan.created'=>'Paket dibuat','plan.updated'=>'Paket diperbarui','plan.status_changed'=>'Status paket diubah','billing.invoice_created'=>'Invoice dibuat','billing.invoice_auto_created'=>'Invoice otomatis dibuat','billing.invoice_paid'=>'Invoice dilunasi','billing.invoice_voided'=>'Invoice dibatalkan','billing.proof_approved'=>'Bukti transfer disetujui','billing.proof_rejected'=>'Bukti transfer ditolak','owner.password_reset'=>'Password owner direset'];
?>
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><div><strong>Jejak aktivitas admin</strong><div class="small text-muted"><?= number_format($total, 0, ',', '.') ?> catatan</div></div>
    <form method="get" action="<?= $e($base) ?>/admin/activity" class="d-flex gap-2"><label class="visually-hidden" for="activityAction">Filter kode aksi</label><input class="form-control form-control-sm" id="activityAction" name="action" value="<?= $e($action) ?>" placeholder="Kode aksi"><button class="btn btn-primary btn-sm">Filter</button></form>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Waktu</th><th>Admin</th><th>Aksi</th><th>Objek</th><th>Rincian</th><th>Alamat IP</th></tr></thead><tbody>
    <?php foreach ($activity as $item): $details = json_decode((string) ($item['details'] ?? ''), true) ?: []; ?>
      <tr><td class="small text-nowrap"><?= $e($item['created_at']) ?></td><td><?= $e($item['admin_name']) ?></td><td><?= $e($labels[$item['action_code']] ?? $item['action_code']) ?><div class="small text-muted"><?= $e($item['action_code']) ?></div></td><td><?= $e($item['entity_type']) ?><?= $item['entity_id'] !== null ? ' #' . (int) $item['entity_id'] : '' ?></td>
      <td class="small"><?= $e(implode(', ', array_map(static fn($key, $value): string => $key . ': ' . (is_scalar($value) ? (string) $value : json_encode($value)), array_keys($details), array_values($details)))) ?></td><td class="small"><?= $e($item['ip_address'] ?? '-') ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$activity): ?><tr><td colspan="6" class="text-center text-muted py-4">Tidak ada aktivitas yang cocok.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-footer d-flex justify-content-between align-items-center"><span class="small">Halaman <?= $page ?> dari <?= $pageCount ?></span><div class="d-flex gap-2">
    <?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/activity?<?= $e(http_build_query(['action' => $action, 'page' => $page - 1])) ?>">Sebelumnya</a><?php endif; ?>
    <?php if ($page < $pageCount): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/activity?<?= $e(http_build_query(['action' => $action, 'page' => $page + 1])) ?>">Berikutnya</a><?php endif; ?>
  </div></div>
</div>