<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_admin.php';

$pendingRes = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status IN ('In Cart','Processing')");
$pendingCount = $pendingRes->fetch_assoc()['cnt'];
$processingRes = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Processing'");
$processingCount = $processingRes->fetch_assoc()['cnt'];

$completedRes = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Completed'");
$completedCount = $completedRes->fetch_assoc()['cnt'];
$weekStart = date('Y-m-d', strtotime('monday this week'));
$completedThisWeek = $conn->query("SELECT COUNT(*) as cnt FROM orders WHERE status='Completed' AND date_created >= '$weekStart'")->fetch_assoc()['cnt'];

$totalProducts = $conn->query("SELECT COUNT(*) as cnt FROM products WHERE record_type='product'")->fetch_assoc()['cnt'];

$bestSelling = [];
$bestRes = $conn->query("
        SELECT co.product_id,
            co.product_name,
           p.price as unit_price,
           p.img as image,
           SUM(co.quantity) as total_sold, 
           SUM(co.price) as total_revenue,
           COUNT(co.id) as order_count
    FROM orders co
    LEFT JOIN products p ON p.record_type='product' AND co.product_id = p.product_id
        WHERE co.status = 'Completed'
            AND NULLIF(TRIM(p.img), '') IS NOT NULL
        GROUP BY co.product_id, co.product_name, p.price, p.img
    ORDER BY total_sold DESC
    LIMIT 8
");
if ($bestRes) {
    while ($row = $bestRes->fetch_assoc()) $bestSelling[] = $row;
}

$recentOrdersRes = $conn->query("SELECT co.id, co.product_name, co.price, co.status, co.date_created, c.username as customer_name FROM orders co LEFT JOIN customers c ON co.customer_id = c.customer_id ORDER BY co.date_created DESC LIMIT 5");
$recentOrders = [];
while ($row = $recentOrdersRes->fetch_assoc()) {
    $recentOrders[] = $row;
}

function getStatusBadge($status) {
    $map = [
        'In Cart' => 'badge-incart',
        'Pending' => 'badge-pending',
        'Processing' => 'badge-processing',
        'Completed' => 'badge-completed',
        'Cancelled' => 'badge-cancelled',
    ];
    return $map[$status] ?? 'badge-pending';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
    <script>
    tailwind.config = {
        theme: {
            extend: {
                colors: {
                    accent: '#ff6b35',
                    sidebar: { bg: 'hsl(222, 47%, 11%)', border: 'hsl(222, 30%, 20%)' }
                }
            }
        }
    }
    </script>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
            <p class="text-gray-500 text-sm mt-1">Welcome back! Here's your shop overview.</p>
        </div>
        <div class="text-sm text-gray-500"><?= date('F d, Y') ?></div>
    </div>

    
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        
        <div class="bg-white rounded-xl p-6 stat-card">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-medium text-gray-500">Pending Orders</div>
                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:hsl(38 92% 50%/0.1);">
                    <svg class="w-5 h-5" style="color:hsl(38 92% 50%);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-gray-900"><?= $pendingCount ?></div>
            <div class="text-xs mt-1 text-gray-500"><?= $processingCount ?> processing</div>
        </div>

        
        <div class="bg-white rounded-xl p-6 stat-card">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-medium text-gray-500">Completed Orders</div>
                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:hsl(142 71% 45%/0.1);">
                    <svg class="w-5 h-5" style="color:hsl(142 71% 45%);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22,4 12,14.01 9,11.01"/></svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-gray-900"><?= $completedCount ?></div>
            <div class="text-xs mt-1 text-green-600">+<?= $completedThisWeek ?> this week</div>
        </div>

        
        <div class="bg-white rounded-xl p-6 stat-card">
            <div class="flex items-center justify-between mb-4">
                <div class="text-sm font-medium text-gray-500">Total Products</div>
                <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background:hsl(270 70% 55%/0.1);">
                    <svg class="w-5 h-5" style="color:hsl(270 70% 55%);" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 3H8l-2 4h12l-2-4z"/></svg>
                </div>
            </div>
            <div class="text-2xl font-bold text-gray-900"><?= $totalProducts ?></div>
            <div class="text-xs mt-1 text-gray-500">in catalog</div>
        </div>

    </div>

    
    <div class="bg-white rounded-xl p-6 stat-card mb-8">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Products Best on Sale</h3>
                <p class="text-sm text-gray-500 mt-1">Top-performing products by completed orders</p>
            </div>
            <a href="products.php" class="text-sm font-medium px-4 py-2 rounded-lg transition" style="color:#ff6b35; background:rgba(255,107,53,0.08);">View All Products</a>
        </div>

        <?php if (empty($bestSelling)): ?>
            <div class="text-center py-12">
                <div class="w-16 h-16 rounded-full mx-auto mb-4 flex items-center justify-center" style="background:rgba(255,107,53,0.08);">
                    <svg class="w-8 h-8" style="color:#ff6b35;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3H8l-2 4h12l-2-4z"/></svg>
                </div>
                <p class="text-gray-500 font-medium">No sales data yet</p>
                <p class="text-gray-400 text-sm mt-1">Completed orders will appear here</p>
            </div>
        <?php else: ?>
            
            <div class="grid grid-cols-12 gap-4 px-4 py-3 rounded-lg bg-gray-50 text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                <div class="col-span-1">#</div>
                <div class="col-span-4">Product</div>
                <div class="col-span-2 text-center">Unit Price</div>
                <div class="col-span-1 text-center">Sold</div>
                <div class="col-span-1 text-center">Orders</div>
                <div class="col-span-2 text-right">Revenue</div>
                <div class="col-span-1 text-right">Share</div>
            </div>

            <?php 
            $maxSold = $bestSelling[0]['total_sold'] ?? 1;
            $totalSalesRevenue = array_sum(array_column($bestSelling, 'total_revenue'));
            foreach ($bestSelling as $i => $product): 
                $barWidth = ($product['total_sold'] / $maxSold) * 100;
                $share = $totalSalesRevenue > 0 ? round(($product['total_revenue'] / $totalSalesRevenue) * 100, 1) : 0;
            ?>
            <div class="grid grid-cols-12 gap-4 items-center px-4 py-4 rounded-lg hover:bg-gray-50 transition border-b border-gray-100 last:border-0 group">
                
                <div class="col-span-1">
                    <?php if ($i < 3): ?>
                        <span class="inline-flex items-center justify-center w-7 h-7 rounded-full text-xs font-bold text-white
                            <?= $i === 0 ? 'bg-yellow-500' : ($i === 1 ? 'bg-gray-400' : 'bg-amber-600') ?>">
                            <?= $i + 1 ?>
                        </span>
                    <?php else: ?>
                        <span class="text-sm font-medium text-gray-400 pl-2"><?= $i + 1 ?></span>
                    <?php endif; ?>
                </div>

                
                <div class="col-span-4">
                    <div class="flex items-center gap-3">
                        <?php if (!empty($product['image'])): ?>
                            <img src="../<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['product_name']) ?>" class="w-10 h-10 rounded-lg object-cover border border-gray-200">
                        <?php else: ?>
                            <div class="w-10 h-10 rounded-lg flex items-center justify-center text-lg" style="background:rgba(255,107,53,0.08); color:#ff6b35;">
                                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                            </div>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-gray-900 truncate"><?= htmlspecialchars($product['product_name']) ?></div>
                            <div class="w-full bg-gray-100 rounded-full h-1.5 mt-1.5">
                                <div class="h-1.5 rounded-full transition-all" style="width:<?= $barWidth ?>%; background:#ff6b35;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                
                <div class="col-span-2 text-center">
                    <span class="text-sm text-gray-600">₱<?= number_format($product['unit_price'] ?? 0, 2) ?></span>
                </div>

                
                <div class="col-span-1 text-center">
                    <span class="text-sm font-bold text-gray-900"><?= number_format($product['total_sold']) ?></span>
                </div>

                
                <div class="col-span-1 text-center">
                    <span class="text-sm text-gray-500"><?= $product['order_count'] ?></span>
                </div>

                
                <div class="col-span-2 text-right">
                    <span class="text-sm font-bold" style="color:#ff6b35;">₱<?= number_format($product['total_revenue'], 0) ?></span>
                </div>

                
                <div class="col-span-1 text-right">
                    <span class="text-xs font-medium text-gray-500"><?= $share ?>%</span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    
    <div class="bg-white rounded-xl p-6 stat-card">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h3 class="text-lg font-bold text-gray-900">Recent Orders</h3>
                <p class="text-sm text-gray-500 mt-1">Latest customer orders</p>
            </div>
            <a href="orders.php" class="text-sm font-medium px-4 py-2 rounded-lg transition" style="color:#ff6b35; background:rgba(255,107,53,0.08);">View All Orders</a>
        </div>
        <div class="space-y-3">
            <?php if (empty($recentOrders)): ?>
                <p class="text-gray-400 text-sm text-center py-4">No orders yet</p>
            <?php else: ?>
                <?php foreach ($recentOrders as $order): ?>
                <div class="flex items-center justify-between py-3 px-4 rounded-lg hover:bg-gray-50 transition border-b border-gray-100 last:border-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center text-xs font-bold" style="background:rgba(255,107,53,0.08); color:#ff6b35;">
                            #<?= $order['id'] ?>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($order['product_name']) ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars($order['customer_name'] ?: 'Unknown') ?> · <?= date('M d, h:i A', strtotime($order['date_created'])) ?></div>
                        </div>
                    </div>
                    <div class="text-right flex items-center gap-3">
                        <div class="text-sm font-semibold" style="color:#ff6b35;">₱<?= number_format($order['price'], 0) ?></div>
                        <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?= getStatusBadge($order['status']) ?>"><?= $order['status'] ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>

let _lastDashStats = {
    pending: <?= $pendingCount ?>,
    completed: <?= $completedCount ?>,
    products: <?= $totalProducts ?>,
    recentIds: <?= json_encode(array_column($recentOrders, 'id')) ?>
};

function getStatusBadgeClass(status) {
    const map = { 'In Cart': 'badge-incart', 'Pending': 'badge-pending', 'Processing': 'badge-processing', 'Completed': 'badge-completed', 'Cancelled': 'badge-cancelled' };
    return map[status] || 'badge-pending';
}

function playNewOrderSound() {
    try {
        const context = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        oscillator.type = 'sine';
        oscillator.frequency.setValueAtTime(880, context.currentTime);
        oscillator.frequency.setValueAtTime(1175, context.currentTime + 0.12);
        gain.gain.setValueAtTime(0.0001, context.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.18, context.currentTime + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.45);
        oscillator.connect(gain);
        gain.connect(context.destination);
        oscillator.start();
        oscillator.stop(context.currentTime + 0.45);
    } catch (error) {}
}

function pollDashboard() {
    fetch('poll_api.php?action=dashboard_stats')
        .then(r => r.json())
        .then(d => {
            let changed = false;

            
            const statCards = document.querySelectorAll('.stat-card .text-2xl');
            if (statCards[0] && parseInt(statCards[0].textContent) !== d.pending) {
                statCards[0].textContent = d.pending;
                statCards[0].closest('.stat-card').querySelector('.text-xs').textContent = d.processing + ' processing';
                if (d.pending > _lastDashStats.pending) {
                    showToast('New Order', 'New orders received!', 'info');
                }
                changed = true;
            }
            if (statCards[1] && parseInt(statCards[1].textContent) !== d.completed) {
                statCards[1].textContent = d.completed;
                statCards[1].closest('.stat-card').querySelector('.text-xs.text-green-600').textContent = '+' + d.completedWeek + ' this week';
                changed = true;
            }
            if (statCards[2] && parseInt(statCards[2].textContent) !== d.products) {
                statCards[2].textContent = d.products;
                if (d.products > _lastDashStats.products) {
                    showToast('New Product', 'A new product has been added!', 'info');
                }
                changed = true;
            }

            
            const newRecentIds = d.recentOrders.map(o => o.id).join(',');
            const oldRecentIds = _lastDashStats.recentIds.join(',');
            if (newRecentIds !== oldRecentIds) {
                if (_lastDashStats.recentIds.length > 0 && d.recentOrders.some(o => !_lastDashStats.recentIds.includes(o.id))) {
                    playNewOrderSound();
                }
                const container = document.querySelector('.stat-card:last-child .space-y-3');
                if (container && d.recentOrders.length > 0) {
                    container.innerHTML = d.recentOrders.map(o => {
                        const dateStr = new Date(o.date_created).toLocaleDateString('en-US', { month: 'short', day: '2-digit' }) + ', ' + new Date(o.date_created).toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                        return `<div class="flex items-center justify-between py-3 px-4 rounded-lg hover:bg-gray-50 transition border-b border-gray-100 last:border-0">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg flex items-center justify-center text-xs font-bold" style="background:rgba(255,107,53,0.08); color:#ff6b35;">#${o.id}</div>
                                <div>
                                    <div class="text-sm font-medium text-gray-900">${o.product_name}</div>
                                    <div class="text-xs text-gray-500">${o.customer_name || 'Unknown'} · ${dateStr}</div>
                                </div>
                            </div>
                            <div class="text-right flex items-center gap-3">
                                <div class="text-sm font-semibold" style="color:#ff6b35;">₱${Number(o.price).toLocaleString()}</div>
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium ${getStatusBadgeClass(o.status)}">${o.status}</span>
                            </div>
                        </div>`;
                    }).join('');
                }
                changed = true;
            }

            _lastDashStats = {
                pending: d.pending,
                completed: d.completed,
                products: d.products,
                recentIds: d.recentOrders.map(o => o.id)
            };
        })
        .catch(() => {});
}

setInterval(pollDashboard, 5000);
</script>

</body>
</html>
