<?php

$currentPage = basename($_SERVER['PHP_SELF']);
$customerName = $_SESSION['username'] ?? 'Customer';
$customerEmail = $_SESSION['email'] ?? '';

require_once __DIR__ . '/../notifications_helper.php';
require_once __DIR__ . '/../seen_helper.php';

$_custId = $_SESSION['user_id'] ?? 0;

$_custSectionMap = [
    'customer_dashboard.php' => 'products',
    'customer_orders.php'    => 'orders',
    'customer_messages.php'  => 'messages'
];
if (isset($conn) && $_custId && isset($_custSectionMap[$currentPage])) {
    markSectionSeen($conn, 'customer', $_custId, $_custSectionMap[$currentPage]);
}

$_custBadges = ['products' => 0, 'orders' => 0, 'messages' => 0];
if (isset($conn) && $_custId) {
    $_custBadges = getCustomerBadgeCounts($conn, $_custId);
}
$productsBadge = $_custBadges['products'];
$ordersBadge   = $_custBadges['orders'];
$msgCount      = $_custBadges['messages'];

$custNotifCount = 0;
if (isset($conn) && $_custId) {
    $custNotifCount = getUnreadNotifCount($conn, 'customer', $_custId);
}

$cartCount = 0;
if (isset($conn) && $_custId) {
    $cartCount = (int)$conn->query("SELECT COUNT(*) as c FROM orders WHERE customer_id = $_custId AND status = 'In Cart'")->fetch_assoc()['c'];
}
?>

<nav class="customer-navbar" style="position:sticky; top:0; z-index:100; display:flex; align-items:center; justify-content:space-between; padding:18px 40px; background:#fff; border-bottom:1px solid #eaeaea;">
    <a href="customer_dashboard.php" class="logo" style="display:inline-block; border: 2.2px solid #000; padding: 4px 10px; text-decoration:none;">
        <div style="font-family: 'Impact', 'Arial Black', sans-serif; font-size: 19px; font-weight: 900; color: #000; line-height: 0.9; text-transform: uppercase; letter-spacing: 0.04em;">
            ZIAN'S<br>PRINTSHOP
        </div>
    </a>

    <div class="nav-links" style="display:flex; gap:32px; align-items:center;">
        <a href="customer_dashboard.php" style="font-size:12px; font-weight:700; color:#000; letter-spacing:0.06em; text-decoration:none; padding-bottom:4px; border-bottom:2px solid <?= $currentPage === 'customer_dashboard.php' ? '#000' : 'transparent' ?>; text-transform:uppercase;">Home</a>
        <a href="customer_shop.php" style="font-size:12px; font-weight:700; color:#000; letter-spacing:0.06em; text-decoration:none; padding-bottom:4px; border-bottom:2px solid <?= $currentPage === 'customer_shop.php' ? '#000' : 'transparent' ?>; text-transform:uppercase;">Shop</a>
        <div class="nav-dropdown-wrap">
            <a href="customer_orders.php" style="font-size:12px; font-weight:700; color:#000; letter-spacing:0.06em; text-decoration:none; padding-bottom:4px; border-bottom:2px solid <?= ($currentPage === 'customer_orders.php' || $currentPage === 'customer_messages.php') ? '#000' : 'transparent' ?>; text-transform:uppercase; display:flex; align-items:center; gap:4px;">
                <?= $currentPage === 'customer_orders.php' ? 'My Orders' : ($currentPage === 'customer_messages.php' ? 'Messages' : 'Pages') ?>
                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </a>
            <div class="nav-dropdown-menu">
                <a href="customer_orders.php" class="nav-dropdown-item">My Orders</a>
                <a href="customer_messages.php" class="nav-dropdown-item">Messages</a>
            </div>
        </div>
    </div>

    <div class="nav-right" style="display:flex; align-items:center; gap:12px;">
        
        <div class="search-box">
            <svg width="14" height="14" fill="none" stroke="#666" stroke-width="2.5" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input type="text" id="navbarSearchInput" placeholder="Search..." oninput="handleNavbarSearch(this.value)">
        </div>

        
        <div id="custNotifBellWrap" style="position:relative;">
            <button onclick="toggleCustNotif(event)" class="nav-icon-btn" style="width:34px; height:34px; border:none; background:transparent; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#000; position:relative;" title="Notifications">
                <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/>
                </svg>
                <span id="custNotifBadge" style="position:absolute; top:2px; right:2px; min-width:14px; height:14px; display:<?= $custNotifCount > 0 ? 'flex' : 'none' ?>; align-items:center; justify-content:center; border-radius:50%; font-size:8px; font-weight:800; color:#fff; background:#ff6b35; padding:0 3px;"><?= $custNotifCount ?></span>
            </button>
            <div id="custNotifDropdown" class="notif-dropdown">
                <div class="notif-dropdown-header">
                    <span>Notifications</span>
                    <button onclick="markAllCustNotifRead()">Mark all read</button>
                </div>
                <div id="custNotifList" class="notif-dropdown-list">
                    <div class="notif-loading">Loading...</div>
                </div>
            </div>
        </div>

        
        <div style="position:relative;">
            <button onclick="toggleProfileDropdown(event)" class="nav-icon-btn profile-avatar" style="width:34px; height:34px; border:none; background:#ff6b35; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#fff; font-size:14px; font-weight:700; border-radius:50%;" title="Profile">
                <?= strtoupper(substr($customerName, 0, 1)) ?>
            </button>
            <div id="navbarProfileDropdown" class="profile-dropdown">
                <div class="profile-dropdown-user">
                    <div class="profile-avatar large"><?= strtoupper(substr($customerName, 0, 1)) ?></div>
                    <div class="profile-user-details">
                        <div class="profile-name"><?= htmlspecialchars($customerName) ?></div>
                        <div class="profile-email"><?= htmlspecialchars($customerEmail) ?></div>
                    </div>
                </div>
                <div class="profile-dropdown-divider"></div>
                <ul class="profile-dropdown-links">
                    <li><a href="customer_dashboard.php">Browse Products</a></li>
                    <li><a href="customer_orders.php">My Orders</a></li>
                    <li><a href="customer_messages.php">Messages</a></li>
                    <li><a href="customer_dashboard.php?settings=1">Profile Settings</a></li>
                </ul>
                <div class="profile-dropdown-divider"></div>
                <a href="../logout.php?role=customer" onclick="return confirm('Are you sure you want to log out?');" class="profile-logout-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Sign Out
                </a>
            </div>
        </div>

        
        <button class="navbar-mobile-toggle" onclick="toggleMobileNavbarMenu(event)" title="Toggle Menu" style="display:none;">
            <span></span><span></span><span></span>
        </button>
    </div>
