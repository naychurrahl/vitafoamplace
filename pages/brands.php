<?php
$S = Store::info();
$brands = Store::api()->get('/brands');
View::meta(['title' => "Mattress brands: Vitafoam, Mouka, Winco | {$S['name']}", 'description' => 'Shop genuine mattresses and pillows by brand. Every product is sealed and comes with the manufacturer\'s warranty.', 'canonical' => '/brands']);
?>
<div class="wrap">
  <?php View::breadcrumbs([['Home', '/'], ['Brands', null]]) ?>
  <header class="page-head"><h1>Brands</h1><p class="lead">We only sell genuine, sealed products from the manufacturers, with their warranty.</p></header>
  <div class="brand-list">
    <?php foreach ($brands as $b): ?>
    <a class="brand-card" href="<?= url("brands/{$b['slug']}") ?>" style="--brand:<?= e($b['color']) ?>">
      <span class="brand-name"><?= e($b['name']) ?></span>
      <p><?= e($b['description']) ?></p>
      <span class="muted small"><?= icon('shield') ?> <?= e($b['warranty_note']) ?> · <?= (int) $b['product_count'] ?> products</span>
      <span class="link">Shop <?= e($b['name']) ?> <?= icon('chev') ?></span>
    </a>
    <?php endforeach ?>
  </div>
  <section class="notice"><strong>Looking for another brand?</strong> We can source most Nigerian foam brands. <a href="<?= url('contact') ?>">Tell us what you need</a>.</section>
</div>
