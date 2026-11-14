<?php
// Generated from the zones and payment methods set in the CMS, so it can't contradict checkout.
$S = Store::info();
$cut = date('g a', strtotime($S['same_day_cutoff']));
$loc = Store::location();
$cities = Store::zoneCities();
View::meta(['title' => "Mattress Delivery in Abuja, Lagos & Nigeria – Fees & Payment | {$S['name']}",
            'description' => "Same-day mattress delivery in Abuja before $cut, 2–3 day delivery to Lagos, free showroom pickup. Delivery fees for every area and how to pay.",
            'canonical' => '/delivery-and-payment']);
?>
<div class="wrap narrow">
  <?php View::breadcrumbs([['Home', '/'], ['Delivery & payment', null]]) ?>
  <header class="page-head"><h1>Mattress delivery &amp; payment</h1>
    <p class="lead">In Abuja, order before <strong><?= e($cut) ?></strong> and we deliver the same day in the areas marked below. We deliver to Lagos by courier in 2–3 working days. We don't deliver on Sundays.</p>
    <?php if (count($cities) > 1): ?><nav class="chips"><?php foreach (array_keys($cities) as $c): ?><a class="chip-link" href="#<?= e(strtolower($c)) ?>"><?= e($c) ?></a><?php endforeach ?></nav><?php endif ?>
  </header>

  <?php foreach ($cities as $city => $zones): $courier = !$zones[0]['pay_on_delivery']; ?>
  <section id="<?= e(strtolower($city)) ?>">
    <h2>Delivery in <?= e($city) ?></h2>
    <?php if ($courier): ?><p>By courier, paid online or by bank transfer before we dispatch. The mattress price is the same as in Abuja. <a href="<?= url($city === 'Lagos' ? 'buy-mattress-lagos' : 'delivery-and-payment') ?>">More about <?= e($city) ?> delivery</a>.</p><?php endif ?>
    <div class="table-scroll">
    <table class="zones">
      <thead><tr><th>Area</th><th>Fee</th><th>When</th><th>Pay on delivery</th></tr></thead>
      <tbody>
        <?php if (!$courier): ?><tr class="pickup-row"><td><strong>Pickup</strong> — <?= e($loc['name']) ?></td><td><strong>Free</strong></td><td>Ready in about 2 hours</td><td>Yes</td></tr><?php endif ?>
        <?php foreach ($zones as $z): ?><tr><td><?= e($z['name']) ?></td><td><?= naira($z['fee']) ?></td><td><?= $z['same_day'] ? '<span class="pill pill-ok">Same day</span>' : '' ?> <?= e($z['eta_text']) ?></td><td><?= $z['pay_on_delivery'] ? 'Yes' : 'No' ?></td></tr><?php endforeach ?>
      </tbody>
    </table>
    </div>
  </section>
  <?php endforeach ?>
  <p class="muted">Somewhere else in Nigeria? <a href="<?= e(Store::whatsapp('Hello, I need a delivery quote for: ')) ?>" target="_blank" rel="noopener">Ask us for a courier quote</a>.</p>

  <h2>How to pay</h2>
  <div class="pay-cards">
    <?php foreach ($S['payment_methods'] as $pm): ?><div class="card-box"><h3><?= e($pm['label']) ?></h3><p><?= e($pm['instructions']) ?></p></div><?php endforeach ?>
  </div>
</div>