</nav>

<div class="toast-container" id="toastContainer"></div>

<script>

function handleNavbarSearch(query) {
    const q = String(query || '').toLowerCase().trim();
    const cards = document.querySelectorAll('.product-card');
    if (!cards || cards.length === 0) return;

    let visibleFavorites = 0;
    cards.forEach(card => {
        const productName = card.querySelector('h3')?.textContent.toLowerCase() || '';
        const productDesc = card.querySelector('.product-service-type')?.textContent.toLowerCase() || '';
        const categoryTag = card.querySelector('.product-category-tag')?.textContent.toLowerCase() || '';
        const matches = !q || productName.includes(q) || productDesc.includes(q) || categoryTag.includes(q);
        card.style.display = matches ? '' : 'none';
        if (matches && card.closest('.favorites-section')) visibleFavorites++;
    });

    const favoritesSection = document.querySelector('.favorites-section');
    if (favoritesSection) favoritesSection.style.display = !q || visibleFavorites > 0 ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    const searchInput = document.getElementById('navbarSearchInput');
    if (searchInput) {
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                const q = this.value.trim();
                const isDashboard = <?= $currentPage === 'customer_dashboard.php' ? 'true' : 'false' ?>;
                if (!isDashboard && q) {
                    window.location.href = 'customer_dashboard.php?search=' + encodeURIComponent(q);
                }
            }
        });
        
        
        const urlParams = new URLSearchParams(window.location.search);
        const searchVal = urlParams.get('search');
        if (searchVal) {
            searchInput.value = searchVal;
            
            setTimeout(() => {
                handleNavbarSearch(searchVal);
            }, 100);
            searchInput.focus();
        }
    }
});

let profileDropdownOpen = false;
function toggleProfileDropdown(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('navbarProfileDropdown');
    profileDropdownOpen = !profileDropdownOpen;
    dropdown.classList.toggle('show', profileDropdownOpen);
    
    
    if (custNotifOpen) {
        document.getElementById('custNotifDropdown').style.display = 'none';
        custNotifOpen = false;
    }
}

