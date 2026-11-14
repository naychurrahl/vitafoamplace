<?php
// Paystack redirects here with ?reference=. We verify server-side, then show the order.
$q = ['reference' => $_GET['reference'] ?? ''];
if (isset($_GET['outcome'])) $q['outcome'] = $_GET['outcome']; // demo only
$v = Store::api()->get('/payments/paystack/verify', $q);
$_SESSION['orders'][$v['number']] = $v['access_token'];
redirect("order/{$v['number']}?t={$v['access_token']}");
