<?php
require_once __DIR__ . '/../session_bootstrap.php';
require_once '../db_conn.php';
require_once 'check_admin.php';
require_once '../csrf_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
}
require_once '../classes/HttpSmsGateway.php';

$smsGateway = new HttpSmsGateway($conn);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_sms_settings') {
        $fields = [
            'sms_gateway_type' => $_POST['sms_gateway_type'] ?? 'semaphore',
            'semaphore_api_key' => $_POST['semaphore_api_key'] ?? '',
            'semaphore_sender_name' => $_POST['semaphore_sender_name'] ?? 'ZianPrint',
            'twilio_account_sid' => $_POST['twilio_account_sid'] ?? '',
            'twilio_auth_token' => $_POST['twilio_auth_token'] ?? '',
            'twilio_phone_number' => $_POST['twilio_phone_number'] ?? '',
            'smsgate_local_url' => $_POST['smsgate_local_url'] ?? '',
            'smsgate_local_username' => $_POST['smsgate_local_username'] ?? '',
            'smsgate_local_password' => $_POST['smsgate_local_password'] ?? '',
            'smsgate_public_url' => $_POST['smsgate_public_url'] ?? '',
            'smsgate_cloud_url' => $_POST['smsgate_cloud_url'] ?? 'https://api.sms-gate.app:443',
            'smsgate_device_id' => $_POST['smsgate_device_id'] ?? '',
            'smsgate_cloud_username' => $_POST['smsgate_cloud_username'] ?? '',
            'smsgate_cloud_password' => $_POST['smsgate_cloud_password'] ?? ''
        ];
        
        $success = true;
        foreach ($fields as $key => $val) {
            $stmt = $conn->prepare("INSERT INTO system_info (meta_field, meta_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)");
            if ($stmt) {
                $stmt->bind_param('ss', $key, $val);
                $stmt->execute();
                $stmt->close();
            } else {
                $success = false;
            }
        }
        
        if ($success) {
            $msg = 'success:SMS settings saved successfully!';
            $smsGateway = new HttpSmsGateway($conn);
        } else {
            $msg = 'error:Failed to save SMS settings';
        }
    } elseif ($_POST['action'] === 'save_gemini_settings') {
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
            $msg = 'success:Gemini AI settings saved successfully!';
        } else {
            $msg = 'error:Failed to save Gemini AI settings';
        }
    } elseif ($_POST['action'] === 'test_sms') {
        $test_number = trim($_POST['test_number'] ?? '');
        $test_message = trim($_POST['test_message'] ?? '');
        
        if (empty($test_number) || empty($test_message)) {
            $msg = 'error:Phone number and message are required';
        } else {
            $res = $smsGateway->sendSMS($test_number, $test_message);
            if ($res['success']) {
                $msg = 'success:Test SMS sent successfully! ID: ' . ($res['message_id'] ?? 'N/A');
            } else {
                $msg = 'error:Send failed: ' . ($res['message'] ?? 'Unknown error');
            }
        }
    }
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

$config = $smsGateway->getConfiguration();
$stats = $smsGateway->getStatistics();
$logs = $smsGateway->getSMSLogs(30);

$dbKeyRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_api_key'");
$currentGeminiKey = ($dbKeyRes && $dbKeyRes->num_rows > 0) ? $dbKeyRes->fetch_assoc()['meta_value'] : '';

$dbModelRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_model'");
$currentGeminiModel = ($dbModelRes && $dbModelRes->num_rows > 0) ? $dbModelRes->fetch_assoc()['meta_value'] : 'gemini-3.5-flash';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Settings - Zian's PrintShop Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../css/admin.css?v=<?= time() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 admin-page">

<?php 
$currentPage = 'api_settings.php';
include 'sidebar.php'; 
?>