let mobileMenuOpen = false;
function toggleMobileNavbarMenu(event) {
    event.stopPropagation();
    const menu = document.querySelector('.nav-links');
    const toggle = document.querySelector('.navbar-mobile-toggle');
    mobileMenuOpen = !mobileMenuOpen;
    menu.classList.toggle('show', mobileMenuOpen);
    toggle.classList.toggle('active', mobileMenuOpen);
}

document.addEventListener('click', function(e) {
    
    const profileDropdown = document.getElementById('navbarProfileDropdown');
    const profileBtn = document.querySelector('.profile-avatar');
    if (profileDropdown && profileBtn && !profileDropdown.contains(e.target) && !profileBtn.contains(e.target) && profileDropdownOpen) {
        profileDropdown.classList.remove('show');
        profileDropdownOpen = false;
    }

    
    const notifDropdown = document.getElementById('custNotifDropdown');
    const notifWrap = document.getElementById('custNotifBellWrap');
    if (notifDropdown && notifWrap && !notifWrap.contains(e.target) && custNotifOpen) {
        notifDropdown.style.display = 'none';
        custNotifOpen = false;
    }
    
    
    const navMenu = document.querySelector('.nav-links');
    const mobileToggle = document.querySelector('.navbar-mobile-toggle');
    if (navMenu && mobileToggle && !navMenu.contains(e.target) && !mobileToggle.contains(e.target) && mobileMenuOpen) {
        navMenu.classList.remove('show');
        mobileToggle.classList.remove('active');
        mobileMenuOpen = false;
    }
});

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

let custNotifOpen = false;
function toggleCustNotif(event) {
    if (event) event.stopPropagation();
    const dd = document.getElementById('custNotifDropdown');
    custNotifOpen = !custNotifOpen;
    dd.style.display = custNotifOpen ? 'block' : 'none';
    if (custNotifOpen) loadCustNotifications();
    
    
    if (profileDropdownOpen) {
        document.getElementById('navbarProfileDropdown').classList.remove('show');
        profileDropdownOpen = false;
    }
}

function loadCustNotifications() {
    fetch('notifications_api.php?action=list')
        .then(r => r.json())
        .then(notifs => {
            const list = document.getElementById('custNotifList');
            if (notifs.length === 0) {
                list.innerHTML = '<div style="padding:2rem; text-align:center; color:hsl(215 16% 47%); font-size:0.8rem;">No notifications yet</div>';
                return;
            }
            list.innerHTML = notifs.map(n => {
                const iconColor = n.type === 'status' ? '#22c55e' : n.type === 'message' ? '#3b82f6' : '#ff6b35';
                const icon = n.type === 'status'
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>'
                    : n.type === 'message'
                    ? '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>'
                    : '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>';
                const ago = ntTimeAgo(n.date_created);
                const unreadBg = n.is_read == 0 ? `background:hsl(210 20% 97%);` : '';
                return `<a href="${n.link || '#'}" style="display:flex; align-items:flex-start; gap:10px; padding:10px 16px; border-bottom:1px solid hsl(214 32% 93%); text-decoration:none; transition:background 0.2s; ${unreadBg}" onmouseover="this.style.background='hsl(210 20% 96%)'" onmouseout="this.style.background='${n.is_read == 0 ? 'hsl(210 20% 97%)' : 'transparent'}'">
                    <div style="flex-shrink:0; width:28px; height:28px; border-radius:50%; background:${iconColor}; display:flex; align-items:center; justify-content:center;">${icon}</div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.75rem; font-weight:700; color:hsl(222 47% 11%); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${ntEsc(n.title)}</div>
                        <div style="font-size:0.7rem; color:hsl(215 16% 47%); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${ntEsc(n.message)}</div>
                        <div style="font-size:0.6rem; color:hsl(215 16% 60%); margin-top:2px;">${ago}</div>
                    </div>
                    ${n.is_read == 0 ? '<div style="width:7px; height:7px; border-radius:50%; background:#ff6b35; flex-shrink:0; margin-top:6px;"></div>' : ''}
                </a>`;
            }).join('');
        });
}

function markAllCustNotifRead() {
    fetch('notifications_api.php?action=mark_read')
        .then(r => r.json())
        .then(() => {
            lastCustomerNotifCount = 0;
            document.getElementById('custNotifBadge').style.display = 'none';
            loadCustNotifications();
        });
}

let lastCustomerNotifCount = null;
let customerNotificationAudioContext = null;

