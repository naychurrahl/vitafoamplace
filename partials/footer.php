<?php
$loc = Store::location();
$cats = Store::api()->get('/categories');
?>
<section class="trustbar">
  <div class="wrap">
    <div><?= icon('shield') ?><strong>Genuine brands</strong><span>Sealed, with the maker's warranty</span></div>
    <div><?= icon('truck') ?><strong>Same-day in Abuja</strong><span>Order before <?= e(date('ga', strtotime($S['same_day_cutoff']))) ?> · Lagos in 2–3 days</span></div>
    <div><?= icon('cash') ?><strong>Pay on delivery</strong><span>In Abuja, by cash, transfer or POS</span></div>
    <div><?= icon('store') ?><strong>Free pickup</strong><span>Try before you buy</span></div>
  </div>
</section>
<footer class="footer">
  <div class="wrap footer-grid">
    <div class="footer-brand">
      <a class="logo logo-light" href="<?= url('/') ?>"><span class="logo-mark"><?= e(mb_substr($S['name'], 0, 1)) ?></span><span class="logo-text"><?= e($S['name']) ?></span></a>
      <p><?= e($S['tagline']) ?></p>
      <form class="newsletter" method="post" action="<?= url('newsletter') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="back" value="<?= e('/' . View::$path) ?>">
        <label for="nl-email">Sleep tips and offers, once a month</label>
        <div class="row"><input id="nl-email" type="email" name="email" placeholder="Your email" required><button class="btn btn-accent">Subscribe</button></div>
      </form>
    </div>
    <div>
      <h3>Shop</h3>
      <?php foreach ($cats as $c): ?><a href="<?= url($c['slug']) ?>"><?= e($c['name']) ?></a><?php endforeach ?>
      <a href="<?= url('brands') ?>">Brands</a>
      <?php foreach (Store::api()->get('/guide-pages', ['kind' => 'type']) as $t) if ($t['category'] === 'mattresses'): ?><a href="<?= url($t['path']) ?>"><?= e(preg_replace('/ in Nigeria.*$/', '', $t['title'])) ?></a><?php endif ?>
    </div>
    <div>
      <h3>Help</h3>
      <a href="<?= url('buyers-guide') ?>">Buyer's guide</a>
      <a href="<?= url('mattress-prices') ?>">Mattress prices</a>
      <a href="<?= url('pillow-prices') ?>">Pillow prices</a>
      <a href="<?= url('mattress-sizes') ?>">Mattress sizes</a>
      <a href="<?= url('delivery-and-payment') ?>">Delivery &amp; payment</a>
      <?php foreach (Store::api()->get('/guide-pages', ['kind' => 'local']) as $t): ?><a href="<?= url($t['path']) ?>"><?= e(preg_replace('/ — .*$/', '', $t['title'])) ?></a><?php endforeach ?>
      <a href="<?= url('account') ?>">Track your order</a>
      <a href="<?= url('faq') ?>">FAQ</a>
      <a href="<?= url('returns') ?>">Returns</a>
      <a href="<?= url('warranty') ?>">Warranty</a>
      <a href="<?= url('contact') ?>">Contact us</a>
    </div>
    <div>
      <h3>Visit us</h3>
      <p><?= e($loc['street']) ?><br><span class="muted"><?= e($loc['landmark']) ?></span></p>
      <p class="muted"><?= e(Store::hoursText($loc['hours'])) ?></p>
      <?php foreach (Store::contacts('phone') as $p): ?><a href="tel:<?= e(phone_tel($p['value'])) ?>"><?= icon('phone') ?> <?= e(phone_display($p['value'])) ?></a><?php endforeach ?>
      <a href="mailto:<?= e(Store::contact('email')) ?>"><?= icon('mail') ?> <?= e(Store::contact('email')) ?></a>
      <a href="<?= url('visit-us') ?>" class="footer-cta">Get directions <?= icon('chev') ?></a>
    </div>
  </div>
  <div class="wrap footer-base">
    <span>© <?= date('Y') ?> <?= e($S['name']) ?>. All prices in Naira. · Website by <a href="https://naychurrahl.com.ng">naychurrahl</a></span>
    <span class="pay-badges"><span>Pay on delivery</span><span>Paystack</span><span>Bank transfer</span></span>
    <span><a href="<?= url('about') ?>">About</a> · <a href="<?= url('privacy') ?>">Privacy</a> · <a href="<?= url('terms') ?>">Terms</a></span>
  </div>
</footer>
