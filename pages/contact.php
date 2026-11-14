<?php
$S = Store::info();
$msg = null; $errors = []; $general = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try { $msg = Store::api()->post('/contact', array_intersect_key($_POST, array_flip(['name', 'phone', 'email', 'subject', 'message'])))['message']; }
    catch (ApiError $e) { $errors = $e->fields(); $general = $e->getMessage(); }
}
View::meta(['title' => "Contact us | {$S['name']}", 'description' => 'Call, WhatsApp or message ' . $S['name'] . '. We reply within an hour during opening times.', 'canonical' => '/contact']);
$loc = Store::location();
$err = fn($k) => isset($errors[$k]) ? '<p class="err">' . e($errors[$k]) . '</p>' : '';
?>
<div class="wrap">
  <?php View::breadcrumbs([['Home', '/'], ['Contact', null]]) ?>
  <header class="page-head"><h1>Contact us</h1><p class="lead">The fastest way to reach us is WhatsApp. We're available <?= e(Store::hoursText($S['support_hours'])) ?>.</p></header>
  <div class="contact-layout">
    <div class="contact-cards">
      <?php foreach (Store::contacts('phone') as $p): ?><a class="card-box contact-card" href="tel:<?= e(phone_tel($p['value'])) ?>"><?= icon('phone', 'i-lg') ?><span><strong><?= e($p['label']) ?></strong><?= e(phone_display($p['value'])) ?></span></a><?php endforeach ?>
      <a class="card-box contact-card" href="<?= e(Store::whatsapp()) ?>" target="_blank" rel="noopener"><?= icon('whatsapp', 'i-lg') ?><span><strong>WhatsApp</strong>Chat with sales</span></a>
      <a class="card-box contact-card" href="mailto:<?= e(Store::contact('email')) ?>"><?= icon('mail', 'i-lg') ?><span><strong>Email</strong><?= e(Store::contact('email')) ?></span></a>
      <a class="card-box contact-card" href="<?= url('visit-us') ?>"><?= icon('map', 'i-lg') ?><span><strong>Showroom</strong><?= e($loc['street']) ?></span></a>
    </div>
    <div class="card-box">
      <h2>Send a message</h2>
      <?php if ($msg): ?><p class="notice ok"><?= e($msg) ?></p><?php else: ?>
      <?php if ($general): ?><p class="notice err"><?= e($general) ?></p><?php endif ?>
      <form method="post" action="<?= url('contact') ?>" class="form" data-contact>
        <?= csrf_field() ?>
        <div class="grid2">
          <label>Name *<input name="name" value="<?= e($_POST['name'] ?? '') ?>" required><?= $err('name') ?></label>
          <label>Phone *<input name="phone" type="tel" value="<?= e($_POST['phone'] ?? '') ?>" required><?= $err('phone') ?></label>
        </div>
        <label>Email <span class="muted">(optional)</span><input name="email" type="email" value="<?= e($_POST['email'] ?? '') ?>"></label>
        <label>Subject<input name="subject" value="<?= e($_POST['subject'] ?? '') ?>" placeholder="e.g. Bulk order, warranty, delivery"></label>
        <label>Message *<textarea name="message" rows="5" required><?= e($_POST['message'] ?? '') ?></textarea><?= $err('message') ?></label>
        <div class="help-row"><button class="btn btn-primary">Send message</button><button class="btn btn-wa" type="button" data-send-wa="<?= e(Store::contact('whatsapp')) ?>"><?= icon('whatsapp') ?> Send via WhatsApp instead</button></div>
      </form>
      <?php endif ?>
    </div>
  </div>
</div>
