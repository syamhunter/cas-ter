<?php
/** @var string $base @var string $csrf @var array $owners @var string $search @var int $page @var int $pageCount @var int $total */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<div class="alert alert-warning py-2"><strong>Reset password bersifat sensitif.</strong> Gunakan password sementara, sampaikan melalui kanal pribadi, dan minta pemilik menggantinya setelah login.</div>
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><div><strong>Akun pemilik usaha</strong><div class="small text-muted"><?= number_format($total, 0, ',', '.') ?> akun</div></div>
    <form method="get" action="<?= $e($base) ?>/admin/accounts" class="d-flex gap-2"><label class="visually-hidden" for="ownerSearch">Cari akun</label><input class="form-control form-control-sm" id="ownerSearch" name="q" value="<?= $e($search) ?>" placeholder="Nama, email, atau usaha"><button class="btn btn-primary btn-sm">Cari</button></form>
  </div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>Pemilik</th><th>Email login</th><th>Usaha terkait</th><th>Status akun</th><th>Tindakan</th></tr></thead><tbody>
    <?php foreach ($owners as $owner): ?>
      <tr><td class="fw-semibold"><?= $e($owner['name']) ?></td><td><?= $e($owner['email']) ?></td><td><?= $e($owner['tenant_name'] ?: '-') ?><?= (int) $owner['tenant_count'] > 1 ? ' +' . ((int) $owner['tenant_count'] - 1) . ' usaha' : '' ?></td><td><?= (int) $owner['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></td>
        <td><details><summary class="btn btn-outline-primary btn-sm">Reset password</summary>
          <form method="post" action="<?= $e($base) ?>/admin/accounts/reset-password" class="package-edit mt-2" onsubmit="return confirm('Reset password owner ini? Sesi aktifnya akan berakhir.')">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="user_id" value="<?= (int) $owner['id'] ?>">
            <label class="form-label small" for="newPassword<?= (int) $owner['id'] ?>">Password sementara (12–72 karakter)</label>
            <input class="form-control form-control-sm mb-2" id="newPassword<?= (int) $owner['id'] ?>" name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            <label class="form-label small" for="confirmPassword<?= (int) $owner['id'] ?>">Ulangi password sementara</label>
            <input class="form-control form-control-sm mb-2" id="confirmPassword<?= (int) $owner['id'] ?>" name="password_confirmation" type="password" minlength="12" maxlength="72" autocomplete="new-password" required>
            <button class="btn btn-danger btn-sm" type="submit">Reset dan catat</button>
          </form>
        </details></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$owners): ?><tr><td colspan="5" class="text-center text-muted py-4">Akun pemilik tidak ditemukan.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-footer d-flex justify-content-between align-items-center"><span class="small">Halaman <?= $page ?> dari <?= $pageCount ?></span><div class="d-flex gap-2"><?php if ($page > 1): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/accounts?<?= $e(http_build_query(['q' => $search, 'page' => $page - 1])) ?>">Sebelumnya</a><?php endif; ?><?php if ($page < $pageCount): ?><a class="btn btn-outline-secondary btn-sm" href="<?= $e($base) ?>/admin/accounts?<?= $e(http_build_query(['q' => $search, 'page' => $page + 1])) ?>">Berikutnya</a><?php endif; ?></div></div>
</div>