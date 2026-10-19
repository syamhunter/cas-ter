<?php
/** @var string $base @var string $csrf @var string $adminName @var string $activePage @var string $pageTitle @var string $body @var ?array $flash @var int $ticketUnread */
$e = static fn($value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$nav = [
    'dashboard' => ['Ringkasan', '/admin'],
    'tenants' => ['Pelanggan', '/admin/tenants'],
    'plans' => ['Paket', '/admin/plans'],
    'billing' => ['Billing', '/admin/billing'],
    'activity' => ['Aktivitas', '/admin/activity'],
    'support' => ['Dukungan', '/admin/support'],
    'accounts' => ['Akun Owner', '/admin/accounts'],
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= $e($pageTitle) ?> · Admin Console</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <style>
    :root { --ink:#1d302e; --muted:#687976; --line:#dce6e2; --canvas:#f3f7f5; --paper:#fff; --brand:#13786d; --brand-dark:#0d514a; --brand-soft:#e6f3ef; --warm:#c56b42; }
    * { letter-spacing:0; }
    body { background:var(--canvas); color:var(--ink); font-family:"DM Sans",sans-serif; }
    .admin-navbar { position:sticky; top:0; z-index:1020; background:rgba(255,255,255,.97); border-bottom:1px solid var(--line); box-shadow:0 3px 14px rgba(23,52,45,.05); }
    .nav-inner { min-height:66px; display:flex; align-items:center; gap:1.5rem; }
    .brand-mark { width:34px; height:34px; display:grid; place-items:center; border-radius:9px; background:var(--brand); color:#fff; font-weight:700; font-size:.8rem; }
    .brand-title { color:var(--ink); font-weight:700; line-height:1.1; }
    .brand-subtitle { color:var(--muted); font-size:.7rem; }
    .admin-links { display:flex; align-items:center; gap:.25rem; overflow-x:auto; scrollbar-width:none; }
    .admin-links::-webkit-scrollbar { display:none; }
    .admin-links a { color:#536762; text-decoration:none; white-space:nowrap; font-size:.88rem; font-weight:600; padding:.55rem .75rem; border-radius:7px; }
    .admin-links a:hover,.admin-links a:focus-visible,.admin-links a.active { color:var(--brand-dark); background:var(--brand-soft); }
    .nav-count { min-width:18px; padding:1px 5px; border-radius:10px; background:#c8493b; color:#fff; font-size:.68rem; text-align:center; }
    .admin-identity { color:var(--muted); font-size:.82rem; }
    .page-wrap { max-width:1720px; margin-inline:auto; }
    .page-heading { padding:1.6rem 0 1rem; }
    .eyebrow { color:var(--brand); font-size:.72rem; font-weight:700; text-transform:uppercase; }
    .page-heading h1 { font-size:1.65rem; font-weight:700; margin:.25rem 0; }
    .page-heading p { color:var(--muted); margin:0; font-size:.92rem; }
    .metric-card,.panel-card,.card { background:var(--paper); border:1px solid var(--line); border-radius:8px; box-shadow:0 2px 8px rgba(23,52,45,.035); }
    .metric-card { height:100%; padding:.9rem 1rem; border-left:3px solid var(--brand); }
    .metric-card[data-tone="warm"] { border-left-color:var(--warm); }
    .metric-label { color:var(--muted); font-size:.8rem; font-weight:600; }
    .metric-value { color:var(--ink); font-size:1.75rem; line-height:1.2; font-weight:700; }
    .card-header { padding:.85rem 1rem; background:#fff; border-bottom-color:var(--line); }
    .card-footer { background:#fbfdfc; border-top-color:var(--line); }
    .table { --bs-table-bg:#fff; --bs-table-border-color:#e7eeeb; color:var(--ink); }
    .table thead th { color:var(--muted); font-size:.72rem; text-transform:uppercase; font-weight:700; white-space:nowrap; background:#f7faf8; }
    .table td,.table th { padding:.72rem .8rem; vertical-align:middle; }
    .form-control,.form-select { border-color:#cfdcd7; border-radius:6px; }
    .form-control:focus,.form-select:focus { border-color:var(--brand); box-shadow:0 0 0 .2rem rgba(19,120,109,.14); }
    .btn { border-radius:6px; font-weight:600; }
    .btn-primary { --bs-btn-bg:var(--brand); --bs-btn-border-color:var(--brand); --bs-btn-hover-bg:var(--brand-dark); --bs-btn-hover-border-color:var(--brand-dark); }
    .section-anchor { scroll-margin-top:84px; }
    .package-edit { padding:.85rem; background:#f6faf8; border:1px solid var(--line); border-radius:7px; }
    .package-summary { list-style:none; cursor:pointer; display:inline-flex; align-items:center; gap:.35rem; }
    .package-summary::-webkit-details-marker { display:none; }
    .package-summary::after { content:"+"; font-size:1.05rem; }
    details[open]>.package-summary::after { content:"−"; }
    .status-dot { display:inline-block; width:7px; height:7px; margin-right:.35rem; border-radius:50%; background:currentColor; vertical-align:1px; }
    @media(max-width:767.98px) { .nav-inner { min-height:60px; flex-wrap:wrap; gap:.5rem; padding-block:.5rem; } .admin-links { order:3; width:100%; } .admin-identity { margin-left:auto; } .page-heading { padding-top:1.2rem; } .page-heading h1 { font-size:1.4rem; } }
  </style>
</head>
<body>
<nav class="admin-navbar" aria-label="Navigasi admin">
  <div class="container-fluid page-wrap nav-inner">
    <a class="d-flex align-items-center gap-2 text-decoration-none" href="<?= $e($base) ?>/admin" aria-label="Admin Console, ringkasan">
      <span class="brand-mark">AC</span><span><span class="brand-title d-block">Admin Console</span><span class="brand-subtitle">OPERATIONS</span></span>
    </a>
    <div class="admin-links" aria-label="Menu utama">
      <?php foreach ($nav as $key => [$label, $path]): ?>
        <a href="<?= $e($base . $path) ?>"<?= $activePage === $key ? ' class="active" aria-current="page"' : '' ?>><?= $e($label) ?><?php if ($key === 'support' && !empty($ticketUnread)): ?> <span class="nav-count"><?= (int) $ticketUnread ?></span><?php endif; ?></a>
      <?php endforeach; ?>
    </div>
    <div class="d-flex align-items-center gap-2 ms-auto">
      <span class="admin-identity d-none d-sm-inline"><?= $e($adminName) ?></span>
      <form method="post" action="<?= $e($base) ?>/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="btn btn-outline-secondary btn-sm" type="submit">Keluar</button></form>
    </div>
  </div>
</nav>
<main class="container-fluid page-wrap pb-5">
  <header class="page-heading"><div class="eyebrow">Administrasi layanan</div><h1><?= $e($pageTitle) ?></h1></header>
  <?php if ($flash): ?><div class="alert alert-<?= $e($flash['type'] ?? 'info') ?> py-2" role="alert"><?= $e($flash['msg'] ?? '') ?></div><?php endif; ?>
  <?= $body ?>
</main>
</body>
</html>