<?php
/** @var string $base @var string $csrf @var string $ownerName @var array $tenant @var array $branch @var array $businesses @var string $activePage @var string $pageTitle @var string $body @var ?array $ownerFlash */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sidebarBase = $base;
$sidebarBusiness = $tenant['name'];
$sidebarBranch = $branch['name'];
$sidebarRole = 'owner';
$sidebarUser = $ownerName;
$sidebarPage = 'owner';
$sidebarActive = $activePage;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= $e($pageTitle) ?> · <?= $e($tenant['name']) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root { --owner-ink:#17243b; --owner-muted:#738096; --owner-blue:#3157d5; --owner-line:#e4e9f1; --owner-bg:#f4f6fa; }
    * { letter-spacing:0; }
    body { min-height:100vh; background:var(--owner-bg); color:var(--owner-ink); font-family:"DM Sans",sans-serif; }
    .app-shell { min-height:100vh; display:grid; grid-template-columns:250px minmax(0,1fr); align-items:stretch; transition:grid-template-columns .18s ease; }
    .app-content { min-width:0; display:flex; flex-direction:column; }
    .app-content>main { flex:1 0 auto; }
    .app-shell.sidebar-collapsed { grid-template-columns:78px minmax(0,1fr); }
    .app-sidebar { position:sticky; top:0; height:100vh; padding:20px 14px; display:flex; flex-direction:column; gap:8px; overflow-y:auto; background:#111d36; color:#e8eeff; z-index:1040; }
    .app-sidebar-brand { display:flex; align-items:center; gap:11px; min-width:0; padding:3px 5px 18px; color:inherit; text-decoration:none; }
    .app-sidebar-mark { flex:0 0 40px; width:40px; height:40px; display:grid; place-items:center; border-radius:13px; background:#4269e8; color:#fff; font-weight:800; }
    .app-sidebar-brand-copy { min-width:0; display:grid; }
    .app-sidebar-brand-copy strong,.app-sidebar-brand-copy small { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .app-sidebar-brand-copy small { color:#aab6d1; }
    .app-sidebar-caption { padding:8px 10px 4px; color:#8493b2; font-size:.68rem; font-weight:700; }
    .app-sidebar-nav { display:grid; gap:5px; }
    .app-sidebar-link { display:flex; align-items:center; gap:11px; padding:10px 11px; border-radius:8px; color:#c7d1e7; font-size:.9rem; font-weight:600; text-decoration:none; transition:background .15s ease,color .15s ease; }
    .app-sidebar-link:hover { background:#ffffff12; color:#fff; }
    .app-sidebar-link.active { background:#3157d5; color:#fff; }
    .app-sidebar-icon { flex:0 0 25px; width:25px; height:25px; display:grid; place-items:center; border:1px solid #ffffff20; border-radius:7px; font-size:.72rem; font-weight:800; }
    .app-sidebar-icon svg { width:16px; height:16px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
    .app-sidebar-footer { display:grid; gap:7px; margin-top:auto; padding:13px 10px 3px; border-top:1px solid #ffffff1c; color:#aab6d1; font-size:.75rem; }
    .app-sidebar-user { display:grid; overflow:hidden; color:#fff; font-weight:650; text-overflow:ellipsis; white-space:nowrap; }
    .app-sidebar-user small { color:#aab6d1; font-size:.72rem; font-weight:400; }
    .app-sidebar-action { display:flex; align-items:center; gap:11px; width:100%; padding:9px 10px; border:0; border-radius:8px; background:transparent; color:#c7d1e7; font:inherit; font-size:.86rem; font-weight:600; text-align:left; text-decoration:none; cursor:pointer; }
    .app-sidebar-action:hover { background:#ffffff18; color:#fff; }
    .app-sidebar-action .app-sidebar-icon { border:0; }
    .app-sidebar-logout { margin:0; }
    .page-wrap { width:100%; max-width:1480px; margin-inline:auto; }
    .brand-mark { width:36px; height:36px; display:grid; place-items:center; border-radius:9px; background:var(--owner-blue); color:#fff; font-weight:800; }
    .page-heading { padding:1.5rem 0 1rem; }
    .page-heading .eyebrow { color:var(--owner-blue); font-size:.7rem; font-weight:700; text-transform:uppercase; }
    .page-heading h1 { font-size:1.55rem; font-weight:700; margin:.2rem 0; }
    .page-heading p { color:var(--owner-muted); margin:0; font-size:.9rem; }
    .owner-card,.metric-card { min-width:0; border:1px solid var(--owner-line); border-radius:8px; background:#fff; box-shadow:0 2px 8px rgba(23,36,59,.035); }
    .metric-card { height:100%; padding:1rem; }
    .metric-label { color:var(--owner-muted); font-size:.8rem; }
    .metric-value { color:var(--owner-ink); font-size:1.45rem; font-weight:700; }
    .card { border-color:var(--owner-line); border-radius:8px; box-shadow:0 2px 8px rgba(23,36,59,.035); }
    .card-header { background:#fff; border-bottom-color:var(--owner-line); font-weight:650; }
    .table { --bs-table-bg:#fff; --bs-table-border-color:#e8edf3; color:var(--owner-ink); }
    .table thead th { background:#f8f9fc; color:var(--owner-muted); font-size:.72rem; font-weight:700; text-transform:uppercase; white-space:nowrap; }
    .form-control,.form-select { border-color:#cfd7e4; border-radius:6px; }
    .form-control:focus,.form-select:focus { border-color:var(--owner-blue); box-shadow:0 0 0 .2rem rgba(49,87,213,.13); }
    .btn { border-radius:6px; font-weight:600; }
    .btn-primary { --bs-btn-bg:var(--owner-blue); --bs-btn-border-color:var(--owner-blue); --bs-btn-hover-bg:#2545b3; --bs-btn-hover-border-color:#2545b3; }
    .module-card { border:1px solid #e8ecf4; border-radius:8px; background:#fff; }
    .module-dot { width:8px; height:8px; display:inline-block; border-radius:50%; background:#31aa75; }
    .business-module-choice { height:100%; border:1px solid var(--owner-line); border-radius:7px; background:#fff; }
    .business-module-choice:has(input:checked) { border-color:#aab9ef; background:#f5f7ff; }
    .package-summary { list-style:none; cursor:pointer; }
    .package-summary::-webkit-details-marker { display:none; }
    .section-anchor { scroll-margin-top:80px; }
    .owner-footer { color:var(--owner-muted); border-top:1px solid var(--owner-line); background:#fff; }
    @media(max-width:991.98px) {
      .app-shell,.app-shell.sidebar-collapsed { display:flex; flex-direction:column; }
      .app-content { width:100%; }
      .app-sidebar { position:sticky; top:0; width:100%; height:auto; padding:8px 12px; overflow:hidden; }
      .app-sidebar-brand,.app-sidebar-caption,.app-sidebar-footer { display:none; }
      .app-sidebar-nav { display:flex; gap:6px; overflow-x:auto; scrollbar-width:thin; }
      .app-sidebar-link { flex:0 0 auto; padding:8px 11px; font-size:.82rem; }
      .app-sidebar-icon { display:none; }
    }
    @media(max-width:575.98px) { .owner-main { padding-inline:14px; } .page-heading h1 { font-size:1.35rem; } }
  </style>
</head>
<body>
<div class="app-shell">
  <?php require __DIR__ . '/../partials/sidebar.php'; ?>
  <div class="app-content">
    <main class="container-fluid page-wrap owner-main px-3 px-lg-4 pb-4">
      <header class="page-heading d-flex flex-wrap justify-content-between align-items-end gap-3">
        <div><div class="eyebrow"><?= $e($tenant['name']) ?> · <?= $e($branch['name']) ?> · <?= $e($ownerName) ?></div><h1><?= $e($pageTitle) ?></h1></div>
        <?php if (count($businesses) > 1): ?>
          <form method="post" action="<?= $e($base) ?>/owner/business/switch" class="d-flex align-items-center gap-2 m-0">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <label class="small text-muted" for="ownerBusinessSwitch">Usaha aktif</label>
            <select class="form-select form-select-sm" id="ownerBusinessSwitch" name="tenant_id" aria-label="Pilih usaha aktif" onchange="this.form.requestSubmit()">
              <?php foreach ($businesses as $business): ?><option value="<?= (int) $business['id'] ?>"<?= (int) $business['id'] === (int) $tenant['id'] ? ' selected' : '' ?>><?= $e($business['name']) ?></option><?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
      </header>
      <?php if ($ownerFlash): ?><div class="alert alert-<?= $e($ownerFlash['type'] ?? 'info') ?> py-2" role="status"><?= $e($ownerFlash['message'] ?? '') ?></div><?php endif; ?>
      <?= $body ?>
    </main>
    <footer class="owner-footer mt-4"><div class="container-fluid page-wrap d-flex flex-wrap justify-content-between gap-2 py-3 small"><span><?= $e($tenant['name']) ?> · <?= $e($branch['name']) ?></span><span>Dashboard pemilik</span></div></footer>
  </div>
</div>
</body>
</html>