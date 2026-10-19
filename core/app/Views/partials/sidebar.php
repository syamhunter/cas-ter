<?php
declare(strict_types=1);

$sidebarItems = [
    ['label' => 'Kasir', 'href' => $sidebarBase . '/pos', 'key' => 'pos'],
    ['label' => 'Transaksi', 'href' => $sidebarBase . '/transactions', 'key' => 'transactions'],
    ['label' => 'Laporan', 'href' => $sidebarBase . '/reports', 'key' => 'reports'],
];
$sidebarSupportUnread = (int) ($supportUnread ?? 0);
if ($sidebarRole === 'owner') {
  $sidebarItems = array_merge([
    ['label' => 'Ringkasan usaha', 'href' => $sidebarBase . '/owner', 'key' => 'dashboard'],
    ['label' => 'Bantuan', 'href' => $sidebarBase . '/owner/support', 'key' => 'support'],
    ['label' => 'Usaha & profil', 'href' => $sidebarBase . '/owner/businesses', 'key' => 'businesses'],
    ['label' => 'Tim & kasir', 'href' => $sidebarBase . '/owner/team', 'key' => 'team'],
    ['label' => 'Paket & billing', 'href' => $sidebarBase . '/owner/billing', 'key' => 'billing'],
  ], $sidebarItems);
}
if (in_array($sidebarRole, ['owner', 'manager'], true)) {
    $sidebarItems[] = ['label' => 'Manajemen toko', 'href' => $sidebarBase . '/store', 'key' => 'store'];
    $sidebarItems[] = ['label' => 'Pengeluaran', 'href' => $sidebarBase . '/expenses', 'key' => 'expenses'];
}
$sidebarItems[] = ['label' => 'Shift & kas', 'href' => $sidebarPage === 'operations' ? '#shift' : $sidebarBase . '/operations#shift', 'key' => 'shift'];
if ($sidebarPage !== 'operations' && in_array($sidebarRole, ['owner', 'manager'], true)) {
    $sidebarItems[] = ['label' => 'Pembelian & stok', 'href' => $sidebarBase . '/operations#purchases', 'key' => 'inventory'];
}
if ($sidebarPage === 'store') {
    $sidebarItems[] = ['label' => 'Katalog', 'href' => '#catalog', 'key' => 'catalog'];
    $sidebarItems[] = ['label' => 'Stok & batch', 'href' => '#inventory', 'key' => 'stock'];
    $sidebarItems[] = ['label' => 'Pelanggan', 'href' => '#customers', 'key' => 'customers'];
    if (!empty($sidebarHasOrders)) {
        $sidebarItems[] = ['label' => 'Antrean layanan', 'href' => '#orders', 'key' => 'orders'];
    }
}
if ($sidebarPage === 'operations') {
    if (in_array($sidebarRole, ['owner', 'manager'], true)) {
        $sidebarItems[] = ['label' => 'Pemasok & pembelian', 'href' => '#purchases', 'key' => 'inventory'];
        $sidebarItems[] = ['label' => 'Stok opname', 'href' => '#counts', 'key' => 'counts'];
        $sidebarItems[] = ['label' => 'Transfer cabang', 'href' => '#transfers', 'key' => 'transfers'];
    }
}
$sidebarSettingsHref = $sidebarRole === 'owner'
  ? $sidebarBase . '/owner/businesses'
    : $sidebarBase . '/store';
