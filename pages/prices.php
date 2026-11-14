<?php
/** /mattress-prices and /pillow-prices — every size and price, generated from the catalogue so it's never out of date. */
$S = Store::info();
$category = View::$path === 'pillow-prices' ? 'pillows' : 'mattresses';
$brand = preg_match('/^[a-z0-9-]+$/', $_GET['brand'] ?? '') ? $_GET['brand'] : null;
$g = Store::api()->get('/price-guide', array_filter(['category' => $category, 'brand' => $brand]));
$brands = array_column(Store::api()->get('/brands'), 'name', 'slug');
$noun = $category === 'pillows' ? 'Pillow' : 'Mattress';
$month = date('F Y');
View::meta([
    'title' => "$noun Prices in Nigeria ($month) – Updated Price Guide | {$S['name']}",
    'description' => "Current prices for every " . strtolower($noun) . " size we sell: Vitafoam, Mouka and Winco, from " . naira($g['price']['min'] ?? 0) . ". Updated " . date('j F Y') . '. Delivered in Abuja and Lagos.',
    'canonical' => '/' . View::$path, 'noindex' => (bool) $brand,
]);
View::jsonld(['@type' => 'ItemList', 'name' => "$noun prices in Nigeria", 'itemListElement' => array_map(fn($r, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => View::absolute("/product/{$r['product']['slug']}"), 'name' => $r['product']['name']], $g['products'], array_keys($g['products']))]);
$size = fn($v) => $v['width_ft'] ? str_replace('.0', '', "{$v['length_ft']}ft × {$v['width_ft']}ft") : $v['size_label'];
?>
<div class="wrap">
  <?php View::breadcrumbs([['Home', '/'], [$category === 'pillows' ? 'Pillows' : 'Mattresses', "/$category"], ["$noun prices", null]]) ?>
  <header class="page-head">
    <h1><?= e($noun) ?> prices in Nigeria — <?= e($month) ?></h1>
    <p class="lead">The current price of every <?= strtolower($noun) ?> size we sell<?= $g['price'] ? ', from ' . naira($g['price']['min']) . ' to ' . naira($g['price']['max']) : '' ?>. Prices come straight from our stock system, so this page is always up to date.</p>
    <p class="muted small">Last updated <?= e(date('j F Y', strtotime($g['updated_at']))) ?> · prices include VAT · delivery fees are on the <a href="<?= url('delivery-and-payment') ?>">delivery page</a></p>
    <nav class="chips">
      <a class="chip-link <?= $brand ? '' : 'on' ?>" href="<?= url(View::$path) ?>">All brands</a>
      <?php foreach ($brands as $slug => $name): ?><a class="chip-link <?= $brand === $slug ? 'on' : '' ?>" href="<?= url(View::$path, ['brand' => $slug]) ?>"><?= e($name) ?></a><?php endforeach ?>
      <a class="chip-link" href="<?= url($category === 'pillows' ? 'mattress-prices' : 'pillow-prices') ?>"><?= $category === 'pillows' ? 'Mattress' : 'Pillow' ?> prices →</a>
    </nav>
  </header>

  <nav class="toc" aria-label="Products on this page">
    <?php foreach ($g['products'] as $r): ?><a href="#<?= e($r['product']['slug']) ?>"><?= e($r['product']['name']) ?></a><?php endforeach ?>
  </nav>

  <?php foreach ($g['products'] as $r): $p = $r['product']; ?>
  <section class="price-block" id="<?= e($p['slug']) ?>">
    <div class="price-block-head">
      <div>
        <h2><a href="<?= url("product/{$p['slug']}") ?>"><?= e($p['name']) ?></a></h2>
        <p class="muted"><?= e($p['brand']['name']) ?> · <?= e(type_label($p['type'])) ?><?= $p['firmness'] ? ' · ' . e(firmness_label($p['firmness'])) . ' (' . (int) $p['firmness'] . '/5)' : '' ?><?= $p['warranty_years'] ? ' · ' . (int) $p['warranty_years'] . '-year warranty' : '' ?></p>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= url("product/{$p['slug']}") ?>">View &amp; buy</a>
    </div>
    <div class="table-scroll"><table class="specs price-table">
      <thead><tr><th>Size</th><?php if ($category === 'mattresses'): ?><th>Thickness</th><th>Max load</th><?php endif ?><th>Price</th><th></th></tr></thead>
      <tbody><?php foreach ($r['variants'] as $v): ?>
        <tr><td><?= e($size($v)) ?></td>
          <?php if ($category === 'mattresses'): ?><td><?= $v['thickness_in'] ? (float) $v['thickness_in'] . '"' : '–' ?></td><td><?= $v['max_load_kg'] ? (int) $v['max_load_kg'] . 'kg' : '–' ?></td><?php endif ?>
          <td><strong><?= naira($v['price']) ?></strong><?php if ($v['compare_at_price']): ?> <s class="muted"><?= naira($v['compare_at_price']) ?></s><?php endif ?></td>
          <td><?= $v['in_stock'] ? '<a href="' . url("product/{$p['slug']}", ['size' => $v['slug']]) . '">Buy</a>' : '<span class="muted">Out of stock</span>' ?></td></tr>
      <?php endforeach ?></tbody>
    </table></div>
  </section>
  <?php endforeach ?>

  <section class="landing-more"><div class="prose">
    <h2>What affects <?= strtolower($noun) ?> prices?</h2>
    <?php if ($category === 'mattresses'): ?>
    <p><strong>Size and thickness</strong> matter most: a 6ft × 6ft × 10" mattress uses almost twice the foam of a 6ft × 3.5ft × 8". <strong>Type</strong> comes next — orthopedic, memory foam and pocket-spring mattresses cost more than standard foam. Not sure which size fits your bed? See the <a href="<?= url('mattress-sizes') ?>">mattress size guide</a>.</p>
    <?php else: ?>
    <p>Memory foam pillows cost more than fibre pillows because the foam holds its shape for years. See <a href="<?= url('pillows') ?>">all pillows</a>.</p>
    <?php endif ?>
  </div></section>
</div>
