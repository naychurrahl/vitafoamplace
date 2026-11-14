<?php
$S = Store::info();
$api = Store::api();

// Review form (works without JS)
$reviewErrors = [];
$reviewMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'review') {
    csrf_check();
    try {
        $reviewMsg = $api->post("/products/$slug/reviews", ['rating' => $_POST['rating'] ?? 0, 'name' => $_POST['name'] ?? '', 'title' => $_POST['title'] ?? '', 'body' => $_POST['body'] ?? ''])['message'];
    } catch (ApiError $e) {
        $reviewErrors = $e->fields() ?: ['body' => $e->getMessage()];
    }
}

$p = $api->get("/products/$slug");
$related = $api->get("/products/$slug/related");
$reviews = $api->get("/products/$slug/reviews");
$variants = $p['variants'];
$selected = null;
// ?size= takes the readable size (6x6x10) or the variant id.
foreach ($variants as $v) if (in_array((string) ($_GET['size'] ?? ''), [(string) $v['id'], $v['slug']], true)) $selected = $v;
$selected ??= array_values(array_filter($variants, fn($v) => $v['in_stock']))[0] ?? $variants[0] ?? null;
$loc = Store::location();
$cutoff = $S['same_day_cutoff'];
$isMattress = $p['category']['slug'] === 'mattresses';