$sidebarSettingsLabel = $sidebarRole === 'owner' ? 'Pengaturan aplikasi' : 'Pengaturan toko';
$sidebarIcons = [
    'dashboard' => '<svg viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="2"/><rect x="13" y="3" width="8" height="5" rx="2"/><rect x="13" y="10" width="8" height="11" rx="2"/><rect x="3" y="13" width="8" height="8" rx="2"/></svg>',
    'pos' => '<svg viewBox="0 0 24 24"><path d="M4 7h16l-1 13H5L4 7Z"/><path d="M9 9V6a3 3 0 0 1 6 0v3"/></svg>',
    'transactions' => '<svg viewBox="0 0 24 24"><path d="M7 3h10l3 3v15H4V3h3Z"/><path d="M8 11h8M8 15h8M8 7h3"/></svg>',
    'reports' => '<svg viewBox="0 0 24 24"><path d="M4 19V5M4 19h17"/><path d="m7 15 4-4 3 2 5-6"/></svg>',
    'expenses' => '<svg viewBox="0 0 24 24"><path d="M4 6h16v14H4zM8 3v6M16 3v6M4 11h16"/><path d="M8 15h3"/></svg>',
    'store' => '<svg viewBox="0 0 24 24"><path d="M3 10v10h18V10"/><path d="m3 10 2-6h14l2 6"/><path d="M3 10a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M9 20v-5h6v5"/></svg>',
    'shift' => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>',
    'inventory' => '<svg viewBox="0 0 24 24"><path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h4"/></svg>',
    'profile' => '<svg viewBox="0 0 24 24"><path d="M4 21V5l8-3 8 3v16"/><path d="M9 21v-6h6v6M8 8h.01M12 8h.01M16 8h.01"/></svg>',
    'cashiers' => '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M16 4a4 4 0 0 1 0 8M18 14a6 6 0 0 1 4 6"/></svg>',
    'businesses' => '<svg viewBox="0 0 24 24"><path d="M3 21V6l9-3 9 3v15M8 21v-6h8v6M7 9h.01M12 9h.01M17 9h.01"/></svg>',
    'team' => '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M17 8h5M19.5 5.5v5"/></svg>',
    'billing' => '<svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg>',
      'support' => '<svg viewBox="0 0 24 24"><path d="M4 5h16v12H8l-4 4V5Z"/><path d="M8 9h8M8 13h5"/></svg>',
    'catalog' => '<svg viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13"/><path d="M3 6h.01M3 12h.01M3 18h.01"/></svg>',
    'stock' => '<svg viewBox="0 0 24 24"><path d="m12 3 9 5v8l-9 5-9-5V8l9-5Z"/><path d="m3 8 9 5 9-5M12 13v8"/></svg>',
    'customers' => '<svg viewBox="0 0 24 24"><circle cx="9" cy="8" r="4"/><path d="M2 21a7 7 0 0 1 14 0M17 11a4 4 0 1 0 0-8M18 14a6 6 0 0 1 4 6"/></svg>',
    'orders' => '<svg viewBox="0 0 24 24"><path d="M7 3h10l3 3v15H4V3h3ZM8 11h8M8 15h8M8 7h3"/></svg>',
    'counts' => '<svg viewBox="0 0 24 24"><path d="m4 12 4 4L20 4"/><path d="M20 12v7H4V5h11"/></svg>',
    'transfers' => '<svg viewBox="0 0 24 24"><path d="M4 7h15l-3-3M20 17H5l3 3"/></svg>',
    'settings' => '<svg viewBox="0 0 24 24"><path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8Z"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.6a8 8 0 0 1-1.6.9l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.6-.9l-1.7.6-1.4-2.4L7.5 15a8 8 0 0 1 0-1.9L6.1 12l1.4-2.4 1.7.6a8 8 0 0 1 1.6-.9l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.6.9l1.7-.6 1.4 2.4-1.4 1.1a8 8 0 0 1-.1 1.9Z"/></svg>',
    'logout' => '<svg viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3"/><path d="M12 3h7a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-7"/></svg>',
    'toggle' => '<svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="16" rx="3"/><path d="M9 4v16"/></svg>',
];
?>
<aside class="app-sidebar" aria-label="Navigasi utama">
  <div class="app-sidebar-top">
    <a class="app-sidebar-brand" href="<?= $e($sidebarRole === 'owner' ? $sidebarBase . '/owner' : $sidebarBase . '/pos') ?>">
      <span class="app-sidebar-mark" aria-hidden="true">K</span>
      <span class="app-sidebar-brand-copy"><strong><?= $e($sidebarBusiness) ?></strong><small><?= $e($sidebarBranch) ?></small></span>
    </a>
    <button class="app-sidebar-toggle" type="button" aria-label="Ciutkan sidebar" aria-expanded="true" title="Ciutkan sidebar">
      <?= $sidebarIcons['toggle'] ?>
    </button>
  </div>
  <div class="app-sidebar-caption">MENU UTAMA</div>
  <nav class="app-sidebar-nav">
    <?php foreach ($sidebarItems as $item): ?>
      <a class="app-sidebar-link<?= $sidebarActive === $item['key'] ? ' active' : '' ?>" href="<?= $e($item['href']) ?>" title="<?= $e($item['label']) ?>"<?= $sidebarActive === $item['key'] ? ' aria-current="page"' : '' ?>>
        <span class="app-sidebar-icon" aria-hidden="true"><?= $sidebarIcons[$item['key']] ?? $sidebarIcons['dashboard'] ?></span>
        <span><?= $e($item['label']) ?><?= $item['key'] === 'support' && $sidebarSupportUnread > 0 ? ' <span class="badge rounded-pill text-bg-danger">' . number_format($sidebarSupportUnread, 0, ',', '.') . '</span>' : '' ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="app-sidebar-footer">
    <div class="app-sidebar-user"><?= $e($sidebarUser) ?><small><?= $e(ucfirst($sidebarRole)) ?></small></div>
    <?php if (in_array($sidebarRole, ['owner', 'manager'], true)): ?>
      <a class="app-sidebar-action" href="<?= $e($sidebarSettingsHref) ?>" title="<?= $e($sidebarSettingsLabel) ?>" aria-label="<?= $e($sidebarSettingsLabel) ?>">
        <span class="app-sidebar-icon" aria-hidden="true"><?= $sidebarIcons['settings'] ?></span><span class="app-sidebar-action-label"><?= $e($sidebarSettingsLabel) ?></span>
      </a>
    <?php endif; ?>
    <form method="post" action="<?= $e($sidebarBase) ?>/logout" class="app-sidebar-logout">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button class="app-sidebar-action" type="submit" title="Keluar" aria-label="Keluar">
        <span class="app-sidebar-icon" aria-hidden="true"><?= $sidebarIcons['logout'] ?></span><span class="app-sidebar-action-label">Keluar</span>
      </button>
    </form>
  </div>
