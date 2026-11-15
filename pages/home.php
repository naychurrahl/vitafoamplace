<?php
// Home page, following the client's wireframe (misc/research/Homepage.jpeg):
// hero → delivery & payment facts → buyer's guide → shop by category → work hours.
$S = Store::info();
$api = Store::api();
$hero = $api->get('/banners', ['placement' => 'home_hero'])[0] ?? null;
$cats = $api->get('/categories');
$loc = Store::location();
$open = Store::openNow($loc['hours']);
$phone = Store::contact('phone');
View::meta(['title' => $S['seo']['title'], 'description' => $S['seo']['description'], 'canonical' => '/']);
View::$bodyClass = 'home';

// Delivery facts come from the delivery zones set in the CMS, per city.
$cities = [];
foreach (Store::zoneCities() as $city => $zones) {
    $fees = array_column($zones, 'fee');
    $sameDay = array_filter($zones, fn($z) => $z['same_day']);
    $cities[$city] = [
        'min' => min($fees), 'max' => max($fees),
        'same_day' => $sameDay ? (count($sameDay) === count($zones) ? 'all' : 'most') : null,
        'lead' => min(array_column($zones, 'lead_days')),
        'pod' => (bool) array_filter($zones, fn($z) => $z['pay_on_delivery']),
    ];
}
$sameDayCities = array_keys(array_filter($cities, fn($c) => $c['same_day']));
$laterCities = array_filter($cities, fn($c) => !$c['same_day']);
$payTypes = array_column($S['payment_methods'], 'type');
$podOn = in_array('pod', $payTypes, true);
$payOnline = in_array('paystack', $payTypes, true);
$podCities = $podOn ? array_keys(array_filter($cities, fn($c) => $c['pod'])) : [];
$cutoff = date('ga', strtotime($S['same_day_cutoff']));
$list = fn(array $xs) => count($xs) > 1 ? implode(', ', array_slice($xs, 0, -1)) . ' and ' . end($xs) : ($xs[0] ?? '');

$days = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
$today = strtolower(date('D'));
$hm = fn(string $t) => date(substr($t, 3) === '00' ? 'ga' : 'g:ia', strtotime($t));
$catArt = ['mattresses' => 'orthopedic', 'pillows' => 'memory-pillow', 'toppers' => 'topper', 'baby' => 'baby-mattress', 'furniture' => 'sofa-bed'];
?>
<section class="home-hero">
  <img class="home-hero-img" src="<?= e($hero['image'] ?? null ?: asset('img/home-hero.jpg')) ?>" alt="" width="1280" height="853" fetchpriority="high">
  <div class="wrap home-hero-copy">
    <p class="eyebrow"><?= icon('shield') ?> Official Vitafoam dealer</p>
    <h1><?= e($hero['title'] ?? 'Sleep better tonight') ?></h1>
    <p class="lead"><?= e($hero['subtitle'] ?? $S['tagline']) ?></p>
    <a class="btn btn-accent btn-lg" href="<?= url($hero['link'] ?? '/shop') ?>">Shop now</a>
  </div>
</section>

