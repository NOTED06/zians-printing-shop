<?php

$currentPage = basename($_SERVER['PHP_SELF']);
require_once __DIR__ . '/../notifications_helper.php';
require_once __DIR__ . '/../seen_helper.php';
$adminNotifCount = getUnreadNotifCount($conn, 'admin');

$_adminId = $_SESSION['admin_id'] ?? 0;
$_sectionMap = [
    'orders.php' => 'orders',
    'products.php' => 'products',
    'customers.php' => 'customers',
    'messages.php' => 'messages'
];
if (isset($_sectionMap[$currentPage])) {
    markSectionSeen($conn, 'admin', $_adminId, $_sectionMap[$currentPage]);
}

$_badges = getAdminBadgeCounts($conn, $_adminId);
$_orderCount = $_badges['orders'];
$_productCount = $_badges['products'];
$_customerCount = $_badges['customers'];
$_msgCount = $_badges['messages'];
?><aside class="admin-sidebar fixed inset-y-0 left-0 w-64 flex flex-col z-50" style="background:#ffffff; color:#475569; border-right:1px solid #e2e8f0;">
    
    <button onclick="toggleAdminSidebar(event)" class="sidebar-toggle-btn" id="sidebarToggleBtn" title="Toggle Sidebar">
        <svg id="sidebarToggleIcon" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="15 18 9 12 15 6"/>
        </svg>
    </button>
    
    <div class="px-6 py-5 flex items-center gap-3" style="border-bottom:1px solid #e2e8f0; position:relative;">
        <div class="h-10 w-10 rounded-lg flex items-center justify-center font-bold text-white" style="background:linear-gradient(135deg, #ff6b35, #e55a2b); box-shadow:0 4px 14px rgba(255,107,53,0.35);">Z</div>
        <div class="flex-1">
            <div class="text-sm font-semibold text-slate-900">Zian's PrintShop</div>
            <div class="text-xs" style="color:#64748b;">Management System</div>
        </div>
    </div>

    
    <nav class="flex-1 overflow-auto px-3 py-4">
        <p class="text-xs font-semibold uppercase tracking-wider px-3 mb-2" style="color:#94a3b8;">Main Menu</p>
        <ul class="space-y-1">
            <li>
                <a href="admin_dashboard.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'admin_dashboard.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'admin_dashboard.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'admin_dashboard.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="orders.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'orders.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'orders.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'orders.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>
                    <span>Orders</span>
                    <?php if ($_orderCount > 0): ?><span id="badgeOrders" class="admin-sidebar-badge"><?= $_orderCount ?></span><?php endif; ?>
                </a>
            </li>
            <li>
                <a href="products.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'products.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'products.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'products.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="8" height="8" rx="1"/><rect x="14" y="2" width="8" height="8" rx="1"/><rect x="2" y="14" width="8" height="8" rx="1"/><rect x="14" y="14" width="8" height="8" rx="1"/></svg>
                    <span>Products</span>
                    <?php if ($_productCount > 0): ?><span id="badgeProducts" class="admin-sidebar-badge" style="background:#8b5cf6;"><?= $_productCount ?></span><?php endif; ?>
                </a>
            </li>
            <li>
                <a href="customers.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'customers.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'customers.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'customers.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
                    <span>Customers</span>
                    <?php if ($_customerCount > 0): ?><span id="badgeCustomers" class="admin-sidebar-badge" style="background:#3b82f6;"><?= $_customerCount ?></span><?php endif; ?>
                </a>
            </li>
            <li>
                <a href="messages.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'messages.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'messages.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'messages.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                    <span>Messages</span>
                    <?php if ($_msgCount > 0): ?><span id="badgeMessages" class="admin-sidebar-badge" style="background:#22c55e;"><?= $_msgCount ?></span><?php endif; ?>
                </a>
            </li>
            <li>
                <a href="sales.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'sales.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'sales.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'sales.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23,6 13.5,15.5 8.5,10.5 1,18"/><polyline points="17,6 23,6 23,12"/></svg>
                    <span>Sales</span>
                </a>
            </li>
        </ul>

        <p class="text-xs font-semibold uppercase tracking-wider px-3 mt-6 mb-2" style="color:#94a3b8;">Settings</p>
        <ul class="space-y-1">
            <li>
                <a href="settings.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'settings.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'settings.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'settings.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
                    <span>Settings</span>
                </a>
            </li>
            <li>
                <a href="api_settings.php" class="relative flex items-center gap-3 px-4 py-2.5 rounded-lg text-sm transition-all hover:bg-slate-50 text-slate-600 hover:text-slate-900" style="<?= $currentPage === 'api_settings.php' ? 'background:#fff5f0; color:#ff6b35; font-weight:600;' : '' ?>">
                    <?php if ($currentPage === 'api_settings.php'): ?>
                        <span style="position:absolute; left:0; top:20%; bottom:20%; width:3.5px; background:#ff6b35; border-radius:0 4px 4px 0;"></span>
                    <?php endif; ?>
                    <svg class="h-5 w-5" style="color:<?= $currentPage === 'api_settings.php' ? '#ff6b35' : '#64748b' ?>" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/></svg>
                    <span>API Settings</span>
                </a>
            </li>
        </ul>
    </nav>

    
    <div class="px-4 pb-4" style="border-top:1px solid #e2e8f0;">
        <a href="../logout.php?role=admin" onclick="return confirm('Are you sure you want to log out?');" class="flex items-center gap-3 px-3 py-2.5 mt-3 rounded-lg text-sm hover:bg-red-50 text-red-600 transition-all">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Sign Out</span>
        </a>
        <div class="admin-profile-card mt-3 p-3 rounded-lg flex items-center gap-3" style="background:#f8fafc; border:1px solid #e2e8f0;">
            <div class="h-9 w-9 rounded-full flex items-center justify-content text-white font-semibold text-sm" style="background:#ff6b35; display:flex; align-items:center; justify-content:center;">AD</div>
            <div class="admin-profile-details text-sm">
                <div class="font-medium text-slate-900">Admin</div>
                <div class="text-xs" style="color:#64748b;"><?= htmlspecialchars($_SESSION['admin_email'] ?? 'admin@zians.com') ?></div>
            </div>
        </div>
    </div>
