<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_admin.php';
require_once '../csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_customer') {
    $customerId = (int)($_POST['customer_id'] ?? 0);
    $firstName = trim((string)($_POST['first_name'] ?? ''));
    $lastName = trim((string)($_POST['last_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $normalizedPhone = preg_replace('/\D+/', '', $phone);
    $address = trim((string)($_POST['address'] ?? ''));

    if ($customerId > 0 && $firstName !== '' && $lastName !== '' && $email !== '') {
        $emailCheck = $conn->prepare('SELECT customer_id FROM customers WHERE email = ? AND customer_id <> ? LIMIT 1');
        $emailCheck->bind_param('si', $email, $customerId);
        $emailCheck->execute();

        if ($emailCheck->get_result()->num_rows > 0) {
            header('Location: customers.php?edit_id=' . $customerId . '&msg=email_taken');
            exit;
        }

        if ($phone !== '') {
            $phoneCheck = $conn->prepare('SELECT customer_id, phone FROM customers WHERE customer_id <> ? AND phone IS NOT NULL AND phone <> ""');
            $phoneCheck->bind_param('i', $customerId);
            $phoneCheck->execute();
            $phoneResult = $phoneCheck->get_result();
            while ($existingCustomer = $phoneResult->fetch_assoc()) {
                if (preg_replace('/\D+/', '', (string)$existingCustomer['phone']) === $normalizedPhone) {
                    header('Location: customers.php?edit_id=' . $customerId . '&msg=phone_taken');
                    exit;
                }
            }
            $adminPhoneResult = $conn->query('SELECT phone FROM admins WHERE phone IS NOT NULL AND phone <> ""');
            while ($existingAdmin = $adminPhoneResult->fetch_assoc()) {
                if (preg_replace('/\D+/', '', (string)$existingAdmin['phone']) === $normalizedPhone) {
                    header('Location: customers.php?edit_id=' . $customerId . '&msg=phone_taken_admin');
                    exit;
                }
            }
            $phone = $normalizedPhone;
        }

        $stmt = $conn->prepare('UPDATE customers SET first_name = ?, last_name = ?, email = ?, phone = ?, address = ? WHERE customer_id = ?');
        $stmt->bind_param('sssssi', $firstName, $lastName, $email, $phone, $address, $customerId);

        if ($stmt->execute()) {
            header('Location: customers.php?msg=updated');
            exit;
        }
    }

    header('Location: customers.php?edit_id=' . $customerId . '&msg=update_error');
    exit;
}

$viewCustomer = null;
if (isset($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    if ($viewId > 0) {
        $viewStmt = $conn->prepare('SELECT c.*, (SELECT COUNT(*) FROM orders WHERE customer_id = c.customer_id) as order_count FROM customers c WHERE c.customer_id = ? LIMIT 1');
        $viewStmt->bind_param('i', $viewId);
        $viewStmt->execute();
        $viewCustomer = $viewStmt->get_result()->fetch_assoc();
    }
}

$editCustomer = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    if ($editId > 0) {
        $editStmt = $conn->prepare('SELECT * FROM customers WHERE customer_id = ? LIMIT 1');
        $editStmt->bind_param('i', $editId);
        $editStmt->execute();
        $editCustomer = $editStmt->get_result()->fetch_assoc();
    }
}

$customersRes = $conn->query("SELECT c.*, (SELECT COUNT(*) FROM orders WHERE customer_id = c.customer_id) as order_count FROM customers c ORDER BY c.date_created DESC");
$customers = [];
while ($r = $customersRes->fetch_assoc()) {
    $customers[] = $r;
}
$statusMessage = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 admin-page">

<?php if ($statusMessage): ?>
<script>document.addEventListener('DOMContentLoaded', () => showToast(<?= json_encode($statusMessage[0]) ?>, <?= json_encode($statusMessage[1]) ?>, <?= json_encode($statusMessage[2]) ?>));</script>
<?php endif; ?>

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    <div class="flex items-center justify-between mb-8">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">Customers</h1>
            <p class="text-gray-500 text-sm mt-1">View all registered customers</p>
        </div>
        <div class="text-sm text-gray-400"><?= count($customers) ?> total customers</div>
    </div>

    <div class="bg-white rounded-xl stat-card overflow-hidden">
        <table class="admin-table w-full">
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Address</th>
                    <th>Orders</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="8" class="text-center text-gray-400 py-8">No customers yet</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                    <tr>
                        <td>
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-white text-xs font-bold" style="background:#ff6b35;">
                                    <?= strtoupper(substr($c['username'] ?? 'U', 0, 1)) ?>
                                </div>
                                <span class="font-medium text-gray-900"><?= htmlspecialchars($c['username'] ?: 'N/A') ?></span>
                            </div>
                        </td>
                        <td class="text-gray-600"><?= htmlspecialchars($c['email'] ?? 'N/A') ?></td>
                        <td class="text-gray-600"><?= htmlspecialchars($c['phone'] ?? 'N/A') ?></td>
                        <td class="text-gray-600 text-sm"><?= htmlspecialchars($c['address'] ?? 'N/A') ?></td>
                        <td class="font-medium"><?= $c['order_count'] ?></td>
                        <td>
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-2.5 py-1 rounded-full bg-green-50 text-green-600">
                                <span class="status-dot w-2 h-2 rounded-full bg-green-500"></span>
                                <span class="status-label">Active</span>
                            </span>
                        </td>
                        <td class="text-gray-500 text-sm"><?= date('M d, Y', strtotime($c['date_created'])) ?></td>
                        <td>
                            <div class="flex items-center gap-2">
                                <a href="customers.php?edit_id=<?= (int)$c['customer_id'] ?>" class="inline-flex items-center justify-center rounded-md border border-blue-200 bg-blue-50 px-2.5 py-1.5 text-xs font-semibold text-blue-600 transition hover:bg-blue-100 hover:text-blue-700">
                                    Edit
                                </a>
                                <button type="button" class="inline-flex items-center justify-center rounded-md border border-gray-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-gray-700 transition hover:bg-gray-100 hover:text-gray-900" onclick="window.location.href='customers.php?view_id=<?= (int)$c['customer_id'] ?>'">
                                    View Details
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<?php if ($viewCustomer): ?>
<div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-bold text-gray-900">Customer Details</h2>
            <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl" onclick="window.location.href='customers.php'">&times;</button>
        </div>
        <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-5 text-sm">
            <div class="bg-gray-50 rounded-xl p-4">
                <div class="text-gray-500 mb-1">Full name</div>
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($viewCustomer['username'] ?: 'N/A') ?></div>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <div class="text-gray-500 mb-1">Email</div>
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($viewCustomer['email'] ?? 'N/A') ?></div>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <div class="text-gray-500 mb-1">Phone</div>
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($viewCustomer['phone'] ?? 'N/A') ?></div>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <div class="text-gray-500 mb-1">Orders</div>
                <div class="font-semibold text-gray-900"><?= (int)($viewCustomer['order_count'] ?? 0) ?></div>
            </div>
            <div class="bg-gray-50 rounded-xl p-4 md:col-span-2">
                <div class="text-gray-500 mb-1">Address</div>
                <div class="font-semibold text-gray-900"><?= htmlspecialchars($viewCustomer['address'] ?? 'N/A') ?></div>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <div class="text-gray-500 mb-1">Joined</div>
                <div class="font-semibold text-gray-900"><?= !empty($viewCustomer['date_created']) ? date('M d, Y', strtotime($viewCustomer['date_created'])) : 'N/A' ?></div>
            </div>
            <div class="bg-gray-50 rounded-xl p-4">
                <div class="text-gray-500 mb-1">Last active</div>
                <div class="font-semibold text-gray-900"><?= !empty($viewCustomer['last_active']) ? date('M d, Y h:i A', strtotime($viewCustomer['last_active'])) : 'N/A' ?></div>
            </div>
        </div>
        <div class="border-t border-gray-200 px-6 py-4 flex justify-end gap-3">
            <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100" onclick="window.location.href='customers.php'">Close</button>
            <a href="customers.php?edit_id=<?= (int)$viewCustomer['customer_id'] ?>" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Edit</a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($editCustomer): ?>
<div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl overflow-hidden">
        <div class="flex items-center justify-between border-b border-gray-200 px-6 py-4">
            <h2 class="text-xl font-bold text-gray-900">Edit Customer</h2>
            <button type="button" class="text-gray-400 hover:text-gray-600 text-2xl" onclick="window.location.href='customers.php'">&times;</button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_customer">
            <input type="hidden" name="customer_id" value="<?= (int)$editCustomer['customer_id'] ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                    <input type="text" name="first_name" value="<?= htmlspecialchars($editCustomer['first_name'] ?? '', ENT_QUOTES) ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                    <input type="text" name="last_name" value="<?= htmlspecialchars($editCustomer['last_name'] ?? '', ENT_QUOTES) ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none" required>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                <input type="email" name="email" value="<?= htmlspecialchars($editCustomer['email'] ?? '', ENT_QUOTES) ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none" required>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                <input type="text" name="phone" value="<?= htmlspecialchars($editCustomer['phone'] ?? '', ENT_QUOTES) ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none">
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                <textarea name="address" rows="3" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:outline-none"><?= htmlspecialchars($editCustomer['address'] ?? '', ENT_QUOTES) ?></textarea>
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" class="rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100" onclick="window.location.href='customers.php'">Cancel</button>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</body>
</html>
