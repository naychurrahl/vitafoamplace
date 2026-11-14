<?php
$cart = Store::cart();
$cats = Store::api()->get('/categories');
$phone = Store::contact('phone');
$here = '/' . View::$path;
$nav = [['/shop', 'Shop all']];
foreach ($cats as $c) $nav[] = ["/{$c['slug']}", $c['name']];
$nav[] = ['/brands', 'Brands'];
$nav[] = ['/buyers-guide', "Buyer's guide"];
$nav[] = ['/visit-us', 'Visit us'];
$nav[] = ['/blog', 'Blog'];
?>
<?php if ($S['announcement']): ?>
<div class="announce"><div class="wrap"><?= icon('truck') ?> <span><?= e($S['announcement']) ?></span></div></div>
<?php endif ?>
<div class="topbar">
  <div class="wrap">
    <a href="tel:<?= e(phone_tel($phone)) ?>"><?= icon('phone') ?> <?= e(phone_display($phone)) ?></a>
    <a href="<?= e(Store::whatsapp()) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> WhatsApp us</a>
    <span class="grow"></span>
    <a href="<?= url('account') ?>">Track order</a>
    <a href="<?= url('visit-us') ?>"><?= icon('map') ?> <?= e(Store::location()['name']) ?></a>
  </div>
</div>
<header class="header">
  <div class="wrap header-row">
    <button class="icon-btn nav-toggle" aria-label="Menu" aria-expanded="false" data-nav-toggle><?= icon('menu') ?></button>
    <a class="logo" href="<?= url('/') ?>">
      <?php if ($S['logo']): ?><img src="<?= e($S['logo']) ?>" alt="<?= e($S['name']) ?>"><?php else: ?>
      <span class="logo-mark"><?= e(mb_substr($S['name'], 0, 1)) ?></span><span class="logo-text"><?= e($S['name']) ?></span><?php endif ?>
    </a>
    <form class="search" action="<?= url('search') ?>" role="search" data-search>
      <?= icon('search') ?>
      <input type="search" name="q" placeholder="Search mattresses, pillows, brands…" value="<?= e($_GET['q'] ?? '') ?>" autocomplete="off" aria-label="Search">
      <div class="suggest" hidden></div>
    </form>
    <nav class="header-icons" aria-label="Account">
      <a class="icon-btn" href="<?= url('wishlist') ?>" aria-label="Wishlist"><?= icon('heart') ?><span class="badge" data-wish-count hidden>0</span></a>
      <a class="icon-btn" href="<?= url(Store::me() ? 'account' : 'login') ?>" aria-label="Account"><?= icon('user') ?></a>
      <a class="icon-btn cart-btn" href="<?= url('cart') ?>" aria-label="Cart"><?= icon('cart') ?><span class="badge" data-cart-count <?= $cart['count'] ? '' : 'hidden' ?>><?= (int) $cart['count'] ?></span></a>
    </nav>
  </div>
  <nav class="mainnav" aria-label="Main" data-nav>
    <div class="wrap">
      <?php foreach ($nav as [$href, $label]): ?>
        <a href="<?= url($href) ?>" class="<?= $here === $href || ($href !== '/shop' && str_starts_with($here, $href . '/')) ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach ?>
    </div>
  </nav>
</header>
