<?php
function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session must be started before generating a CSRF token.');
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf_token(): void {
    $expected = csrf_token();
    $provided = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';

    if (is_string($provided) && $provided !== '' && hash_equals($expected, $provided)) {
        return;
    }

    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $requestReferer = $_SERVER['HTTP_REFERER'] ?? '';
    $expectedOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
    $sameOrigin = ($requestOrigin !== '' && rtrim($requestOrigin, '/') === rtrim($expectedOrigin, '/'))
        || ($requestReferer !== '' && str_starts_with($requestReferer, rtrim($expectedOrigin, '/') . '/'));

    if (!$sameOrigin) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid security token. Refresh the page and try again.']);
        exit;
    }
}
