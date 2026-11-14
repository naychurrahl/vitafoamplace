<?php
// Storefront front controller: every non-file request lands here (.htaccess).
// With PHP's built-in server: php -S localhost:8080 -t storefront storefront/index.php

if (PHP_SAPI === 'cli-server') {
    if (preg_match('#^/(config|mock|lib)(/|$)#', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) { http_response_code(404); exit('Not found'); }
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_file($file) && !str_ends_with($file, '.php')) return false;
    $_SERVER['SCRIPT_NAME'] = '/index.php';
}

require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/Api.php';
require __DIR__ . '/lib/Store.php';
require __DIR__ . '/lib/View.php';

session_name('rw_sf');
session_start();

$path = trim(substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), strlen(base_path())), '/');
$path = rawurldecode($path);

// JSON endpoints for the page scripts (cart, filters, checkout quote, guide). Same-origin only.
if (str_starts_with($path, '_api/')) {
    require __DIR__ . '/lib/proxy.php';
    exit;
}

$routes = [
    ''                         => 'home',
    'shop'                     => 'shop',
    'shop/{category}'          => 'shop',
    'brands'                   => 'brands',
    'brands/{brand}'           => 'shop',
    'search'                   => 'shop',
    'product/{slug}'           => 'product',
    'buyers-guide'             => 'guide',
    'buyers-guide/{slug}'      => 'guide-page',
    'blog'                     => 'blog',
    'blog/category/{category}' => 'blog',
    'blog/tag/{tag}'           => 'blog',
    'blog/{slug}'              => 'post',
    'visit-us'                 => 'visit',
    'contact'                  => 'contact',
    'faq'                      => 'faq',
    'delivery-and-payment'     => 'delivery',
    'about'                    => 'page',
    'returns'                  => 'page',
    'warranty'                 => 'page',
    'privacy'                  => 'page',
    'terms'                    => 'page',
    'cart'                     => 'cart',
    'checkout'                 => 'checkout',
    'order/{number}'           => 'order',
    'track'                    => 'track',
    'wishlist'                 => 'wishlist',
    'login'                    => 'login',
    'register'                 => 'register',
    'logout'                   => 'logout',
    'account'                  => 'account',
    'newsletter'               => 'newsletter',
    'pay/mock'                 => 'pay-mock',
    'pay/callback'             => 'pay-callback',
    'mattress-prices'          => 'prices',
    'pillow-prices'            => 'prices',
    'mattress-sizes'           => 'sizes',
    'feeds/google.xml'         => 'feed',
    'sitemap.xml'              => 'sitemap',
    'robots.txt'               => 'robots',
];

$params = [];
$page = null;
foreach ($routes as $pattern => $p) {
    $rx = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
    if (preg_match($rx, $path, $m)) {
        $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
        $page = $p;
        break;
    }
}

View::dispatch($page ?? '404', $params, $path);
