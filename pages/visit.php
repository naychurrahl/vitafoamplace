<?php
$S = Store::info();
$loc = Store::location();
$open = Store::openNow($loc['hours']);
$days = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
View::meta(['title' => "Visit our mattress showroom in Abuja — {$loc['name']} | {$S['name']}", 'description' => "Try every mattress before you buy at {$loc['name']}, {$loc['street']}. " . Store::hoursText($loc['hours']) . '. Free pickup for online orders.', 'canonical' => '/visit-us']);
View::jsonld(['@type' => 'FurnitureStore', 'name' => $loc['name'], 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $loc['street'], 'addressLocality' => 'Abuja', 'addressCountry' => 'NG'],
    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => $loc['lat'], 'longitude' => $loc['lng']], 'telephone' => phone_tel(Store::contact('phone')),
    'openingHoursSpecification' => array_values(array_filter(array_map(fn($k, $v) => $v ? ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days[$k], 'opens' => $v[0], 'closes' => $v[1]] : null, array_keys($loc['hours']), $loc['hours'])))]);
$directions = 'https://www.google.com/maps/dir/?api=1&destination=' . $loc['lat'] . ',' . $loc['lng'];
?>
<div class="wrap">
  <?php View::breadcrumbs([['Home', '/'], ['Visit us', null]]) ?>
  <header class="page-head"><h1>Visit our showroom</h1><p class="lead">Lie on every mattress we sell, compare firmness side by side, and take yours home the same day.</p></header>
  <div class="visit-layout">
    <iframe class="map tall" title="Map to <?= e($loc['name']) ?>" loading="lazy" src="https://maps.google.com/maps?q=<?= e($loc['lat'] . ',' . $loc['lng']) ?>&z=16&output=embed"></iframe>
    <div class="card-box">
      <h2><?= e($loc['name']) ?> <span class="pill <?= $open ? 'pill-ok' : 'pill-muted' ?>"><?= $open ? 'Open now' : 'Closed now' ?></span></h2>
      <p><?= icon('map') ?> <?= e($loc['street']) ?><br><span class="muted"><?= e($loc['landmark']) ?></span></p>
      <p class="muted"><?= e($loc['directions']) ?></p>
      <table class="hours"><?php foreach ($days as $k => $d): ?><tr class="<?= strtolower(date('D')) === $k ? 'today' : '' ?>"><th><?= $d ?></th><td><?= $loc['hours'][$k] ? date('g:ia', strtotime($loc['hours'][$k][0])) . ' – ' . date('g:ia', strtotime($loc['hours'][$k][1])) : 'Closed' ?></td></tr><?php endforeach ?></table>
      <div class="help-row">
        <a class="btn btn-primary" href="<?= e($directions) ?>" target="_blank" rel="noopener"><?= icon('map') ?> Get directions</a>
        <a class="btn btn-ghost" href="tel:<?= e(phone_tel(Store::contact('phone'))) ?>"><?= icon('phone') ?> Call ahead</a>
      </div>
    </div>
  </div>
  <section class="section three-col">
    <div class="card-box"><?= icon('store', 'i-lg') ?><h3>Try before you buy</h3><p>Every mattress, pillow and topper we sell is on display.</p></div>
    <div class="card-box"><?= icon('cart', 'i-lg') ?><h3>Order online, collect here</h3><p>Choose “Pick up” at checkout. Ready in about 2 hours, free.</p></div>
    <div class="card-box"><?= icon('truck', 'i-lg') ?><h3>Or we deliver</h3><p>Buy in store and we deliver the same day across Abuja, or to Lagos in 2–3 working days.</p></div>
  </section>
</div>