View::meta([
    // Per-product SEO from the CMS wins; otherwise a sensible automatic title and description.
    'title' => ($p['seo']['title'] ?? null) ?: "{$p['name']} – " . ($p['sizes'] > 1 ? 'all sizes, ' : '') . 'price in Abuja | ' . $S['name'],
    'description' => ($p['seo']['description'] ?? null) ?: mb_strimwidth("Buy the genuine {$p['name']} by {$p['brand']['name']} from " . naira($p['price_from']) . '. ' . ($p['max_load_kg'] ? "Supports up to {$p['max_load_kg']}kg. " : '') . 'Same-day delivery in Abuja, pay on delivery.', 0, 158, '…'),
    'canonical' => "/product/$slug", 'og_type' => 'product',
]);
View::jsonld(array_filter([
    '@type' => 'Product', 'name' => $p['name'], 'brand' => ['@type' => 'Brand', 'name' => $p['brand']['name']], 'sku' => $selected['sku'] ?? null,
    'description' => strtok($p['description'], "\n"), 'category' => $p['category']['name'], 'image' => array_column($p['media'], 'url') ?: null,
    'offers' => ['@type' => 'AggregateOffer', 'priceCurrency' => 'NGN', 'lowPrice' => $p['price_from'], 'highPrice' => $p['price_to'], 'offerCount' => count($variants),
                 'availability' => $p['in_stock'] ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock'],
    'aggregateRating' => $p['rating']['count'] ? ['@type' => 'AggregateRating', 'ratingValue' => $p['rating']['avg'], 'reviewCount' => $p['rating']['count']] : null,
]));
$waText = "Hello {$S['name']}, I'd like to order:\n{$p['name']}" . ($selected ? " ({$selected['size_label']}) — " . naira($selected['price']) : '') . "\n" . View::absolute("/product/$slug");
$specRows = array_filter([
    'Brand' => $p['brand']['name'], 'Type' => type_label($p['type']), 'Firmness' => $p['firmness'] ? "{$p['firmness']} / 5 · " . firmness_label($p['firmness']) : null,
    'Max load per sleeper' => $p['max_load_kg'] ? "Up to {$p['max_load_kg']}kg (thickest size)" : null, 'Density' => $p['density'], 'Materials' => $p['materials'],
    'Cover' => $p['cover'], 'Warranty' => $p['warranty_years'] ? "{$p['warranty_years']} year" . ($p['warranty_years'] > 1 ? 's' : '') : null,
] + $p['specs']);
?>
<div class="wrap">
  <?php View::breadcrumbs([['Home', '/'], [$p['category']['name'], "/{$p['category']['slug']}"], [$p['brand']['name'], "/brands/{$p['brand']['slug']}"], [$p['name'], null]]) ?>

  <div class="pdp" data-product='<?= e(json_encode(['id' => $p['id'], 'name' => $p['name'], 'slug' => $slug, 'variants' => $variants, 'whatsapp' => Store::contact('whatsapp'), 'store' => $S['name']])) ?>'>
    <div class="pdp-media">
      <div class="pdp-art"><?= product_art($p, 'big') ?>
        <span class="tags"><?php if ($p['on_sale']): ?><span class="tag tag-sale">Sale</span><?php endif ?><?php if ($p['new_arrival']): ?><span class="tag tag-new">New</span><?php endif ?></span>
      </div>
      <?php if (count($p['media']) > 1): ?>
      <div class="pdp-thumbs"><?php foreach (array_slice($p['media'], 1) as $m): ?><img src="<?= e($m['url']) ?>" alt="<?= e($m['alt'] ?: $p['name']) ?>" loading="lazy" width="<?= (int) ($m['width'] ?? 0) ?: '' ?>" height="<?= (int) ($m['height'] ?? 0) ?: '' ?>"><?php endforeach ?></div>
      <?php endif ?>
      <?php if ($p['good_for']): ?>
      <div class="goodfor"><span class="muted small">Good for</span><?php foreach ($p['good_for'] as $g): ?><span class="pill"><?= e(good_label($g)) ?></span><?php endforeach ?></div>
      <?php endif ?>
    </div>

    <div class="pdp-info">
      <a class="pdp-brand" href="<?= url("brands/{$p['brand']['slug']}") ?>"><?= e($p['brand']['name']) ?></a>
      <h1><?= e($p['name']) ?></h1>
      <div class="pdp-sub">
        <?php if ($p['rating']['count']): ?><a href="#reviews"><?= stars($p['rating']['avg'], $p['rating']['count']) ?></a><?php endif ?>
        <span><?= e(type_label($p['type'])) ?></span>
        <?php if ($p['warranty_years']): ?><span><?= icon('shield') ?> <?= (int) $p['warranty_years'] ?>-year warranty</span><?php endif ?>
      </div>

      <?php if ($p['firmness']): ?>
      <div class="firm-scale" aria-label="Firmness <?= (int) $p['firmness'] ?> of 5">
        <div class="firm-track"><span style="left:<?= ($p['firmness'] - 1) * 25 ?>%"></span></div>
        <div class="firm-labels"><span>Soft</span><span>Medium</span><span>Extra firm</span></div>
      </div>
      <?php endif ?>

      <form class="buybox" method="post" action="<?= url('cart') ?>" data-add-form>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <div class="price-row">
          <span class="price big" data-price><?= naira($selected['price'] ?? 0) ?></span>
          <s class="muted" data-compare <?= empty($selected['compare_at_price']) ? 'hidden' : '' ?>><?= naira($selected['compare_at_price'] ?? 0) ?></s>
          <span class="pill pill-sale" data-save <?= empty($selected['compare_at_price']) ? 'hidden' : '' ?>>Save <?= naira(($selected['compare_at_price'] ?? 0) - ($selected['price'] ?? 0)) ?></span>
        </div>

        <?php if (count($variants) > 1): ?>
        <fieldset class="sizes">
          <legend>Choose a size <span class="muted">(width × length × thickness)</span></legend>
          <div class="size-grid">
          <?php foreach ($variants as $v): ?>
            <label class="size<?= $v['in_stock'] ? '' : ' is-out' ?>">
              <input type="radio" name="variant_id" value="<?= (int) $v['id'] ?>" <?= $selected && $v['id'] === $selected['id'] ? 'checked' : '' ?> <?= $v['in_stock'] ? '' : 'disabled' ?> required>
              <span class="size-box">
                <strong><?= e(str_replace(' X ', ' × ', $v['size_label'])) ?></strong>
                <span><?= naira($v['price']) ?><?php if ($v['max_load_kg']): ?> · <?= (int) $v['max_load_kg'] ?>kg<?php endif ?></span>
                <?php if (!$v['in_stock']): ?><em>Out of stock</em><?php elseif ($v['stock'] <= 2): ?><em class="low">Only <?= (int) $v['stock'] ?> left</em><?php endif ?>
              </span>
            </label>
          <?php endforeach ?>
          </div>
        </fieldset>
        <?php elseif ($selected): ?>
          <input type="hidden" name="variant_id" value="<?= (int) $selected['id'] ?>">
          <p class="muted"><?= e($selected['size_label']) ?></p>
        <?php endif ?>

        <div class="stock-line" data-stock>
          <?php if ($selected && $selected['in_stock']): ?><span class="ok"><?= icon('check') ?> In stock<?= $selected['stock'] <= 2 ? " · only {$selected['stock']} left" : '' ?></span>
          <?php else: ?><span class="bad">Out of stock in this size</span><?php endif ?>
          <?php if ($isMattress && $selected['max_load_kg']): ?><span data-load><?= icon('weight') ?> Up to <?= (int) $selected['max_load_kg'] ?>kg per sleeper</span><?php endif ?>
        </div>

        <div class="buy-actions">
          <div class="qty" data-qty><button type="button" aria-label="Less" data-step="-1">−</button><input type="number" name="qty" value="1" min="1" max="<?= (int) ($selected['stock'] ?? 1) ?>" inputmode="numeric" aria-label="Quantity"><button type="button" aria-label="More" data-step="1">+</button></div>
          <button class="btn btn-primary btn-lg grow" data-add <?= $p['in_stock'] ? '' : 'disabled' ?>><?= icon('cart') ?> Add to cart</button>
          <button class="icon-btn wish-lg" type="button" data-wish="<?= (int) $p['id'] ?>" aria-label="Save to wishlist" aria-pressed="false"><?= icon('heart') ?></button>
        </div>
        <a class="btn btn-wa btn-block" data-wa-order href="<?= e(Store::whatsapp($waText)) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Order on WhatsApp</a>
      </form>

      <ul class="assurances">
        <li data-cutoff="<?= e($cutoff) ?>"><?= icon('truck') ?> <span data-countdown><?= date('H:i') < $cutoff ? 'Order before ' . date('ga', strtotime($cutoff)) . ' for <strong>same-day delivery</strong> in Abuja' : 'Order now for delivery <strong>tomorrow</strong>' ?></span> · <a href="<?= url('delivery-and-payment') ?>">fees by area</a></li>
        <li><?= icon('store') ?> <span><strong>Free pickup</strong> or try it at <?= e($loc['name']) ?></span></li>
        <li><?= icon('truck') ?> <span><strong>Delivery to Lagos</strong> in 2–3 working days · <a href="<?= url('buy-mattress-lagos') ?>">Lagos fees</a></span></li>
        <li><?= icon('cash') ?> <span><strong>Pay on delivery</strong> in Abuja by cash, transfer or POS</span></li>
        <li><?= icon('shield') ?> <span><?= $p['brand']['slug'] === 'vitafoam' ? '<strong>Official Vitafoam dealer</strong> — original' : 'Genuine' ?> and sealed, with the <?= e($p['brand']['name']) ?> warranty card</span></li>
      </ul>
    </div>
  </div>

  <div class="tabs" data-tabs>
    <div class="tab-list" role="tablist">
      <button role="tab" aria-selected="true" aria-controls="t-desc" id="tb-desc">Description</button>
      <button role="tab" aria-selected="false" aria-controls="t-spec" id="tb-spec">Specifications</button>
      <button role="tab" aria-selected="false" aria-controls="reviews" id="tb-rev">Reviews (<?= (int) $reviews['summary']['count'] ?>)</button>
      <button role="tab" aria-selected="false" aria-controls="t-del" id="tb-del">Delivery &amp; returns</button>
    </div>
    <section class="tab-panel prose" role="tabpanel" id="t-desc" aria-labelledby="tb-desc"><?= md($p['description']) ?></section>
    <section class="tab-panel" role="tabpanel" id="t-spec" aria-labelledby="tb-spec">
      <table class="specs"><?php foreach ($specRows as $k => $v): ?><tr><th><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach ?></table>
      <?php if (count($variants) > 1): ?>
      <h3>Sizes and prices</h3>
      <div class="table-scroll"><table class="specs sizes-table"><thead><tr><th>Size</th><th>Thickness</th><th>Max load</th><th>Price</th><th>Stock</th></tr></thead><tbody>
      <?php foreach ($variants as $v): ?><tr><td><?= e(preg_replace('/ X \d+"$/', '', $v['size_label'])) ?></td><td><?= $v['thickness_in'] ? (float) $v['thickness_in'] . '"' : '–' ?></td><td><?= $v['max_load_kg'] ? (int) $v['max_load_kg'] . 'kg' : '–' ?></td><td><?= naira($v['price']) ?></td><td><?= $v['in_stock'] ? 'In stock' : '<span class="muted">Out</span>' ?></td></tr><?php endforeach ?>
      </tbody></table></div>
      <?php endif ?>
    </section>
    <section class="tab-panel" role="tabpanel" id="reviews" aria-labelledby="tb-rev">
      <div class="reviews">
        <div class="review-summary">
          <?php if ($reviews['summary']['count']): ?>
            <div class="big-rating"><?= e($reviews['summary']['avg']) ?></div><?= stars($reviews['summary']['avg'], 0, false) ?><p class="muted"><?= (int) $reviews['summary']['count'] ?> reviews</p>
            <?php foreach ($reviews['summary']['breakdown'] as $n => $c): ?><div class="bar"><span><?= $n ?>★</span><i><b style="width:<?= $reviews['summary']['count'] ? round($c / $reviews['summary']['count'] * 100) : 0 ?>%"></b></i><span><?= $c ?></span></div><?php endforeach ?>
          <?php else: ?><p>No reviews yet. Be the first.</p><?php endif ?>
        </div>
        <div class="review-list">
          <?php foreach ($reviews['items'] as $r): ?>
          <article class="review"><?= stars($r['rating'], 0, false) ?> <strong><?= e($r['title']) ?></strong><p><?= e($r['body']) ?></p><p class="muted small"><?= e($r['name']) ?><?= $r['verified'] ? ' · ' . icon('check') . ' Verified buyer' : '' ?> · <?= e(when($r['created_at'], 'j M Y')) ?></p></article>
          <?php endforeach ?>
          <details class="review-form" <?= $reviewErrors || $reviewMsg ? 'open' : '' ?>>
            <summary class="btn btn-ghost">Write a review</summary>
            <?php if ($reviewMsg): ?><p class="notice ok"><?= e($reviewMsg) ?></p><?php else: ?>
            <form method="post" action="<?= url("product/$slug") ?>#reviews" class="form">
              <?= csrf_field() ?><input type="hidden" name="action" value="review">
              <fieldset class="rate"><legend>Your rating</legend><?php for ($i = 5; $i >= 1; $i--): ?><input type="radio" id="r<?= $i ?>" name="rating" value="<?= $i ?>" <?= (int) ($_POST['rating'] ?? 0) === $i ? 'checked' : '' ?>><label for="r<?= $i ?>" title="<?= $i ?> stars">★</label><?php endfor ?></fieldset>
              <?php if (isset($reviewErrors['rating'])): ?><p class="err"><?= e($reviewErrors['rating']) ?></p><?php endif ?>
              <label>Your name<input name="name" value="<?= e($_POST['name'] ?? '') ?>" required></label>
              <?php if (isset($reviewErrors['name'])): ?><p class="err"><?= e($reviewErrors['name']) ?></p><?php endif ?>
              <label>Title <span class="muted">(optional)</span><input name="title" value="<?= e($_POST['title'] ?? '') ?>"></label>
              <label>Your review<textarea name="body" rows="4" required><?= e($_POST['body'] ?? '') ?></textarea></label>
              <?php if (isset($reviewErrors['body'])): ?><p class="err"><?= e($reviewErrors['body']) ?></p><?php endif ?>
              <button class="btn btn-primary">Submit review</button>
            </form>
            <?php endif ?>
          </details>
        </div>
      </div>
    </section>
    <section class="tab-panel prose" role="tabpanel" id="t-del" aria-labelledby="tb-del">
      <p><strong>Delivery in Abuja:</strong> same day if you order before <?= e(date('g a', strtotime($cutoff))) ?> (most areas), from <?= naira(min(array_column($S['zones'], 'fee'))) ?>. <a href="<?= url('delivery-and-payment') ?>">See the fee for your area</a>.</p>
      <p><strong>Delivery to Lagos:</strong> by courier in 2–3 working days, from <?= naira(min(array_column(array_filter($S['zones'], fn($z) => ($z['city'] ?? '') === 'Lagos'), 'fee') ?: [0])) ?>. Paid online or by transfer before dispatch. <a href="<?= url('buy-mattress-lagos') ?>">Lagos delivery</a>.</p>
      <p><strong>Pickup:</strong> free from <?= e($loc['name']) ?>, <?= e($loc['street']) ?>.</p>
      <p><strong>Returns:</strong> check the seal and size at the door. If anything is wrong, reject it and we replace it free. <a href="<?= url('returns') ?>">Returns policy</a>.</p>
    </section>
  </div>

  <?php if ($p['faqs']): ?>
  <section class="section narrow-block" id="questions">
    <h2>Questions about the <?= e($p['name']) ?></h2>
    <?php View::partial('faq-list', ['groups' => [['group' => '', 'items' => $p['faqs']]], 'schema' => true]) ?>
    <p class="muted small">Another question? <a href="<?= e(Store::whatsapp("Hello, a question about the {$p['name']}: ")) ?>" target="_blank" rel="noopener">Ask us on WhatsApp</a>.</p>
  </section>
  <?php endif ?>

  <?php if ($related): ?>
  <section class="section">
    <div class="section-head"><h2>You may also like</h2></div>
    <div class="grid"><?php foreach ($related as $r) View::partial('product-card', ['p' => $r]) ?></div>
  </section>
  <?php endif ?>
</div>
