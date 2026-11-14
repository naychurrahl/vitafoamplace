<?php
$S = Store::info();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $api = Store::api();
    try {
        switch ($_POST['action'] ?? '') {
            case 'add':
                Store::ensureCart();
                Store::api()->post('/cart/items', ['variant_id' => (int) ($_POST['variant_id'] ?? 0), 'qty' => (int) ($_POST['qty'] ?? 1)]);
                flash('Added to your cart');
                break;
            case 'qty':
                $api->put('/cart/items/' . (int) $_POST['item'], ['qty' => (int) $_POST['qty']]);
                break;
            case 'remove':
                $api->delete('/cart/items/' . (int) $_POST['item']);
                break;
        }
        redirect('cart');
    } catch (ApiError $e) {
        $error = $e->getMessage();
        if (($_POST['action'] ?? '') === 'add' && !empty($_SERVER['HTTP_REFERER'])) { flash($error, 'err'); header('Location: ' . $_SERVER['HTTP_REFERER'], true, 303); exit; }
    }
}

$cart = Store::cart(true);
$flash = flash();
View::meta(['title' => 'Your cart | ' . $S['name'], 'noindex' => true]);
$cross = [];
if ($cart['items'] && !array_filter($cart['items'], fn($i) => $i['product']['category'] === 'pillows')) {
    $cross = Store::api()->get('/products', ['category' => 'pillows', 'sort' => 'popular', 'per_page' => 4])['items'];
}
$waLines = ["Hello {$S['name']}, I'd like to order:"];
foreach ($cart['items'] as $i) $waLines[] = "• {$i['qty']} × {$i['product']['name']} ({$i['size_label']}) — " . naira($i['line_total']);
$waLines[] = 'Subtotal: ' . naira($cart['subtotal']);
?>
<div class="wrap narrow-wide">
  <h1 class="page-title">Your cart</h1>
  <?php if ($flash): ?><p class="notice <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></p><?php endif ?>
  <?php if ($error): ?><p class="notice err"><?= e($error) ?></p><?php endif ?>

  <?php if (!$cart['items']): ?>
    <div class="empty">
      <?= icon('cart', 'i-xl') ?>
      <h2>Your cart is empty</h2>
      <p>Find the right mattress in a minute with our buyer's guide, or browse everything.</p>
      <p><a class="btn btn-primary" href="<?= url('mattresses') ?>">Shop mattresses</a> <a class="btn btn-ghost" href="<?= url('buyers-guide') ?>">Buyer's guide</a></p>
    </div>
  <?php else: ?>
  <div class="cart-layout">
    <section class="cart-lines" data-cart>
      <?php foreach ($cart['items'] as $i): ?>
      <article class="line" data-line="<?= (int) $i['id'] ?>">
        <a class="line-art" href="<?= url("product/{$i['product']['slug']}") ?>"><?= product_art(['slug' => $i['product']['slug'], 'name' => $i['product']['name'], 'type' => $i['product']['type'], 'brand' => ['color' => $i['product']['brand_color'], 'name' => $i['product']['brand']]]) ?></a>
        <div class="line-info">
          <span class="muted small"><?= e($i['product']['brand']) ?></span>
          <a href="<?= url("product/{$i['product']['slug']}") ?>"><strong><?= e($i['product']['name']) ?></strong></a>
          <span class="muted"><?= e(str_replace(' X ', ' × ', $i['size_label'])) ?></span>
          <?php if ($i['stock'] <= 2): ?><span class="low small">Only <?= (int) $i['stock'] ?> left</span><?php endif ?>
        </div>
        <form method="post" action="<?= url('cart') ?>" class="line-qty" data-qty-form>
          <?= csrf_field() ?><input type="hidden" name="action" value="qty"><input type="hidden" name="item" value="<?= (int) $i['id'] ?>">
          <div class="qty" data-qty><button type="button" data-step="-1" aria-label="Less">−</button><input type="number" name="qty" value="<?= (int) $i['qty'] ?>" min="0" max="<?= (int) $i['stock'] ?>" aria-label="Quantity"><button type="button" data-step="1" aria-label="More">+</button></div>
          <noscript><button class="btn btn-ghost btn-sm">Update</button></noscript>
        </form>
        <div class="line-price"><strong data-line-total><?= naira($i['line_total']) ?></strong><?php if ($i['qty'] > 1): ?><span class="muted small"><?= naira($i['unit_price']) ?> each</span><?php endif ?></div>
        <form method="post" action="<?= url('cart') ?>" class="line-remove" data-remove-form>
          <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="item" value="<?= (int) $i['id'] ?>">
          <button class="icon-btn" aria-label="Remove <?= e($i['product']['name']) ?>"><?= icon('close') ?></button>
        </form>
      </article>
      <?php endforeach ?>
      <a class="back-link" href="<?= url('shop') ?>">← Continue shopping</a>
    </section>

    <aside class="summary">
      <h2>Order summary</h2>
      <div class="sum-row"><span>Subtotal (<span data-cart-items><?= (int) $cart['count'] ?></span> item<?= $cart['count'] === 1 ? '' : 's' ?>)</span><strong data-subtotal><?= naira($cart['subtotal']) ?></strong></div>
      <div class="sum-row muted"><span>Delivery</span><span>Calculated at checkout</span></div>
      <p class="small muted">Delivery in Abuja from <?= naira(min(array_column($S['zones'], 'fee'))) ?>, to Lagos in 2–3 days, or free pickup from our showroom.</p>
      <a class="btn btn-primary btn-lg btn-block" href="<?= url('checkout') ?>">Checkout</a>
      <a class="btn btn-wa btn-block" href="<?= e(Store::whatsapp(implode("\n", $waLines))) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Send cart on WhatsApp</a>
      <ul class="assurances compact">
        <li><?= icon('cash') ?> Pay on delivery available</li>
        <li><?= icon('shield') ?> Genuine, sealed products</li>
      </ul>
    </aside>
  </div>

  <?php if ($cross): ?>
  <section class="section">
    <div class="section-head"><h2>Complete your bed</h2></div>
    <div class="grid"><?php foreach ($cross as $p) View::partial('product-card', ['p' => $p]) ?></div>
  </section>
  <?php endif ?>
  <?php endif ?>
</div>
