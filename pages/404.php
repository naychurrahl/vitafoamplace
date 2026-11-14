<?php
$S = Store::info();
http_response_code(404);
View::meta(['title' => "Page not found | {$S['name']}", 'noindex' => true]);
$popular = Store::api()->get('/products', ['sort' => 'popular', 'per_page' => 4])['items'];
?>
<div class="wrap">
  <div class="empty">
    <h1>We can't find that page</h1>
    <p>It may have moved, or the product is no longer sold. Try searching, or start from one of these.</p>
    <form class="search big" action="<?= url('search') ?>"><?= icon('search') ?><input type="search" name="q" placeholder="Search products" aria-label="Search"></form>
    <p><a class="btn btn-primary" href="<?= url('shop') ?>">Shop all</a> <a class="btn btn-ghost" href="<?= url('/') ?>">Home</a></p>
  </div>
  <section class="section"><div class="section-head"><h2>Popular right now</h2></div><div class="grid"><?php foreach ($popular as $p) View::partial('product-card', ['p' => $p]) ?></div></section>
</div>
