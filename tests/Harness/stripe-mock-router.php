<?php

// Minimal local Stripe stand-in for harness tests (php -S 127.0.0.1:12111 router.php). Every call is
// logged to calls.log as "METHOD /path?query body"; responses are shaped like Stripe's.
//   POST   /v1/customers                 → a new customer;  GET /v1/customers/{id} → that customer
//   POST   /v1/checkout/sessions         → a session with a fake hosted URL (400 if it names price_harness_broken)
//   GET    /v1/promotion_codes?code=     → SPRING exists, nothing else does
//   GET    /v1/subscriptions/{id}        → fixtures/{id}.json, else 404 resource_missing
//   DELETE /v1/subscriptions/{id}        → cancelled
//   POST   /v1/billing_portal/sessions   → a fake portal URL
$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = file_get_contents('php://input');
@file_put_contents(__DIR__.'/calls.log', $method.' '.$_SERVER['REQUEST_URI'].' '.urldecode((string) $body)."\n", FILE_APPEND);
header('Content-Type: application/json');
$id = basename($path);
$error = function (int $status, string $message, ?string $code = null): never {
    http_response_code($status);
    echo json_encode(['error' => array_filter(['type' => 'invalid_request_error', 'code' => $code, 'message' => $message])]);
    exit;
};

switch (true) {
    case $method === 'POST' && $path === '/v1/customers':
        echo json_encode(['id' => 'cus_mock_'.substr(md5((string) microtime(true)), 0, 10), 'object' => 'customer']);
        break;
    case $method === 'GET' && str_starts_with($path, '/v1/customers/'):
        echo json_encode(['id' => $id, 'object' => 'customer', 'email' => null]);
        break;
    case $method === 'POST' && $path === '/v1/checkout/sessions':
        if (str_contains((string) $body, 'price_harness_broken')) {
            $error(400, 'No such price: price_harness_broken', 'resource_missing');
        }
        $sid = 'cs_mock_'.substr(md5((string) microtime(true)), 0, 10);
        echo json_encode(['id' => $sid, 'object' => 'checkout.session', 'url' => "https://checkout.stripe.test/c/pay/{$sid}"]);
        break;
    case $method === 'GET' && $path === '/v1/promotion_codes':
        parse_str((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $q);
        $data = ($q['code'] ?? '') === 'SPRING' ? [['id' => 'promo_harness_spring', 'object' => 'promotion_code', 'code' => 'SPRING', 'active' => true]] : [];
        echo json_encode(['object' => 'list', 'data' => $data, 'has_more' => false, 'url' => '/v1/promotion_codes']);
        break;
    case $method === 'GET' && str_starts_with($path, '/v1/subscriptions/'):
        $file = __DIR__.'/fixtures/'.preg_replace('/[^A-Za-z0-9_]/', '', $id).'.json';
        is_file($file) ? readfile($file) : $error(404, "No such subscription: '{$id}'", 'resource_missing');
        break;
    case $method === 'DELETE' && str_starts_with($path, '/v1/subscriptions/'):
        echo json_encode(['id' => $id, 'object' => 'subscription', 'status' => 'canceled']);
        break;
    case $method === 'POST' && $path === '/v1/billing_portal/sessions':
        echo json_encode(['id' => 'bps_mock', 'object' => 'billing_portal.session', 'url' => 'https://billing.stripe.test/p/session/bps_mock']);
        break;
    default:
        $error(404, "Unrecognized request URL ({$method}: {$path}).");
}