<section class="home-facts" aria-label="Delivery and payment">
  <div class="wrap home-facts-row">
    <div class="home-fact">
      <?= icon('truck') ?>
      <div>
      <?php if ($sameDayCities): ?>
      <h2>Same-day delivery <?= e(count($sameDayCities) === 1 ? 'within ' . $sameDayCities[0] : 'in ' . $list($sameDayCities)) ?></h2>
      <p><?= in_array('most', array_column($cities, 'same_day'), true) ? 'Most areas, when' : 'When' ?> you order before <?= e($cutoff) ?></p>
      <?php else: ?>
      <h2>Delivered to your door</h2>
      <?php endif ?>
      <?php foreach ($laterCities as $city => $c): ?>
      <p><strong><?= e($city) ?></strong> in <?= $c['lead'] ?> working day<?= $c['lead'] === 1 ? '' : 's' ?></p>
      <?php endforeach ?>
      </div>
    </div>
    <div class="home-fact">
      <?= icon('cash') ?>
      <div>
      <?php if ($podCities): ?>
      <h2>Payment on delivery</h2>
      <p><?= count($podCities) === count($cities) ? 'Everywhere we deliver' : e($list($podCities)) . ' only' ?>: cash, transfer or POS</p>
      <?php if ($payOnline): ?><p class="muted">Or pay online by card, bank or USSD</p><?php endif ?>
      <?php else: ?>
      <h2>Easy payment</h2>
      <p><?= e($list(array_column($S['payment_methods'], 'label'))) ?></p>
      <?php endif ?>
      </div>
    </div>
    <div class="home-fact">
      <?= icon('map') ?>
      <div>
      <h2>Delivery fees</h2>
      <ul>
        <?php foreach ($cities as $city => $c): ?>
        <li><span><?= e(count($cities) > 1 && $c['same_day'] ? "Within $city" : $city) ?></span> <strong><?= $c['min'] === $c['max'] ? '' : 'from ' ?><?= naira($c['min']) ?></strong></li>
        <?php endforeach ?>
      </ul>
      <a href="<?= url('delivery-and-payment') ?>">Fees for every area <?= icon('chev') ?></a>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <h2 class="home-title">Buyer's guide</h2>
    <div class="home-guide">
      <a class="home-guide-art" href="<?= url('buyers-guide') ?>" tabindex="-1" aria-hidden="true">
        <?= product_art(['slug' => 'home-guide', 'name' => '', 'type' => 'orthopedic', 'brand' => ['color' => 'var(--accent)', 'name' => '']]) ?>
      </a>
      <div class="home-guide-body">
        <h3>Find the right mattress in a minute</h3>
        <p>Your weight and the way you sleep decide how firm and how thick your mattress should be. Tell us both and we'll show the ones built for you.</p>
        <form class="home-guide-form" action="<?= url('buyers-guide') ?>">
          <label>Your weight
            <select name="weight_kg"><option value="60">Under 60kg</option><option value="80" selected>60–80kg</option><option value="100">80–100kg</option><option value="120">100–120kg</option><option value="140">Over 120kg</option></select>
          </label>
          <label>You sleep on your
            <select name="position"><option value="back">Back</option><option value="side">Side</option><option value="stomach">Stomach</option><option value="mixed" selected>All of them</option></select>
          </label>
          <label class="check"><input type="checkbox" name="back_pain" value="1"> I have back pain</label>
          <button class="btn btn-primary">Show my matches</button>
        </form>
        <p class="small"><a href="<?= url('buyers-guide') ?>">Read the full buyer's guide <?= icon('chev') ?></a></p>
      </div>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <h2 class="home-title">Shop by category</h2>
    <div class="home-cats">
      <?php foreach ($cats as $c): ?>
      <a class="home-cat" href="<?= url($c['slug']) ?>">
        <span class="home-cat-art"><?= product_art(['slug' => $c['slug'], 'name' => $c['name'], 'image' => $c['image'] ?? null, 'type' => $catArt[$c['slug']] ?? 'foam', 'brand' => ['color' => 'var(--accent)', 'name' => '']]) ?></span>
        <strong><?= e($c['name']) ?></strong>
      </a>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section home-hours-band">
  <div class="wrap home-hours">
    <img class="home-hours-img" src="<?= e(asset('img/home-team.jpg')) ?>" alt="A Vitafoam Place delivery man carrying a new mattress to the van" width="1280" height="853" loading="lazy">
    <div>
      <h2 class="home-title">Work hours</h2>
      <p><span class="pill <?= $open ? 'pill-ok' : 'pill-muted' ?>"><?= $open ? 'Open now' : 'Closed now' ?></span> <span class="muted">at <?= e($loc['name']) ?></span></p>
      <table class="home-hours-table">
        <?php foreach ($days as $k => $label): $h = $loc['hours'][$k] ?? null; ?>
        <tr<?= $k === $today ? ' class="today" aria-current="date"' : '' ?>><th scope="row"><?= $label ?></th><td><?= $h ? e($hm($h[0]) . ' – ' . $hm($h[1])) : 'Closed' ?></td></tr>
        <?php endforeach ?>
      </table>
      <ul class="facts">
        <li><?= icon('map') ?> <?= e($loc['street']) ?> <span class="muted">· <?= e($loc['landmark']) ?></span></li>
      </ul>
      <div class="hero-ctas">
        <?php if ($phone): ?><a class="btn btn-primary" href="tel:<?= e(phone_tel($phone)) ?>"><?= icon('phone') ?> Call <?= e(phone_display($phone)) ?></a><?php endif ?>
        <a class="btn btn-ghost" href="<?= url('visit-us') ?>">Get directions</a>
      </div>
    </div>
  </div>
</section>
