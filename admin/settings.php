<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_admin.php';
require_once '../csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}

if (isset($_GET['test_gemini'])) {
    header('Content-Type: application/json');
    $api_key = trim($_GET['test_gemini']);
    $model = trim($_GET['model'] ?? 'gemini-3.6-flash');
    if (empty($api_key)) {
        echo json_encode(['status' => 'failed', 'msg' => 'API Key is required']);
        exit;
    }
    
    $modelsToTry = array_unique([$model, 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-3.8-flash']);
    $success = false;
    $lastErr = '';

    foreach ($modelsToTry as $m) {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $m . ':generateContent?key=' . $api_key;
        $data = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Say "Connection successful!" in one sentence.']
                    ]
                ]
            ]
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $result = json_decode($response, true);
            if (isset($result['candidates'])) {
                $success = true;
                echo json_encode(['status' => 'success', 'msg' => "Gemini API connected successfully with {$m}!"]);
                break;
            }
        } else {
            $errData = json_decode($response, true);
            $lastErr = $errData['error']['message'] ?? "HTTP {$httpCode}";
        }
    }

    if (!$success) {
        echo json_encode(['status' => 'failed', 'msg' => 'Connection failed: ' . $lastErr]);
    }
    exit;
}

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_admin_phone') {
        $adminPhone = trim($_POST['admin_phone'] ?? '');
        $normalizedAdminPhone = preg_replace('/\D+/', '', $adminPhone);
        if ($adminPhone !== '' && strlen($normalizedAdminPhone) < 7) {
            $msg = 'error:Enter a valid mobile number';
        } elseif ($adminPhone !== '') {
            $customerPhoneResult = $conn->query('SELECT phone FROM customers WHERE phone IS NOT NULL AND phone <> ""');
            $phoneTaken = false;
            while ($existingCustomer = $customerPhoneResult->fetch_assoc()) {
                if (preg_replace('/\D+/', '', (string)$existingCustomer['phone']) === $normalizedAdminPhone) {
                    $phoneTaken = true;
                    break;
                }
            }
            if ($phoneTaken) {
                $msg = 'error:This mobile number is already used by a customer';
            } else {
                $stmt = $conn->prepare('UPDATE admins SET phone = ? WHERE id = ?');
                $stmt->bind_param('si', $normalizedAdminPhone, $_SESSION['admin_id']);
                if ($stmt->execute()) {
                    $msg = 'success:Admin mobile number saved';
                } else {
                    $msg = $conn->errno === 1062 ? 'error:This mobile number is already used by another administrator' : 'error:Unable to save admin mobile number';
                }
            }
        } else {
            $stmt = $conn->prepare('UPDATE admins SET phone = NULL WHERE id = ?');
            $stmt->bind_param('i', $_SESSION['admin_id']);
            $stmt->execute();
            $msg = 'success:Admin mobile number saved';
        }
    } elseif ($_POST['action'] === 'add_admin') {
        $email = trim($_POST['email']);
        $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $check = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $check->bind_param('s', $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $msg = 'error:Email already exists';
        } else {
            $stmt = $conn->prepare("INSERT INTO admins (username, password) VALUES (?, ?)");
            $stmt->bind_param('ss', $email, $password);
            $stmt->execute();
            $msg = 'success:Admin added successfully';
        }
    } elseif ($_POST['action'] === 'save_gemini') {
        $key = trim($_POST['gemini_api_key'] ?? '');
        $model = trim($_POST['gemini_model'] ?? 'gemini-3.8-flash');
        
        $success = true;
        $stmt = $conn->prepare("INSERT INTO system_info (meta_field, meta_value) VALUES ('gemini_api_key', ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        if ($stmt) {
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $stmt->close();
        } else {
            $success = false;
        }
        
        $stmt2 = $conn->prepare("INSERT INTO system_info (meta_field, meta_value) VALUES ('gemini_model', ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
        if ($stmt2) {
            $stmt2->bind_param('s', $model);
            $stmt2->execute();
            $stmt2->close();
        } else {
            $success = false;
        }
        
        if ($success) {
            $msg = 'success:Gemini AI settings saved successfully';
        } else {
            $msg = 'error:Failed to save settings';
        }
    }
}

$admins = [];
$adminsRes = $conn->query("SELECT id, username, phone, date_created FROM admins ORDER BY id");
while ($r = $adminsRes->fetch_assoc()) $admins[] = $r;
$currentAdminPhone = '';
foreach ($admins as $admin) {
    if ((int)$admin['id'] === (int)$_SESSION['admin_id']) {
        $currentAdminPhone = $admin['phone'] ?? '';
        break;
    }
}

$dbKeyRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_api_key'");
$currentGeminiKey = ($dbKeyRes && $dbKeyRes->num_rows > 0) ? $dbKeyRes->fetch_assoc()['meta_value'] : '';

$dbModelRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_model'");
$currentGeminiModel = ($dbModelRes && $dbModelRes->num_rows > 0) ? $dbModelRes->fetch_assoc()['meta_value'] : 'gemini-2.5-flash';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 admin-page">

<?php include 'sidebar.php'; ?>