<main class="ml-64 p-8 min-h-screen">
    
    <div class="mb-8">
        <h1 class="text-2xl font-bold text-gray-900">API Settings Configuration</h1>
        <p class="text-gray-500 text-sm mt-1">Configure Gemini AI chatbot intelligence and Outbound SMS Gateway providers</p>
    </div>

    
    <?php if ($msg): 
        $parts = explode(':', $msg, 2);
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                showToast('<?= $parts[0] === 'success' ? 'Success!' : 'Error!' ?>', '<?= addslashes(htmlspecialchars($parts[1])) ?>', '<?= $parts[0] === 'success' ? 'success' : 'error' ?>');
            });
        </script>
    <?php endif; ?>

    
    <div class="border-b border-gray-200 mb-6">
        <nav class="flex space-x-6" aria-label="Tabs">
            <button onclick="switchMainTab('sms_settings')" id="tab_sms_settings_btn" class="main-tab-btn py-4 px-1 border-b-2 font-semibold text-sm border-orange-500 text-orange-600">
                Outbound SMS Gateway
            </button>
            <button onclick="switchMainTab('gemini_settings')" id="tab_gemini_settings_btn" class="main-tab-btn py-4 px-1 border-b-2 font-semibold text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                Gemini AI Chatbot
            </button>
            <button onclick="switchMainTab('dispatch_logs')" id="tab_dispatch_logs_btn" class="main-tab-btn py-4 px-1 border-b-2 font-semibold text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                SMS Transaction Logs
            </button>
        </nav>
    </div>

    
    <div id="panel_sms_settings" class="main-panel space-y-6">
        
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
                <div class="p-3 bg-orange-50 text-orange-600 rounded-lg">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                </div>
                <div>
                    <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Sent Today</p>
                    <h3 class="text-2xl font-bold text-gray-900 mt-1"><?= $stats['sent_today'] ?></h3>
                </div>
            </div>
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
                <div class="p-3 bg-red-50 text-red-600 rounded-lg">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                </div>
                <div>
                    <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Failed Today</p>
                    <h3 class="text-2xl font-bold text-gray-900 mt-1"><?= $stats['failed_today'] ?></h3>
                </div>
            </div>
            <div class="bg-white p-6 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
                <div class="p-3 bg-green-50 text-green-600 rounded-lg">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
                <div>
                    <p class="text-gray-400 text-xs font-semibold uppercase tracking-wider">Sent This Month</p>
                    <h3 class="text-2xl font-bold text-gray-900 mt-1"><?= $stats['sent_this_month'] ?></h3>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="font-bold text-gray-900">Outbound Gateway Provider Settings</h3>
                </div>
                <form method="POST" class="p-6 space-y-6">
                    <input type="hidden" name="action" value="save_sms_settings">
                    
                    
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Select Active Gateway</label>
                        <select name="sms_gateway_type" id="gatewaySelect" onchange="switchGatewayTab(this.value)" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white font-medium text-gray-900">
                            <option value="semaphore" <?= $config['gateway_type'] === 'semaphore' ? 'selected' : '' ?>>Semaphore (Philippines)</option>
                            <option value="twilio" <?= $config['gateway_type'] === 'twilio' ? 'selected' : '' ?>>Twilio (Global)</option>
                            <option value="smsgate" <?= $config['gateway_type'] === 'smsgate' ? 'selected' : '' ?>>SMS Gate (Local/Cloud Android Phone)</option>
                        </select>
                    </div>

                    
                    <div class="border-t border-gray-100 pt-6">
                        
                        <div id="fields_semaphore" class="gateway-fields space-y-4">
                            <div class="bg-orange-50/50 text-orange-800 text-xs p-4 rounded-lg flex items-start gap-2 mb-2">
                                <svg class="h-4 w-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <div>
                                    <span class="font-semibold">Philippines Recommendation:</span> Sign up at <a href="https://semaphore.co" target="_blank" class="underline font-bold">Semaphore.co</a> to get an API Key. Messages cost roughly ₱0.50 each.
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Semaphore API Key</label>
                                <input type="text" name="semaphore_api_key" value="<?= htmlspecialchars($config['semaphore_api_key']) ?>" placeholder="Enter Semaphore API Key" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Semaphore Sender Name</label>
                                <input type="text" name="semaphore_sender_name" value="<?= htmlspecialchars($config['semaphore_sender_name']) ?>" placeholder="ZianPrint (Max 11 chars)" maxlength="11" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                            </div>
                        </div>

                        
                        <div id="fields_twilio" class="gateway-fields space-y-4 hidden">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Twilio Account SID</label>
                                <input type="text" name="twilio_account_sid" value="<?= htmlspecialchars($config['twilio_account_sid']) ?>" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Twilio Auth Token</label>
                                <input type="password" name="twilio_auth_token" value="<?= htmlspecialchars($config['twilio_auth_token']) ?>" placeholder="••••••••••••••••••••••••••••••••" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Twilio Sender Phone Number</label>
                                <input type="text" name="twilio_phone_number" value="<?= htmlspecialchars($config['twilio_phone_number']) ?>" placeholder="+1XXXXXXXXXX" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                            </div>
                        </div>

                        
                        <div id="fields_smsgate" class="gateway-fields space-y-6 hidden">
                            <div class="bg-blue-50/50 text-blue-800 text-xs p-4 rounded-lg flex items-start gap-2">
                                <svg class="h-4 w-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <div>
                                    <span class="font-semibold">SMS Gate / HttpSMS Info:</span> Turn your Android phone into an SMS Gateway. Download the SMS Gate app and configure your endpoints below. Local, Public, or Cloud configs will automatically fall back in order.
                                </div>
                            </div>

                            
                            <div class="border-b border-gray-100 pb-4">
                                <h4 class="text-sm font-bold text-gray-800 mb-3">1. Local Server (Same Wifi/Network)</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Local Gateway URL</label>
                                        <input type="text" name="smsgate_local_url" value="<?= htmlspecialchars($config['smsgate_local_url']) ?>" placeholder="http://192.168.1.XX:8080" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Local Username</label>
                                        <input type="text" name="smsgate_local_username" value="<?= htmlspecialchars($config['smsgate_local_username']) ?>" placeholder="sms" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Local Password</label>
                                        <input type="password" name="smsgate_local_password" value="<?= htmlspecialchars($config['smsgate_local_password']) ?>" placeholder="••••••••" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                    </div>
                                </div>
                            </div>

                            
                            <div class="border-b border-gray-100 pb-4">
                                <h4 class="text-sm font-bold text-gray-800 mb-3">2. Public Server (Port Forwarded IP)</h4>
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Public Gateway URL</label>
                                    <input type="text" name="smsgate_public_url" value="<?= htmlspecialchars($config['smsgate_public_url']) ?>" placeholder="http://122.XX.XX.XX:8080" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                </div>
                            </div>

                            
                            <div>
                                <h4 class="text-sm font-bold text-gray-800 mb-3">3. Cloud Server (Sms-Gate.app Cloud)</h4>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Cloud URL</label>
                                        <input type="text" name="smsgate_cloud_url" value="<?= htmlspecialchars($config['smsgate_cloud_url']) ?>" placeholder="https://api.sms-gate.app" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Cloud Username / Client ID</label>
                                        <input type="text" name="smsgate_cloud_username" value="<?= htmlspecialchars($config['smsgate_cloud_username']) ?>" placeholder="Cloud Username" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Cloud Password</label>
                                        <input type="password" name="smsgate_cloud_password" value="<?= htmlspecialchars($config['smsgate_cloud_password']) ?>" placeholder="••••••••" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                    </div>
                                    <div class="md:col-span-2">
                                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Device ID</label>
                                        <input type="text" name="smsgate_device_id" value="<?= htmlspecialchars($config['smsgate_device_id']) ?>" placeholder="Device ID from App" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-gray-100 flex justify-end">
                        <button type="submit" class="px-6 py-2.5 rounded-lg text-white text-sm font-semibold transition-all hover:opacity-90 shadow-sm" style="background:#ff6b35;">Save Gateway Settings</button>
                    </div>
                </form>
            </div>

            
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden h-fit">
                <div class="px-6 py-4 border-b border-gray-100">
                    <h3 class="font-bold text-gray-900">Send Test SMS</h3>
                </div>
                <form method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="test_sms">
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Recipient Phone Number</label>
                        <input type="text" name="test_number" required placeholder="09171234567" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Message Body</label>
                        <textarea name="test_message" required rows="4" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">This is a test notification from Zian's PrintShop SMS Gateway configuration.</textarea>
                    </div>
                    <button type="submit" class="w-full py-2.5 rounded-lg text-white text-sm font-semibold transition-all hover:opacity-90 shadow-sm" style="background:#16a34a;">Send Outbound Message</button>
                </form>
            </div>
        </div>
    </div>

    
    <div id="panel_gemini_settings" class="main-panel space-y-6 hidden">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-900">🤖 Google Gemini AI Chatbot Configuration</h3>
            </div>
            <form method="POST" class="p-6 space-y-6">
                <input type="hidden" name="action" value="save_gemini_settings">
                
                <div class="bg-orange-50/50 text-orange-800 text-xs p-4 rounded-lg flex items-start gap-2">
                    <svg class="h-4 w-4 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <div>
                        <span class="font-semibold">Setup instructions:</span> Obtain a Gemini API Key from <a href="https://makersuite.google.com/app/apikey" target="_blank" class="underline font-bold">Google AI Studio</a>. Your chatbot uses this key to talk to customers about shop products, pricing, and orders.
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Gemini API Key</label>
                        <input type="text" name="gemini_api_key" id="gemini_api_key" value="<?= htmlspecialchars($currentGeminiKey) ?>" placeholder="AIzaSy..." class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">AI Model Engine</label>
                        <select name="gemini_model" id="gemini_model" class="w-full px-4 py-2.5 border border-gray-200 rounded-lg focus:outline-none focus:border-orange-400 text-sm bg-white font-medium text-gray-900">
                            <option value="gemini-3.6-flash" <?= $currentGeminiModel === 'gemini-3.6-flash' ? 'selected' : '' ?>>gemini-3.6-flash (Fast & Stable - Recommended)</option>
                            <option value="gemini-3.7-flash" <?= $currentGeminiModel === 'gemini-3.7-flash' ? 'selected' : '' ?>>gemini-3.7-flash (Latest Intelligent)</option>
                            <option value="gemini-3.8-flash" <?= $currentGeminiModel === 'gemini-3.8-flash' ? 'selected' : '' ?>>gemini-3.8-flash</option>
                            <option value="gemini-3.5-flash" <?= $currentGeminiModel === 'gemini-3.5-flash' ? 'selected' : '' ?>>gemini-3.5-flash</option>
                        </select>
                    </div>
                </div>

                <div class="pt-6 border-t border-gray-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button type="button" onclick="testGeminiConnection()" class="px-5 py-2 border border-gray-200 rounded-lg text-slate-700 text-sm font-semibold hover:bg-slate-50 flex items-center gap-2">
                            <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                            Test Connection
                        </button>
                        <span id="gemini-test-result" class="text-sm font-medium"></span>
                    </div>
                    <button type="submit" class="px-6 py-2.5 rounded-lg text-white text-sm font-semibold transition-all hover:opacity-90 shadow-sm" style="background:#ff6b35;">Save Gemini Settings</button>
                </div>
            </form>
        </div>
    </div>

    
    <div id="panel_dispatch_logs" class="main-panel space-y-6 hidden">
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-900 font-semibold">Outbound SMS Dispatch logs</h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-500">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50/70 border-b border-gray-100">
                        <tr>
                            <th class="px-6 py-3">ID</th>
                            <th class="px-6 py-3">Gateway</th>
                            <th class="px-6 py-3">Recipient</th>
                            <th class="px-6 py-3">Message</th>
                            <th class="px-6 py-3">Status</th>
                            <th class="px-6 py-3">Sent At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="6" class="px-6 py-10 text-center text-gray-400">No outbound message dispatches logged yet</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $l): ?>
                                <tr class="hover:bg-gray-50/50">
                                    <td class="px-6 py-4 font-semibold text-gray-900">#<?= $l['id'] ?></td>
                                    <td class="px-6 py-4 capitalize font-medium text-gray-600"><?= htmlspecialchars($l['from_number']) ?></td>
                                    <td class="px-6 py-4 font-medium text-gray-900"><?= htmlspecialchars($l['to_number']) ?></td>
                                    <td class="px-6 py-4 max-w-xs truncate text-gray-500" title="<?= htmlspecialchars($l['message']) ?>"><?= htmlspecialchars($l['message']) ?></td>
                                    <td class="px-6 py-4">
                                        <?php if ($l['status'] === 'sent'): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700">
                                                <span class="w-1.5 h-1.5 rounded-full bg-green-600"></span>Sent
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700" title="<?= htmlspecialchars($l['error_message'] ?? '') ?>">
                                                <span class="w-1.5 h-1.5 rounded-full bg-red-600"></span>Failed
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-xs text-gray-400"><?= date('M d, Y h:i A', strtotime($l['sent_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
    function switchMainTab(tabId) {
        
        document.querySelectorAll('.main-tab-btn').forEach(btn => {
            btn.classList.remove('border-orange-500', 'text-orange-600');
            btn.classList.add('border-transparent', 'text-gray-500');
        });
        const activeBtn = document.getElementById('tab_' + tabId + '_btn');
        if (activeBtn) {
            activeBtn.classList.remove('border-transparent', 'text-gray-500');
            activeBtn.classList.add('border-orange-500', 'text-orange-600');
        }

        
        document.querySelectorAll('.main-panel').forEach(panel => panel.classList.add('hidden'));
        const activePanel = document.getElementById('panel_' + tabId);
        if (activePanel) {
            activePanel.classList.remove('hidden');
        }
    }

    function switchGatewayTab(value) {
        document.querySelectorAll('.gateway-fields').forEach(el => el.classList.add('hidden'));
        const el = document.getElementById('fields_' + value);
        if (el) el.classList.remove('hidden');
    }

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
        
        fetch(`api_settings.php?test_gemini=${encodeURIComponent(key)}&model=${encodeURIComponent(model)}`)
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

    
    document.addEventListener('DOMContentLoaded', () => {
        switchGatewayTab(document.getElementById('gatewaySelect').value);
    });
</script>
</body>
</html>
