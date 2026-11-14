<?php
$S = Store::info();
$api = Store::api();
$hero = $api->get('/banners', ['placement' => 'home_hero'])[0] ?? null;
$mid = $api->get('/banners', ['placement' => 'home_mid']);
$featured = $api->get('/products', ['featured' => 1, 'per_page' => 4])['items'];
$new = $api->get('/products', ['new' => 1, 'per_page' => 4])['items'];
$cats = $api->get('/categories');
$brands = $api->get('/brands');
$faqs = $api->get('/faqs');
$posts = $api->get('/posts', ['per_page' => 3])['items'];
$loc = Store::location();
$open = Store::openNow($loc['hours']);
View::meta(['title' => $S['seo']['title'], 'description' => $S['seo']['description'], 'canonical' => '/']);
View::$bodyClass = 'home';
$heroProduct = $featured[0] ?? null;
?>
<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <p class="eyebrow"><?= icon('shield') ?> Official Vitafoam dealer · Mouka &amp; Winco too</p>
      <h1><?= e($hero['title'] ?? 'Sleep better tonight') ?></h1>
      <p class="lead"><?= e($hero['subtitle'] ?? $S['tagline']) ?></p>
      <div class="hero-ctas">
        <a class="btn btn-primary btn-lg" href="<?= url($hero['link'] ?? '/shop') ?>">Shop mattresses</a>
        <a class="btn btn-ghost btn-lg" href="<?= url('buyers-guide') ?>"><?= icon('spark') ?> Find my mattress</a>
      </div>
      <ul class="hero-points">
        <li><?= icon('check') ?> Order before <?= e(date('ga', strtotime($S['same_day_cutoff']))) ?>, sleep on it tonight</li>
        <li><?= icon('check') ?> Pay when it arrives in Abuja · Lagos in 2–3 days</li>
        <li><?= icon('check') ?> Every size, 6ft × 3.5ft to 6ft × 7ft</li>
      </ul>
    </div>
    <div class="hero-art">
      <?php if ($heroProduct): ?>
      <a href="<?= url("product/{$heroProduct['slug']}") ?>" class="hero-product">
        <?= product_art($heroProduct, 'big') ?>
        <span class="hero-chip"><strong><?= e($heroProduct['name']) ?></strong><span>from <?= naira($heroProduct['price_from']) ?></span></span>
      </a>
      <?php endif ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <form class="quickguide" action="<?= url('buyers-guide') ?>">
      <div class="qg-intro"><h2>Not sure which mattress?</h2><p>Tell us two things and we'll show the ones built for you.</p></div>
      <label>Your weight
        <select name="weight_kg"><option value="60">Under 60kg</option><option value="80" selected>60–80kg</option><option value="100">80–100kg</option><option value="120">100–120kg</option><option value="140">Over 120kg</option></select>
      </label>
      <label>You sleep on your
        <select name="position"><option value="back">Back</option><option value="side">Side</option><option value="stomach">Stomach</option><option value="mixed" selected>All of them</option></select>
      </label>
      <label class="check"><input type="checkbox" name="back_pain" value="1"> I have back pain</label>
      <button class="btn btn-accent">Show my matches</button>
    </form>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="section-head"><h2>Shop by category</h2></div>
    <div class="cat-grid">
      <?php foreach ($cats as $c): ?>
      <a class="cat" href="<?= url($c['slug']) ?>">
        <span class="cat-art"><?= product_art(['slug' => $c['slug'], 'name' => $c['name'], 'type' => ['mattresses' => 'orthopedic', 'pillows' => 'memory-pillow', 'toppers' => 'topper', 'baby' => 'baby-mattress', 'furniture' => 'sofa-bed'][$c['slug']] ?? 'foam', 'brand' => ['color' => 'var(--accent)', 'name' => '']]) ?></span>
        <strong><?= e($c['name']) ?></strong><span class="muted"><?= (int) $c['product_count'] ?> products</span>
      </a>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="section-head"><h2>Best sellers</h2><a href="<?= url('shop', ['sort' => 'popular']) ?>">See all <?= icon('chev') ?></a></div>
    <div class="grid"><?php foreach ($featured as $p) View::partial('product-card', ['p' => $p]) ?></div>
  </div>
</section>

<section class="section">
  <div class="wrap promo-grid">
    <?php foreach ($mid as $b): ?>
    <a class="promo" href="<?= url($b['link']) ?>"><h3><?= e($b['title']) ?></h3><p><?= e($b['subtitle']) ?></p><span class="btn btn-light">Learn more</span></a>
    <?php endforeach ?>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="section-head"><h2>Shop by brand</h2><a href="<?= url('brands') ?>">All brands <?= icon('chev') ?></a></div>
    <div class="brand-grid">
      <?php foreach ($brands as $b): ?>
      <a class="brand-tile" href="<?= url("brands/{$b['slug']}") ?>" style="--brand:<?= e($b['color']) ?>">
        <span class="brand-name"><?= e($b['name']) ?></span><span class="muted"><?= (int) $b['product_count'] ?> products · <?= e($b['warranty_note']) ?></span>
      </a>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <div class="section-head"><h2>New arrivals</h2><a href="<?= url('shop', ['new' => 1]) ?>">See all <?= icon('chev') ?></a></div>
    <div class="grid"><?php foreach ($new as $p) View::partial('product-card', ['p' => $p]) ?></div>
  </div>
</section>

<section class="section visit-band">
  <div class="wrap visit-grid">
    <div>
      <p class="eyebrow"><?= icon('store') ?> Our showroom</p>
      <h2>Lie on it before you buy it</h2>
      <p>Every mattress we sell is on display at <?= e($loc['name']) ?>. Bring your partner, try them all, and take one home the same day or have it delivered free of hassle.</p>
      <ul class="facts">
        <li><?= icon('map') ?> <?= e($loc['street']) ?> <span class="muted">· <?= e($loc['landmark']) ?></span></li>
        <li><?= icon('clock') ?> <?= e(Store::hoursText($loc['hours'])) ?> <span class="pill <?= $open ? 'pill-ok' : 'pill-muted' ?>"><?= $open ? 'Open now' : 'Closed now' ?></span></li>
      </ul>
      <a class="btn btn-primary" href="<?= url('visit-us') ?>">Get directions</a>
    </div>
    <iframe class="map" title="Map to <?= e($loc['name']) ?>" loading="lazy" src="https://maps.google.com/maps?q=<?= e($loc['lat'] . ',' . $loc['lng']) ?>&z=15&output=embed"></iframe>
  </div>
</section>

<section class="section">
  <div class="wrap two-col">
    <div>
      <div class="section-head"><h2>Questions, answered</h2><a href="<?= url('faq') ?>">All FAQs <?= icon('chev') ?></a></div>
      <?php View::partial('faq-list', ['groups' => [['group' => '', 'items' => array_slice(array_merge(...array_column($faqs, 'items')), 0, 5)]], 'schema' => true]) ?>
    </div>
    <div>
      <div class="section-head"><h2>From the blog</h2><a href="<?= url('blog') ?>">All posts <?= icon('chev') ?></a></div>
      <div class="post-list">
        <?php foreach ($posts as $post): ?>
        <a class="post-row" href="<?= url("blog/{$post['slug']}") ?>"><span class="muted small"><?= e($post['category']['name'] ?? 'News') ?> · <?= (int) $post['reading_minutes'] ?> min read</span><strong><?= e($post['title']) ?></strong><span class="muted"><?= e($post['excerpt']) ?></span></a>
        <?php endforeach ?>
      </div>
    </div>
  </div>
</section>