</aside>

<style>
    
    .admin-sidebar {
        transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), background-color 0.3s;
    }
    main {
        transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    #sidebarToggleIcon {
        transition: transform 0.3s ease;
    }

    
    .sidebar-toggle-btn {
        position: absolute;
        right: -13px;
        top: 26px;
        z-index: 100;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 50%;
        width: 26px;
        height: 26px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #64748b;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        transition: all 0.2s;
    }
    .sidebar-toggle-btn:hover {
        color: #ff6b35;
        border-color: #ff6b35;
        transform: scale(1.1);
    }

    
    body.sidebar-collapsed .admin-sidebar {
        width: 72px !important;
    }
    body.sidebar-collapsed main {
        margin-left: 72px !important;
    }
    body.sidebar-collapsed #sidebarToggleIcon {
        transform: rotate(180deg);
    }

    
    body.sidebar-collapsed .admin-sidebar .flex-1 .text-sm,
    body.sidebar-collapsed .admin-sidebar .flex-1 .text-xs,
    body.sidebar-collapsed .admin-sidebar .px-6 .flex-1,
    body.sidebar-collapsed .admin-sidebar nav p,
    body.sidebar-collapsed .admin-sidebar nav a span,
    body.sidebar-collapsed .admin-sidebar nav a .admin-sidebar-badge,
    body.sidebar-collapsed .admin-sidebar .px-4 .flex-1,
    body.sidebar-collapsed .admin-sidebar .px-4 a span {
        display: none !important;
    }

    
    body.sidebar-collapsed .admin-sidebar .px-6 {
        padding-left: 0;
        padding-right: 0;
        justify-content: center;
    }
    body.sidebar-collapsed .admin-sidebar nav {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
    body.sidebar-collapsed .admin-sidebar nav a {
        justify-content: center;
        padding-left: 0;
        padding-right: 0;
    }
    
    body.sidebar-collapsed .admin-sidebar nav a span[style*="position:absolute"] {
        left: 2px !important;
        height: 20px !important;
        top: calc(50% - 10px) !important;
        bottom: auto !important;
        width: 3px !important;
    }
    body.sidebar-collapsed .admin-sidebar .px-4 {
        padding-left: 0.5rem;
        padding-right: 0.5rem;
    }
    body.sidebar-collapsed .admin-sidebar .px-4 a {
        justify-content: center;
    }
    body.sidebar-collapsed .admin-sidebar .mt-3.p-3 {
        justify-content: center;
        padding: 0.5rem;
    }
    body.sidebar-collapsed .admin-sidebar .admin-profile-details {
        display: none !important;
    }

    .notif-bell-btn { position:relative; display:flex; align-items:center; justify-content:center; width:42px; height:42px; border-radius:50%; border:1px solid #ffdcd0; background:#fff; cursor:pointer; transition:all 0.3s cubic-bezier(0.4,0,0.2,1); box-shadow:0 2px 10px rgba(0,0,0,0.08); }
    .notif-bell-btn:hover { background:#ff6b35; border-color:#ff6b35; transform:scale(1.1); box-shadow:0 4px 20px rgba(255,107,53,0.35); }
    .notif-bell-btn:hover .notif-bell-icon { stroke:#fff; }
    .notif-bell-btn:active { transform:scale(0.95); }
    @keyframes notifShake { 0%{transform:rotate(0)} 15%{transform:rotate(12deg)} 30%{transform:rotate(-10deg)} 45%{transform:rotate(8deg)} 60%{transform:rotate(-6deg)} 75%{transform:rotate(3deg)} 100%{transform:rotate(0)} }
    .notif-bell-btn:hover .notif-bell-icon { animation:notifShake 0.6s ease-in-out; }
    .ml-64.p-8 { padding-right:5rem !important; }

    
    .admin-sidebar-badge { margin-left:auto; min-width:20px; height:20px; display:flex; align-items:center; justify-content:center; border-radius:10px; font-size:0.65rem; font-weight:800; color:#fff; background:#ff6b35; padding:0 6px; line-height:1; }

    
    .toast-container { position:fixed; top:24px; right:90px; z-index:10000; display:flex; flex-direction:column; gap:12px; pointer-events:none; }
    .toast-item { pointer-events:auto; display:flex; align-items:center; gap:14px; min-width:320px; max-width:420px; padding:16px 20px; background:#fff; border-radius:16px; box-shadow:0 8px 30px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.06); transform:translateX(120%); animation:toastSlideIn 0.4s cubic-bezier(0.34,1.56,0.64,1) forwards; position:relative; }
    .toast-item.toast-out { animation:toastSlideOut 0.3s ease-in forwards; }
    .toast-icon { flex-shrink:0; width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; }
    .toast-icon.success { background:#22c55e; }
    .toast-icon.error { background:#ef4444; }
    .toast-icon.info { background:#3b82f6; }
    .toast-body { flex:1; min-width:0; }
    .toast-title { font-size:0.9rem; font-weight:700; margin-bottom:2px; }
    .toast-title.success { color:#16a34a; }
    .toast-title.error { color:#dc2626; }
    .toast-title.info { color:#2563eb; }
    .toast-msg { font-size:0.8rem; color:#6b7280; line-height:1.3; }
    .toast-close { flex-shrink:0; background:none; border:none; cursor:pointer; color:#9ca3af; font-size:1.2rem; padding:4px; line-height:1; transition:color 0.2s; }
    .toast-close:hover { color:#4b5563; }
    @keyframes toastSlideIn { from{transform:translateX(120%); opacity:0;} to{transform:translateX(0); opacity:1;} }
    @keyframes toastSlideOut { from{transform:translateX(0); opacity:1;} to{transform:translateX(120%); opacity:0;} }

    
    .confirm-overlay { position:fixed; inset:0; background:rgba(0,0,0,0.45); backdrop-filter:blur(4px); z-index:50000; display:flex; align-items:center; justify-content:center; opacity:0; animation:confirmFadeIn 0.25s ease forwards; }
    .confirm-overlay.confirm-closing { animation:confirmFadeOut 0.2s ease forwards; }
    .confirm-box { background:#fff; border-radius:20px; padding:32px; width:100%; max-width:400px; margin:16px; box-shadow:0 20px 60px rgba(0,0,0,0.2); text-align:center; transform:scale(0.9) translateY(10px); animation:confirmPopIn 0.3s cubic-bezier(0.34,1.56,0.64,1) forwards; }
    .confirm-overlay.confirm-closing .confirm-box { animation:confirmPopOut 0.2s ease forwards; }
    .confirm-icon { width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; margin:0 auto 16px; }
    .confirm-icon.warning { background:#fef3c7; }
    .confirm-icon.danger { background:#fee2e2; }
    .confirm-icon.info { background:#dbeafe; }
    .confirm-heading { font-size:1.1rem; font-weight:700; color:#111827; margin-bottom:6px; }
    .confirm-text { font-size:0.85rem; color:#6b7280; line-height:1.5; margin-bottom:24px; }
    .confirm-btns { display:flex; gap:12px; }
    .confirm-btn { flex:1; padding:11px 16px; border-radius:12px; font-size:0.85rem; font-weight:600; border:none; cursor:pointer; transition:all 0.2s; }
    .confirm-btn:active { transform:scale(0.96); }
    .confirm-btn-cancel { background:#f3f4f6; color:#4b5563; }
    .confirm-btn-cancel:hover { background:#e5e7eb; }
    .confirm-btn-ok { color:#fff; }
    .confirm-btn-ok.danger { background:#ef4444; }
    .confirm-btn-ok.danger:hover { background:#dc2626; box-shadow:0 4px 14px rgba(239,68,68,0.35); }
    .confirm-btn-ok.warning { background:#f59e0b; }
    .confirm-btn-ok.warning:hover { background:#d97706; box-shadow:0 4px 14px rgba(245,158,11,0.35); }
    .confirm-btn-ok.info { background:#3b82f6; }
    .confirm-btn-ok.info:hover { background:#2563eb; box-shadow:0 4px 14px rgba(59,130,246,0.35); }
    .confirm-btn-ok.success { background:#ff6b35; }
    .confirm-btn-ok.success:hover { background:#e55a2b; box-shadow:0 4px 14px rgba(255,107,53,0.35); }
    @keyframes confirmFadeIn { from{opacity:0} to{opacity:1} }
    @keyframes confirmFadeOut { from{opacity:1} to{opacity:0} }
    @keyframes confirmPopIn { from{transform:scale(0.9) translateY(10px); opacity:0;} to{transform:scale(1) translateY(0); opacity:1;} }
    @keyframes confirmPopOut { from{transform:scale(1) translateY(0); opacity:1;} to{transform:scale(0.9) translateY(10px); opacity:0;} }
</style>
<div id="notifBellWrap" style="position:fixed; top:22px; right:32px; z-index:1000;">
    <button onclick="toggleNotifDropdown()" class="notif-bell-btn" title="Notifications">
        <svg class="notif-bell-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 5a2 2 0 114 0 7 7 0 014 6v3a4 4 0 002 3H4a4 4 0 002-3v-3a7 7 0 014-6"/><path d="M9 18a3.5 3.5 0 006 0"/></svg>
        <span id="notifBadge" style="position:absolute; top:-2px; right:-2px; min-width:18px; height:18px; display:<?= $adminNotifCount > 0 ? 'flex' : 'none' ?>; align-items:center; justify-content:center; border-radius:50%; font-size:10px; font-weight:800; color:#fff; background:#ff6b35; padding:0 4px; box-shadow:0 2px 8px rgba(255,107,53,0.4); border:2px solid #fff;"><?= $adminNotifCount ?></span>
    </button>
    
    <div id="notifDropdown" style="display:none; position:absolute; right:0; top:100%; margin-top:8px; width:340px; border-radius:14px; overflow:hidden; background:#ffffff; border:1px solid #e2e8f0; z-index:999; box-shadow:0 12px 40px rgba(0,0,0,0.08);">
        <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #f1f5f9;">
            <span style="font-size:0.85rem; font-weight:700; color:#0f172a;">Notifications</span>
            <button onclick="markAllNotifRead()" style="background:none; border:none; color:#ff6b35; font-size:0.7rem; font-weight:600; cursor:pointer;">Mark all read</button>
        </div>
        <div id="notifList" style="max-height:320px; overflow-y:auto;">
            <div style="padding:1.5rem; text-align:center; font-size:0.8rem; color:#64748b;">Loading...</div>
        </div>
    </div>
</div>

<div class="toast-container" id="toastContainer"></div>

<script>

if (localStorage.getItem('admin_sidebar_collapsed') === 'true') {
    document.body.classList.add('sidebar-collapsed');
}

function toggleAdminSidebar(e) {
    if (e) e.stopPropagation();
    const collapsed = document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem('admin_sidebar_collapsed', collapsed ? 'true' : 'false');
}

function showToast(title, message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        container.id = 'toastContainer';
        document.body.appendChild(container);
    }
    const icons = {
        success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>',
        error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>',
        info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    const titles = { success: 'Success!', error: 'Error!', info: 'Info' };
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.innerHTML = `
        <div class="toast-icon ${type}">${icons[type] || icons.info}</div>
        <div class="toast-body">
            <div class="toast-title ${type}">${title || titles[type]}</div>
            <div class="toast-msg">${message || ''}</div>
        </div>
        <button class="toast-close" onclick="dismissToast(this)">&times;</button>
    `;
    container.appendChild(toast);
    setTimeout(() => dismissToast(toast.querySelector('.toast-close')), 4000);
}
function dismissToast(btn) {
    const toast = btn.closest ? btn.closest('.toast-item') : btn.parentElement;
    if (!toast || toast.classList.contains('toast-out')) return;
    toast.classList.add('toast-out');
    setTimeout(() => toast.remove(), 300);
}

function showConfirm(options) {
    const { title, message, type, confirmText, cancelText, onConfirm } = Object.assign(
        { title:'Are you sure?', message:'', type:'danger', confirmText:'Confirm', cancelText:'Cancel', onConfirm:()=>{} }, options
    );
    const icons = {
        danger: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
        success: '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ff6b35" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9 12l2 2 4-4"/></svg>'
    };
    const iconBgs = { danger:'danger', warning:'warning', info:'info', success:'warning' };
    const overlay = document.createElement('div');
    overlay.className = 'confirm-overlay';
    overlay.innerHTML = `
        <div class="confirm-box">
            <div class="confirm-icon ${iconBgs[type] || 'danger'}">${icons[type] || icons.danger}</div>
            <div class="confirm-heading">${title}</div>
            <div class="confirm-text">${message}</div>
            <div class="confirm-btns">
                <button class="confirm-btn confirm-btn-cancel" id="confirmCancelBtn">${cancelText}</button>
                <button class="confirm-btn confirm-btn-ok ${type}" id="confirmOkBtn">${confirmText}</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    const close = () => { overlay.classList.add('confirm-closing'); setTimeout(() => overlay.remove(), 200); };
    overlay.querySelector('#confirmCancelBtn').onclick = close;
    overlay.querySelector('#confirmOkBtn').onclick = () => { close(); onConfirm(); };
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });
}

let notifOpen = false;

function toggleNotifDropdown() {
    const dd = document.getElementById('notifDropdown');
    notifOpen = !notifOpen;
    dd.style.display = notifOpen ? 'block' : 'none';
    if (notifOpen) loadNotifications();
}

document.addEventListener('click', function(e) {
    const wrap = document.getElementById('notifBellWrap');
    if (wrap && !wrap.contains(e.target) && notifOpen) {
        document.getElementById('notifDropdown').style.display = 'none';
        notifOpen = false;
    }
});

function loadNotifications() {
    fetch('notifications_api.php?action=list')
        .then(r => r.json())
        .then(notifs => {
            const list = document.getElementById('notifList');
            if (notifs.length === 0) {
                list.innerHTML = '<div class="px-4 py-8 text-center text-sm" style="color:#64748b;">No notifications yet</div>';
                return;
            }
            list.innerHTML = notifs.map(n => {
                const icon = n.type === 'order' ? '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>'
                    : n.type === 'message' ? '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>'
                    : '<svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>';
                const iconBg = n.type === 'order' ? '#ff6b35' : n.type === 'message' ? '#3b82f6' : '#22c55e';
                const ago = timeAgo(n.date_created);
                const unread = n.is_read == 0 ? 'background:#f8fafc;' : '';
                return `<a href="${n.link || '#'}" class="flex items-start gap-3 px-4 py-3 hover:bg-slate-50 transition-all" style="border-bottom:1px solid #f1f5f9; ${unread} text-decoration:none;">
                    <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center text-white" style="background:${iconBg};">${icon}</div>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs font-semibold text-slate-800 truncate">${escH(n.title)}</div>
                        <div class="text-xs truncate" style="color:#64748b;">${escH(n.message)}</div>
                        <div class="text-[10px] mt-0.5" style="color:#94a3b8;">${ago}</div>
                    </div>
                    ${n.is_read == 0 ? '<div class="w-2 h-2 rounded-full flex-shrink-0 mt-1.5" style="background:#ff6b35;"></div>' : ''}
                </a>`;
            }).join('');
        });
}

function markAllNotifRead() {
    fetch('notifications_api.php?action=mark_read')
        .then(r => r.json())
        .then(() => {
            lastAdminNotifCount = 0;
            document.getElementById('notifBadge').style.display = 'none';
            loadNotifications();
        });
}

let lastAdminNotifCount = null;
let adminNotificationAudioContext = null;

function unlockAdminNotificationAudio() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;
    if (!adminNotificationAudioContext) {
        adminNotificationAudioContext = new AudioContextClass();
    }
    if (adminNotificationAudioContext.state === 'suspended') {
        adminNotificationAudioContext.resume().catch(() => {});
    }
}

['pointerdown', 'keydown', 'touchstart'].forEach(eventName => {
    window.addEventListener(eventName, unlockAdminNotificationAudio, { passive: true });
});

function playNotificationSound() {
    try {
        unlockAdminNotificationAudio();
        const context = adminNotificationAudioContext;
        if (!context || context.state === 'suspended') return;
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, context.currentTime);
        oscillator.frequency.setValueAtTime(1175, context.currentTime + 0.1);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.16, context.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.35);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.35);
    } catch (error) {}
}

function pollNotifCount() {
    fetch('notifications_api.php?action=count')
        .then(r => r.json())
        .then(data => {
            const count = Number(data.count) || 0;
            const badge = document.getElementById('notifBadge');
            if (lastAdminNotifCount !== null && count > lastAdminNotifCount) {
                playNotificationSound();
            }
            lastAdminNotifCount = count;
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }).catch(() => {});
}
setInterval(pollNotifCount, 8000);

function timeAgo(dateStr) {
    const now = new Date();
    const d = new Date(dateStr);
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}
function escH(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function pollSidebarBadges() {
    fetch('poll_api.php?action=sidebar_badges')
        .then(r => r.json())
        .then(data => {
            updateBadge('badgeOrders', data.orders);
            updateBadge('badgeProducts', data.products);
            updateBadge('badgeCustomers', data.customers);
            updateBadge('badgeMessages', data.messages);
        }).catch(() => {});
}
function updateBadge(id, count) {
    let el = document.getElementById(id);
    if (count > 0) {
        if (!el) {
            
            const colors = { badgeOrders: '#ff6b35', badgeProducts: '#8b5cf6', badgeCustomers: '#3b82f6', badgeMessages: '#22c55e' };
            const links = document.querySelectorAll('.admin-sidebar nav a');
            const map = { badgeOrders: 'orders.php', badgeProducts: 'products.php', badgeCustomers: 'customers.php', badgeMessages: 'messages.php' };
            links.forEach(a => {
                if (a.getAttribute('href') === map[id]) {
                    el = document.createElement('span');
                    el.id = id;
                    el.className = 'admin-sidebar-badge';
                    el.style.background = colors[id] || '#ff6b35';
                    a.appendChild(el);
                }
            });
        }
        if (el) { el.textContent = count > 99 ? '99+' : count; el.style.display = 'flex'; }
    } else if (el) {
        el.style.display = 'none';
    }
}
setInterval(pollSidebarBadges, 8000);
</script>
