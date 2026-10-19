<?php
/** @var array $tenant @var array $branch @var array $summary @var array $recent @var array $modules @var int $activeUsers @var int $activeBranches @var int $activeProducts @var int|null $daysLeft @var int|null $monthLimit @var int $accountMonthCount @var int|null $usagePct @var int $monthCount @var string $today @var string $base */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$money = static fn($value): string => 'Rp' . number_format((float) $value, 0, ',', '.');
$status = (string) $tenant['status'];
$expired = $tenant['subscription_ends_at'] !== null && strtotime((string) $tenant['subscription_ends_at']) <= time();
$statusLabel = $expired ? 'Masa langganan berakhir' : (['trial'=>'Masa uji coba','active'=>'Aktif','suspended'=>'Ditangguhkan','expired'=>'Berakhir'][$status] ?? 'Tidak diketahui');
$statusTone = $expired || in_array($status, ['suspended','expired'], true) ? 'danger' : ($status === 'trial' ? 'primary' : 'success');
$orderLabels = ['retail'=>'Retail','dine_in'=>'Makan di tempat','takeaway'=>'Bawa pulang','pharmacy'=>'Apotek','laundry'=>'Laundry','printing'=>'Percetakan','service'=>'Jasa'];
?>
<section class="owner-card p-3 p-lg-4 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div><div class="small text-muted"><?= $e($today) ?> · <?= $e($branch['name']) ?></div><h2 class="h4 fw-bold mt-1 mb-1">Selamat datang, <?= $e($ownerName) ?></h2><p class="text-muted mb-0">Pantau performa usaha terpilih dan penggunaan paket bersama.</p></div>
    <div class="d-flex flex-wrap gap-2"><?php if (!$expired && in_array($status, ['active','trial'], true)): ?><a class="btn btn-primary" href="<?= $e($base) ?>/pos">Buka kasir</a><a class="btn btn-outline-primary" href="<?= $e($base) ?>/store">Manajemen toko</a><?php else: ?><span class="badge text-bg-danger align-self-center">Kasir tidak aktif</span><?php endif; ?></div>
  </div>
</section>
<?php if ($expired || in_array($status, ['suspended','expired'], true)): ?><div class="alert alert-danger"><strong>Akses kasir dijeda.</strong> <?= $expired ? 'Masa langganan usaha telah berakhir.' : 'Status langganan: ' . $e($statusLabel) ?> Hubungi administrator layanan.</div><?php elseif ($status === 'trial'): ?><div class="alert alert-primary"><strong>Anda sedang mencoba <?= $e($tenant['plan_name']) ?>.</strong> <?= $daysLeft !== null ? $daysLeft . ' hari tersisa.' : 'Masa uji coba aktif.' ?> Lihat status invoice dari menu Paket &amp; Billing.</div><?php endif; ?>
<div class="row g-2 mb-3">
  <?php foreach ([['Penjualan hari ini',$money($summary['sales_today'] ?? 0),number_format((int)($summary['transactions_today'] ?? 0),0,',','.') . ' transaksi'],['Penjualan bulan ini',$money($summary['sales_month'] ?? 0),number_format($monthCount,0,',','.') . ' transaksi'],['Produk aktif',number_format($activeProducts,0,',','.'),'Usaha terpilih'],['Akun & cabang',number_format($activeUsers,0,',','.') . ' pengguna',number_format($activeBranches,0,',','.') . ' cabang'] ] as [$label,$value,$detail]): ?>
    <div class="col-6 col-xl-3"><div class="metric-card"><div class="metric-label"><?= $e($label) ?></div><div class="metric-value mt-2"><?= $e($value) ?></div><div class="small text-muted mt-1"><?= $e($detail) ?></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <section class="col-lg-8"><div class="card h-100"><div class="card-header d-flex justify-content-between align-items-center"><div><strong>Transaksi hari ini</strong><div class="small text-muted">Aktivitas terbaru cabang <?= $e($branch['name']) ?></div></div><a class="btn btn-outline-primary btn-sm" href="<?= $e($base) ?>/transactions">Lihat transaksi</a></div>
    <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Invoice</th><th>Kasir</th><th>Jenis</th><th class="text-end">Total</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($recent as $transaction): ?><tr><td><strong><?= $e($transaction['invoice_no']) ?></strong><div class="small text-muted"><?= $e(date('H:i', strtotime((string)$transaction['created_at']))) ?></div></td><td><?= $e($transaction['cashier_name']) ?></td><td><?= $e($orderLabels[$transaction['order_type']] ?? $transaction['order_type']) ?></td><td class="text-end text-nowrap"><?= $money($transaction['grand_total']) ?></td><td><span class="badge text-bg-<?= $transaction['status'] === 'refunded' ? 'warning' : 'success' ?>"><?= $e($transaction['status']) ?></span></td></tr><?php endforeach; ?>
      <?php if (!$recent): ?><tr><td colspan="5" class="text-center text-muted py-4">Belum ada transaksi hari ini.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div></section>
  <aside class="col-lg-4"><div class="card mb-3"><div class="card-header d-flex justify-content-between"><strong>Paket &amp; pemakaian</strong><span class="badge text-bg-<?= $e($statusTone) ?>"><?= $e($statusLabel) ?></span></div><div class="card-body">
    <div class="fw-bold fs-5"><?= $e($tenant['plan_name']) ?></div><div class="small text-muted"><?= (float)$tenant['price_monthly'] === 0.0 ? 'Gratis' : $money($tenant['price_monthly']) . ' / bulan' ?></div>
    <div class="small text-muted mt-3">Berlaku sampai</div><strong><?= $e($tenant['subscription_ends_at'] ? date('d M Y', strtotime((string)$tenant['subscription_ends_at'])) : 'Tanpa batas waktu') ?></strong>
    <?php if ($monthLimit !== null && $monthLimit > 0): ?><div class="d-flex justify-content-between small mt-3"><span>Transaksi semua usaha</span><strong><?= number_format($accountMonthCount,0,',','.') ?> / <?= number_format($monthLimit,0,',','.') ?></strong></div><div class="progress mt-2" style="height:7px"><div class="progress-bar <?= $usagePct !== null && $usagePct >= 90 ? 'bg-warning' : 'bg-primary' ?>" style="width:<?= (int)$usagePct ?>%"></div></div><?php endif; ?>
    <a class="btn btn-outline-primary btn-sm w-100 mt-3" href="<?= $e($base) ?>/owner/billing">Lihat paket &amp; billing</a>
  </div></div><div class="card"><div class="card-header fw-semibold">Bidang usaha aktif</div><div class="list-group list-group-flush"><?php foreach ($modules as $module): ?><div class="list-group-item d-flex gap-2 align-items-center"><span class="module-dot"></span><div><strong class="d-block"><?= $e($module['name']) ?></strong><span class="small text-muted"><?= $e($module['summary']) ?></span></div></div><?php endforeach; ?><?php if (!$modules): ?><div class="list-group-item text-muted">Belum ada modul aktif.</div><?php endif; ?></div></div></aside>
</div>