<main class="ml-64 p-8 min-h-screen">
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
        <p class="text-gray-500 text-sm mt-1">Manage admin accounts and system settings</p>
    </div>

    <?php if ($msg): 
        $parts = explode(':', $msg, 2);
    ?>
        <script>document.addEventListener('DOMContentLoaded', () => showToast('<?= $parts[0] === 'success' ? 'Success!' : 'Error!' ?>', '<?= addslashes(htmlspecialchars($parts[1])) ?>', '<?= $parts[0] === 'success' ? 'success' : 'error' ?>'));</script>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white rounded-xl stat-card p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Admin Order Notifications</h3>
            <p class="text-gray-500 text-xs mb-4">New orders will be sent to this mobile number by SMS when an SMS gateway is configured.</p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_admin_phone">
                <label class="block text-sm font-medium text-gray-700">Mobile Number</label>
                <input type="text" name="admin_phone" value="<?= htmlspecialchars($currentAdminPhone, ENT_QUOTES) ?>" placeholder="09XX-XXX-XXXX" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                <button type="submit" class="w-full py-2.5 rounded-lg text-white text-sm font-semibold" style="background:#ff6b35;">Save Mobile Number</button>
            </form>
        </div>

        
        <div class="bg-white rounded-xl stat-card p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Add New Admin</h3>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="add_admin">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" required class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                    <input type="password" name="password" required minlength="6" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
                <button type="submit" class="w-full py-2.5 rounded-lg text-white text-sm font-semibold" style="background:#ff6b35;">Add Admin</button>
            </form>
        </div>

        
        <div class="bg-white rounded-xl stat-card p-6">
            <h3 class="text-lg font-semibold text-gray-900 mb-4">Admin Accounts</h3>
            <div class="space-y-3">
                <?php foreach ($admins as $a): ?>
                <div class="flex items-center justify-between py-3 border-b border-gray-100 last:border-0">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-xs font-bold" style="background:hsl(222 47% 11%);">
                            <?= strtoupper(substr($a['username'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($a['username']) ?></div>
                            <div class="text-xs text-gray-400">Joined <?= date('M d, Y', strtotime($a['date_created'])) ?></div>
                        </div>
                    </div>
                    <span class="text-xs px-2.5 py-1 rounded-full bg-green-50 text-green-600 font-medium">Active</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    
    <div class="bg-white rounded-xl stat-card p-6 mt-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-2">🤖 Google Gemini AI (Chatbot)</h3>
        <p class="text-gray-500 text-xs mb-4">Configure the Gemini API key and model used by the customer assistant chatbot</p>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="action" value="save_gemini">
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gemini API Key</label>
                    <input type="text" name="gemini_api_key" id="gemini_api_key" value="<?= htmlspecialchars($currentGeminiKey) ?>" placeholder="AIzaSy..." class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Gemini Model</label>
                    <select name="gemini_model" id="gemini_model" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white">
                        <option value="gemini-3.6-flash" <?= $currentGeminiModel === 'gemini-3.6-flash' ? 'selected' : '' ?>>gemini-3.6-flash (Fast & Stable - Recommended)</option>
                        <option value="gemini-3.7-flash" <?= $currentGeminiModel === 'gemini-3.7-flash' ? 'selected' : '' ?>>gemini-3.7-flash (Latest Intelligent)</option>
                        <option value="gemini-3.8-flash" <?= $currentGeminiModel === 'gemini-3.8-flash' ? 'selected' : '' ?>>gemini-3.8-flash</option>
                        <option value="gemini-3.5-flash" <?= $currentGeminiModel === 'gemini-3.5-flash' ? 'selected' : '' ?>>gemini-3.5-flash</option>
                    </select>
                </div>
            </div>
            
            <div class="flex items-center justify-between pt-2">
                <div class="flex items-center gap-3">
                    <button type="button" onclick="testGeminiConnection()" class="px-4 py-2.5 border border-gray-200 rounded-lg text-slate-700 text-sm font-semibold hover:bg-slate-50 flex items-center gap-2">
                        <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                        Test Connection
                    </button>
                    <span id="gemini-test-result" class="text-sm font-medium"></span>
                </div>
                <button type="submit" class="px-6 py-2.5 rounded-lg text-white text-sm font-semibold hover:opacity-90" style="background:#ff6b35;">Save AI Settings</button>
            </div>
        </form>
    </div>
</main>

<script>
function testGeminiConnection() {
    const key = document.getElementById('gemini_api_key').value.trim();
    const model = document.getElementById('gemini_model').value;
    const resultSpan = document.getElementById('gemini-test-result');
    
    if (!key) {
        resultSpan.className = 'text-red-500 text-sm font-medium';
        resultSpan.textContent = 'Please enter API Key first';
        return;
    }
    
    resultSpan.className = 'text-blue-500 text-sm font-medium';
    resultSpan.textContent = 'Testing connection...';
    
    fetch(`settings.php?test_gemini=${encodeURIComponent(key)}&model=${encodeURIComponent(model)}`)
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                resultSpan.className = 'text-green-600 text-sm font-medium';
                resultSpan.textContent = '✅ Connected successfully!';
            } else {
                resultSpan.className = 'text-red-500 text-sm font-medium';
                resultSpan.textContent = '❌ ' + data.msg;
            }
        })
        .catch(() => {
            resultSpan.className = 'text-red-500 text-sm font-medium';
            resultSpan.textContent = '❌ Request failed';
        });
}
</script>
</body>
</html>