</aside>
<style>
  .app-shell { transition: grid-template-columns .18s ease; }
  .app-sidebar-top { display:flex; align-items:center; justify-content:space-between; gap:8px; min-width:0; }
  .app-sidebar-toggle { flex:0 0 36px; width:36px; height:36px; display:grid; place-items:center; padding:0; border:1px solid #ffffff24; border-radius:10px; background:#ffffff0a; color:#dce5f8; cursor:pointer; }
  .app-sidebar-toggle:hover, .app-sidebar-action:hover { background:#ffffff18; color:#fff; }
  .app-sidebar-toggle svg { width:17px; height:17px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
  .app-sidebar-footer { gap:7px; }
  .app-sidebar-user { display:grid; }
  .app-sidebar-user small { color:#aab6d1; font-size:.72rem; font-weight:400; }
  .app-sidebar-action { display:flex; align-items:center; gap:11px; width:100%; padding:9px 10px; border:0; border-radius:10px; background:transparent; color:#c7d1e7; font:inherit; font-size:.86rem; font-weight:600; text-align:left; text-decoration:none; cursor:pointer; }
  .app-sidebar-action .app-sidebar-icon { border:0; }
  .app-sidebar-logout { margin:0; }
  .app-shell.sidebar-collapsed { grid-template-columns:78px minmax(0, 1fr); }
  .sidebar-collapsed .app-sidebar { padding-right:10px; padding-left:10px; }
  .sidebar-collapsed .app-sidebar-top { justify-content:center; }
  .sidebar-collapsed .app-sidebar-brand-copy, .sidebar-collapsed .app-sidebar-caption,
  .sidebar-collapsed .app-sidebar-link > span:last-child, .sidebar-collapsed .app-sidebar-user,
  .sidebar-collapsed .app-sidebar-action-label { display:none; }
  .sidebar-collapsed .app-sidebar-brand { padding:3px 0 18px; }
  .sidebar-collapsed .app-sidebar-toggle { flex-basis:34px; width:34px; }
  .sidebar-collapsed .app-sidebar-link, .sidebar-collapsed .app-sidebar-action { justify-content:center; padding:9px 7px; }
  .sidebar-collapsed .app-sidebar-icon { flex-basis:25px; }
  .sidebar-collapsed .app-sidebar-footer { align-items:center; padding-right:0; padding-left:0; }
  @media(max-width:991.98px) {
    .app-shell.sidebar-collapsed { display:flex; }
    .app-sidebar-top { justify-content:flex-end; }
    .app-sidebar-brand, .app-sidebar-caption, .app-sidebar-user { display:none; }
    .app-sidebar-toggle { position:absolute; top:8px; right:12px; }
    .app-sidebar-nav { padding-right:42px; }
    .app-sidebar-footer { display:flex; justify-content:flex-end; align-items:center; margin-top:6px; padding:5px 0 0; border-top:1px solid #ffffff1c; }
    .app-sidebar-logout, .app-sidebar-action { width:auto; }
    .sidebar-collapsed .app-sidebar-link > span:last-child,
    .sidebar-collapsed .app-sidebar-action-label { display:none; }
    .sidebar-collapsed .app-sidebar-link, .sidebar-collapsed .app-sidebar-action { justify-content:center; }
  }
</style>
<script>
(function () {
  'use strict';
  const sidebar = document.querySelector('.app-sidebar');
  const shell = sidebar && sidebar.closest('.app-shell');
  const toggle = sidebar && sidebar.querySelector('.app-sidebar-toggle');
  if (!shell || !toggle) return;
  const storageKey = 'pos-sidebar-collapsed';
  let collapsed = false;
  try {
    collapsed = window.localStorage.getItem(storageKey) === '1';
  } catch (error) {
    collapsed = false;
  }
  function applyState() {
    shell.classList.toggle('sidebar-collapsed', collapsed);
    toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    toggle.setAttribute('aria-label', collapsed ? 'Buka sidebar' : 'Ciutkan sidebar');
    toggle.title = collapsed ? 'Buka sidebar' : 'Ciutkan sidebar';
  }
  toggle.addEventListener('click', function () {
    collapsed = !collapsed;
    applyState();
    try {
      window.localStorage.setItem(storageKey, collapsed ? '1' : '0');
    } catch (error) {
      return;
    }
  });
  applyState();
})();
</script>
