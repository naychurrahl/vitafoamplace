<?php
/** Landing pages: buyer's guides (/buyers-guide/{slug}) and product-type pages at a CMS-set path (/orthopedic-mattresses). */
$S = Store::info();
$g = $landing ?? Store::api()->get("/guide-pages/$slug");
$isType = in_array($g['kind'], ['type', 'size'], true);
$cat = $g['rules']['category'] ?? null;
View::meta([
    'title' => $g['seo_title'] ?: "{$g['title']} | {$S['name']}",
    'description' => $g['seo_description'] ?: mb_strimwidth($g['intro'], 0, 158, '…'),
    'canonical' => $g['path'],
]);
View::jsonld(['@type' => 'ItemList', 'name' => $g['title'], 'itemListElement' => array_map(fn($p, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => View::absolute("/product/{$p['slug']}"), 'name' => $p['name']], $g['items'], array_keys($g['items']))]);
$crumbs = match (true) {
    $isType => [['Home', '/'], [ucfirst($cat ?? 'mattresses'), '/' . ($cat ?? 'mattresses')], [$g['title'], null]],
    $g['kind'] === 'local' => [['Home', '/'], [$g['title'], null]],
    default => [['Home', '/'], ["Buyer's guide", '/buyers-guide'], [$g['title'], null]],
};
if ($g['kind'] === 'local') {
    // City and dealer pages: the shop as a local business serving this city.
    $loc = Store::location();
    View::jsonld(['@type' => 'FurnitureStore', 'name' => $S['name'], 'url' => View::absolute('/'), 'telephone' => phone_tel(Store::contact('phone') ?? ''),
                  'address' => ['@type' => 'PostalAddress', 'streetAddress' => $loc['street'], 'addressLocality' => 'Abuja', 'addressCountry' => 'NG'],
                  'areaServed' => ['@type' => 'City', 'name' => $g['city'] ?: 'Abuja']]);
}
?>
<div class="wrap">
  <?php View::breadcrumbs($crumbs) ?>
  <header class="page-head">
    <h1><?= e($g['title']) ?></h1>
    <p class="lead"><?= e($g['intro']) ?></p>
    <?php if ($g['price']): ?><p class="muted"><?= count($g['items']) ?> products · <?= naira($g['price']['min']) ?> – <?= naira($g['price']['max']) ?> · <a href="<?= url($cat === 'pillows' ? 'pillow-prices' : 'mattress-prices') ?>">see every size and price</a></p><?php endif ?>
    <?php if ($g['kind'] === 'local'): ?>
    <p class="hero-ctas"><a class="btn btn-primary" href="<?= url('mattresses') ?>">Shop mattresses</a> <a class="btn btn-wa" href="<?= e(Store::whatsapp("Hello, I'm in " . ($g['city'] ?: 'Abuja') . ' and want to order a mattress.')) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> Order on WhatsApp</a></p>
    <?php elseif ($cat !== 'pillows'): ?>
    <p><a class="btn btn-ghost" href="<?= url('buyers-guide') ?>"><?= icon('spark') ?> Get a personal match</a></p>
    <?php endif ?>
  </header>

  <?php if ($g['items']): ?>
  <div class="grid"><?php foreach ($g['items'] as $p) View::partial('product-card', ['p' => $p]) ?></div>
  <?php else: ?>
  <div class="empty"><h2>Nothing in stock here right now</h2><p><a href="<?= e(Store::whatsapp("Hello, I'm looking for: {$g['title']}")) ?>" target="_blank" rel="noopener">Ask us on WhatsApp</a> — we can usually order it in.</p></div>
  <?php endif ?>

  <?php if (!empty($g['zones'])): $pod = $g['zones'][0]['pay_on_delivery']; ?>
  <section class="city-zones">
    <h2>Delivery fees in <?= e($g['city']) ?></h2>
    <div class="table-scroll"><table class="zones">
      <thead><tr><th>Area</th><th>Fee</th><th>When</th></tr></thead>
      <tbody>
        <?php foreach ($g['zones'] as $z): ?><tr><td><?= e($z['name']) ?></td><td><?= naira($z['fee']) ?></td><td><?= $z['same_day'] ? '<span class="pill pill-ok">Same day</span>' : '' ?> <?= e($z['eta_text']) ?></td></tr><?php endforeach ?>
      </tbody>
    </table></div>
    <p class="muted small"><?= $pod ? 'Pay on delivery by cash, transfer or POS.' : 'Paid online or by bank transfer before dispatch.' ?> <a href="<?= url('delivery-and-payment') ?>">All delivery areas</a></p>
  </section>
  <?php endif ?>

  <div class="landing-more">
    <?php if ($g['body']): ?><section class="prose"><?= md($g['body']) ?></section><?php endif ?>
    <?php if ($g['faqs']): ?><section><h2>Questions</h2><?php View::partial('faq-list', ['groups' => [['group' => '', 'items' => $g['faqs']]], 'schema' => true]) ?></section><?php endif ?>
  </div>

  <?php if ($g['related']): ?>
  <section class="section">
    <h2><?= match (true) { $isType => 'More ways to shop ' . ($cat ?? 'mattresses'), $g['kind'] === 'local' => 'Where we deliver', default => 'More buying guides' } ?></h2>
    <nav class="chips"><?php foreach ($g['related'] as $r): ?><a class="chip-link" href="<?= url($r['path']) ?>"><?= e($r['title']) ?></a><?php endforeach ?></nav>
  </section>
  <?php endif ?>
</div>
