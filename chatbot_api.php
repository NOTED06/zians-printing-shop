<?php

require_once __DIR__ . '/session_bootstrap.php';
require_once 'db_conn.php';
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$message = trim($input['message'] ?? '');
$history = $input['history'] ?? [];

if (empty($message)) {
    echo json_encode(['reply' => "Please type a message so I can help you!", 'quickReplies' => []]);
    exit;
}
if (mb_strlen($message) > 1000 || !is_array($history) || count($history) > 20) {
    http_response_code(413);
    echo json_encode(['reply' => 'Please shorten your message and try again.', 'quickReplies' => []]);
    exit;
}

$now = time();
$_SESSION['chatbot_requests'] = array_values(array_filter(
    $_SESSION['chatbot_requests'] ?? [],
    static fn($timestamp) => is_int($timestamp) && $timestamp > $now - 60
));
if (count($_SESSION['chatbot_requests']) >= 20) {
    http_response_code(429);
    echo json_encode(['reply' => 'Too many requests. Please wait a moment and try again.', 'quickReplies' => []]);
    exit;
}
$_SESSION['chatbot_requests'][] = $now;

$geminiKey = getenv('GEMINI_API_KEY') ?: '';
$dbKeyRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_api_key'");
if ($dbKeyRes && $dbKeyRes->num_rows > 0) {
    $row = $dbKeyRes->fetch_assoc();
    if (!empty($row['meta_value'])) {
        $geminiKey = $row['meta_value'];
    }
}

$geminiModel = getenv('GEMINI_MODEL') ?: 'gemini-3.6-flash';
$dbModelRes = $conn->query("SELECT meta_value FROM system_info WHERE meta_field = 'gemini_model'");
if ($dbModelRes && $dbModelRes->num_rows > 0) {
    $row = $dbModelRes->fetch_assoc();
    if (!empty($row['meta_value'])) {
        $geminiModel = $row['meta_value'];
    }
}

$productInfo = [];
$res = $conn->query("SELECT p.product_name, p.description, p.unit FROM products p WHERE p.record_type='product' AND p.is_active = 1 ORDER BY p.product_name ASC");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $entry = $r['product_name'];
        if (!empty($r['description'])) $entry .= ' - ' . $r['description'];
        if (!empty($r['unit'])) $entry .= ' (Unit: ' . $r['unit'] . ')';
        $productInfo[] = $entry;
    }
}

