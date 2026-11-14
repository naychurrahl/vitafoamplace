<?php
// The wishlist lives in the browser (localStorage), so no login is needed. The page script
// fills this from /_api/products?ids=… ; it will sync to /me/wishlist once accounts are on.
$S = Store::info();
View::meta(['title' => "Your wishlist | {$S['name']}", 'noindex' => true]);
?>
<div class="wrap">
  <h1 class="page-title">Your wishlist</h1>
  <div data-wishlist-page>
    <div class="grid" data-wishlist-grid></div>
    <div class="empty" data-wishlist-empty hidden>
      <?= icon('heart', 'i-xl') ?><h2>Nothing saved yet</h2><p>Tap the heart on any product to save it here.</p>
      <a class="btn btn-primary" href="<?= url('shop') ?>">Browse products</a>
    </div>
    <noscript><p class="notice">Turn on JavaScript to see your saved products.</p></noscript>
  </div>
</div>