function unlockCustomerNotificationAudio() {
    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
    if (!AudioContextClass) return;
    if (!customerNotificationAudioContext) {
        customerNotificationAudioContext = new AudioContextClass();
    }
    if (customerNotificationAudioContext.state === 'suspended') {
        customerNotificationAudioContext.resume().catch(() => {});
    }
}

['pointerdown', 'keydown', 'touchstart'].forEach(eventName => {
    window.addEventListener(eventName, unlockCustomerNotificationAudio, { passive: true });
});

function playNotificationSound() {
    try {
        unlockCustomerNotificationAudio();
        const context = customerNotificationAudioContext;
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

function pollCustNotifCount() {
    fetch('notifications_api.php?action=count')
        .then(r => r.json())
        .then(data => {
            const count = Number(data.count) || 0;
            const badge = document.getElementById('custNotifBadge');
            if (lastCustomerNotifCount !== null && count > lastCustomerNotifCount) {
                playNotificationSound();
            }
            lastCustomerNotifCount = count;
            if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'flex';
            } else {
                badge.style.display = 'none';
            }
        }).catch(() => {});
}
setInterval(pollCustNotifCount, 8000);

function ntTimeAgo(dateStr) {
    const now = new Date();
    const d = new Date(dateStr);
    const diff = Math.floor((now - d) / 1000);
    if (diff < 60) return 'Just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}
function ntEsc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

function pollCustSidebarBadges() {
    fetch('poll_api.php?action=sidebar_badges')
        .then(r => r.json())
        .then(data => {
            updateCustBadge('custBadgeProducts', data.products, '#8b5cf6');
            updateCustBadge('custBadgeOrders', data.orders, '#ff6b35');
            updateCustBadge('custBadgeMessages', data.messages, '#22c55e');
            
            const cartEl = document.getElementById('navCartCount');
            if (cartEl && typeof data.cart_count !== 'undefined') {
                cartEl.textContent = data.cart_count;
            }
        }).catch(() => {});
}
function updateCustBadge(id, count, color) {
    let el = document.getElementById(id);
    if (count > 0) {
        if (!el) {
            const map = { custBadgeProducts: 'Products', custBadgeOrders: 'My Orders', custBadgeMessages: 'Messages' };
            const links = document.querySelectorAll('.nav-links a');
            links.forEach(a => {
                if (a.textContent.trim().startsWith(map[id])) {
                    el = document.createElement('span');
                    el.id = id;
                    el.className = 'nav-badge';
                    if (color) el.style.background = color;
                    a.appendChild(el);
                }
            });
        }
        if (el) { el.textContent = count > 99 ? '99+' : count; el.style.display = 'inline-flex'; }
    } else if (el) {
        el.style.display = 'none';
    }
}
setInterval(pollCustSidebarBadges, 8000);
</script>

<style>

.search-box {
    display: flex;
    align-items: center;
    border: 1.5px solid #eaeaea;
    border-radius: 30px;
    background: #FAF9F6;
    height: 35px;
    box-sizing: border-box;
    width: 36px;
    padding: 0 10px;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    overflow: hidden;
}
.search-box svg {
    flex-shrink: 0;
    cursor: pointer;
    transition: all 0.25s;
    stroke: #666666;
}
.search-box input {
    border: none;
    background: transparent;
    outline: none;
    font-size: 12px;
    width: 0;
    opacity: 0;
    font-family: inherit;
    color: #111111;
    padding: 0;
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.2s;
}
.search-box:hover,
.search-box:focus-within {
    width: 190px;
    padding: 0 12px;
    border-color: #ff6b35;
    background: #ffffff;
    box-shadow: 0 4px 12px rgba(255,107,53,0.06);
}
.search-box:hover input,
.search-box:focus-within input {
    width: 100%;
    opacity: 1;
    margin-left: 8px;
}
.search-box:hover svg,
.search-box:focus-within svg {
    stroke: #ff6b35;
}

@keyframes bell-shake {
    0%, 100% { transform: rotate(0); }
    15% { transform: rotate(-14deg); }
    30% { transform: rotate(12deg); }
    45% { transform: rotate(-10deg); }
    60% { transform: rotate(8deg); }
    75% { transform: rotate(-4deg); }
    90% { transform: rotate(2deg); }
}
#custNotifBellWrap button:hover svg {
    animation: bell-shake 0.6s ease-in-out;
    color: #ff6b35;
}
#custNotifBellWrap button {
    transition: all 0.2s;
}
#custNotifBellWrap button:hover {
    transform: scale(1.08);
}
</style>