$variantInfo = [];
$vRes = $conn->query("SELECT pv.variant_name, pv.size, pv.price, p.product_name 
    FROM products pv 
    JOIN products p ON p.record_type='product' AND pv.product_id = p.product_id
    WHERE pv.record_type='variant' AND pv.is_active = 1 ORDER BY p.product_name, pv.variant_name");
if ($vRes) {
    while ($v = $vRes->fetch_assoc()) {
        $line = $v['product_name'] . ' > ' . $v['variant_name'];
        if (!empty($v['size'])) $line .= ' (' . $v['size'] . ')';
        if (!empty($v['price'])) $line .= ' - ₱' . number_format($v['price'], 2);
        $variantInfo[] = $line;
    }
}

$orderInfo = '';
$orderCards = [];
$customerId = $_SESSION['user_id'] ?? null;
$customerName = $_SESSION['username'] ?? 'Guest';
if ($customerId) {
    $oRes = $conn->prepare("SELECT product_name, variant_name, size, quantity, price, status, payment_mode, down_payment_amount, date_created, batch_id
        FROM orders WHERE customer_id = ? AND status != 'In Cart' ORDER BY date_created DESC LIMIT 10");
    $oRes->bind_param('i', $customerId);
    $oRes->execute();
    $oResult = $oRes->get_result();
    $orders = [];
    while ($o = $oResult->fetch_assoc()) {
        $orders[] = '- ' . $o['product_name'] 
            . (!empty($o['variant_name']) ? ' (' . $o['variant_name'] . ')' : '')
            . ' x' . $o['quantity'] 
            . ' | ₱' . number_format($o['price'], 2) 
            . ' | Status: ' . $o['status'] 
            . ' | Ordered: ' . date('M j, Y', strtotime($o['date_created']));
        
        $orderCards[] = [
            'product' => $o['product_name'],
            'variant' => $o['variant_name'] ?? '',
            'size' => $o['size'] ?? '',
            'qty' => (int)$o['quantity'],
            'price' => number_format($o['price'], 2),
            'status' => $o['status'],
            'payment' => $o['payment_mode'] ?? '',
            'down' => $o['down_payment_amount'] ? number_format($o['down_payment_amount'], 2) : '',
            'date' => date('M j, Y', strtotime($o['date_created'])),
            'batch' => $o['batch_id'] ?? ''
        ];
    }
    if (!empty($orders)) {
        $orderInfo = "\n\nCURRENT CUSTOMER ORDERS for {$customerName}:\n" . implode("\n", $orders);
    } else {
        $orderInfo = "\n\nThis customer ({$customerName}) has no active orders yet.";
    }
}

$productsList = !empty($productInfo) ? implode("\n", $productInfo) : 'No products loaded';
$variantsList = !empty($variantInfo) ? implode("\n", $variantInfo) : 'No variants loaded';

$systemPrompt = "You are Zian's Assistant, a friendly and helpful AI chatbot for Zian's Printing Shop located in Toril, Davao City.

ABOUT THE SHOP:
- Zian's Printing Shop offers quality printing services in Toril, Davao City
- Business Hours: Monday-Saturday 8:00 AM - 6:00 PM, Sunday Closed
- Contact: 09XX-XXX-XXXX | ziansprintshop@email.com
- Online orders accepted 24/7 through the website

AVAILABLE PRODUCTS:
{$productsList}

PRODUCT VARIANTS & PRICES:
{$variantsList}

HOW TO ORDER (Customization Process):
1. Create an Account - Register on the website with name, email, and password
2. Browse Products - View available products in the Products/Dashboard page
3. Select & Customize - Choose a product, pick a variant and size
4. Add to Cart - Select quantity and add to cart
5. Submit Order - Go to My Orders, review cart, and submit
6. Message the Admin - Chat with admin through the Messages page for any customization requests
7. Payment - Admin will send a payment request via chat. Customer can pay via GCash (down payment or full payment)
8. After Payment - Customer fills out customization form with order notes and reference photos
9. Production - Admin processes the order (Processing → Production → Ready for Pickup)
10. Pickup/Completion - Customer picks up the order, admin marks as Completed

ORDER STATUS FLOW:
In Cart → Processing → Production → Ready for Pickup → Completed
(Orders can also be Cancelled)

PAYMENT OPTIONS:
- GCash payment (down payment or full payment)
- Payment is coordinated through the messaging system
- Down payment: partial payment first, remaining balance due at pickup
- Full payment: pay the complete amount upfront via GCash
{$orderInfo}

RULES:
- Be friendly, concise, and helpful
- Use emojis sparingly to keep it engaging
- If asked about specific pricing, refer to the variant prices listed above
- If a product is not in the list, say we don't currently offer it but they can message the admin
- For order status questions, refer to their order data above
- If the customer is not logged in, encourage them to log in or create an account
- You can understand and respond in both English and Filipino/Bisaya
- Keep responses concise (2-4 short paragraphs max)
- Always suggest relevant quick reply options at the end
- Format quick replies as a JSON array in the last line like: [QUICK_REPLIES: option1, option2, option3]";

$contents = [];

$historySlice = array_slice($history, -10);
foreach ($historySlice as $h) {
    $role = ($h['role'] === 'user') ? 'user' : 'model';
    $contents[] = ['role' => $role, 'parts' => [['text' => $h['text']]]];
}

$contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

$modelsToTry = array_unique([$geminiModel, 'gemini-3.6-flash', 'gemini-3.7-flash', 'gemini-3.8-flash', 'gemini-3.5-flash']);
$response = false;
$httpCode = 0;
$curlErr = '';

$payload = [
    'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
    'contents' => $contents,
    'generationConfig' => [
        'temperature' => 0.7,
        'maxOutputTokens' => 1024,
    ]
];
$jsonPayload = json_encode($payload);

foreach ($modelsToTry as $tryModel) {
    $geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $tryModel . ':generateContent?key=' . urlencode($geminiKey);
    $ch = curl_init($geminiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    unset($ch);

    if (!$curlErr && $httpCode === 200) {
        break;
    }
}

$reply = '';
$quickReplies = ['Services', 'How to Order', 'My Orders', 'Contact'];

if ($curlErr || $httpCode !== 200) {
    $reply = "I'm having trouble connecting right now. Please try again in a moment! 🔄";
} else {
    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    if (empty($text)) {
        $reply = "I'm sorry, I couldn't generate a response. Please try rephrasing your question! 🤔";
    } else {
        
        if (preg_match('/\[QUICK_REPLIES:\s*(.+?)\]\s*$/', $text, $m)) {
            $text = preg_replace('/\[QUICK_REPLIES:\s*.+?\]\s*$/', '', $text);
            $quickReplies = array_map('trim', explode(',', $m[1]));
        }
        $reply = trim($text);
    }
}

$msgLower = strtolower($message);
$isOrderQuery = preg_match('/(track|order|status|progress|my order|orders|aking order|update|where.*order|ano.*status)/i', $msgLower);
$responseData = [
    'reply' => $reply,
    'quickReplies' => $quickReplies
];

if ($isOrderQuery && !empty($orderCards) && $customerId) {
    $responseData['orderCards'] = $orderCards;
}

echo json_encode($responseData);
