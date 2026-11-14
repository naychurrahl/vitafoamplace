<?php
// /_api/* — JSON for the page scripts. Forwards an allow-listed set of public calls
// to the API with the shopper's cart cookie, so tokens never reach the browser's JS.

header('Content-Type: application/json');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'];
$apiPath = '/' . substr($path, strlen('_api/'));

$allowed = [
    'GET'    => ['#^/products$#', '#^/search/suggest$#', '#^/cart$#', '#^/store$#'],
    'POST'   => ['#^/cart/items$#', '#^/checkout/quote$#', '#^/delivery/quote$#', '#^/guide/recommend$#'],
    'PUT'    => ['#^/cart/items/\d+$#'],
    'DELETE' => ['#^/cart/items/\d+$#'],
];
$ok = false;
foreach ($allowed[$method] ?? [] as $rx) if (preg_match($rx, $apiPath)) $ok = true;
if (!$ok) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

if ($method !== 'GET') {
    $sent = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals(csrf_token(), $sent)) { http_response_code(419); echo json_encode(['error' => 'Session expired. Refresh the page.']); exit; }
}

$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
if (str_starts_with($apiPath, '/cart/items')) Store::ensureCart();

$r = Store::api()->request($method, $apiPath, $_GET, $body);
http_response_code($r['status']);
echo json_encode($r['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
