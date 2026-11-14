<?php
// Demo stand-in for Paystack's hosted checkout. The real API returns a checkout.paystack.com URL.
$S = Store::info();
$ref = $_GET['reference'] ?? '';
View::meta(['title' => 'Paystack (demo)', 'noindex' => true, 'raw' => true]);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pay with Paystack (demo)</title>
<link rel="stylesheet" href="<?= asset('css/site.css') ?>"></head>
<body class="paymock">
  <div class="paymock-box">
    <p class="paymock-flag">Demo checkout — no money moves</p>
    <h1>paystack</h1>
    <p><?= e($S['name']) ?><br><span class="muted">Reference <?= e($ref) ?></span></p>
    <div class="paymock-card"><span>Card number</span><strong>4084 0840 8408 4081</strong><span class="muted">Test card · any CVV · any future date</span></div>
    <a class="btn btn-primary btn-lg btn-block" href="<?= url('pay/callback', ['reference' => $ref, 'outcome' => 'success']) ?>">Pay now</a>
    <a class="btn btn-ghost btn-block" href="<?= url('pay/callback', ['reference' => $ref, 'outcome' => 'failed']) ?>">Simulate a failed payment</a>
  </div>
</body></html>
