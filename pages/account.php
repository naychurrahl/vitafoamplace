<?php
$S = Store::info();
$me = Store::me();
if (!$me) redirect('login?next=account');
$byEmail = $me['signed_in_with'] === 'email_code'; // sees orders placed with that email; no account details
$pwMsg = null; $pwErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'password') {
    csrf_check();
    try {
        Store::api()->post('/me/password', ['password' => $_POST['password'] ?? '']);
        $pwMsg = $me['has_password'] ? 'Password changed.' : 'Password saved. Next time you can sign in with it, or with a code.';
        $me['has_password'] = true;
    } catch (ApiError $e) { $pwErr = $e->fields()['password'] ?? $e->getMessage(); }
}
$orders = Store::api()->get('/me/orders');
$addresses = $byEmail ? [] : Store::api()->get('/me/addresses');
View::meta(['title' => "Your account | {$S['name']}", 'noindex' => true]);
?>
<div class="wrap">
  <div class="account-head">
    <h1 class="page-title">Hello<?= $me['first_name'] ? ', ' . e($me['first_name']) : '' ?></h1>
    <form method="post" action="<?= url('logout') ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm">Sign out</button></form>
  </div>
  <div class="account-layout">
    <section class="card-box">
      <h2>Your orders</h2>
      <?php if ($byEmail): ?><p class="muted small">Showing orders placed with <?= e($me['email']) ?>. Sign in with your phone number to see every order on your number.</p><?php endif ?>
      <?php if (!$orders): ?><p class="muted">No orders yet. <a href="<?= url('mattresses') ?>">Start shopping</a>.</p><?php else: ?>
      <div class="table-scroll"><table class="data">
        <thead><tr><th>Order</th><th>Date</th><th>Items</th><th>Total</th><th>Status</th><th>Payment</th></tr></thead>
        <tbody><?php foreach ($orders as $o): ?>
          <tr><td><a href="<?= url("order/{$o['number']}", ['t' => $o['access_token']]) ?>"><?= e($o['number']) ?></a></td><td><?= e(when($o['created_at'], 'j M Y')) ?></td><td><?= (int) $o['item_count'] ?></td><td><?= naira($o['total']) ?></td><td><span class="pill"><?= e(status_label($o['status'])) ?></span></td><td><?= e(status_label($o['payment_status'])) ?></td></tr>
        <?php endforeach ?></tbody>
      </table></div>
      <?php endif ?>
    </section>
    <aside>
      <?php if ($byEmail): ?>
      <section class="card-box"><h2>Signed in by email</h2><p><?= e($me['email']) ?></p>
        <p class="small muted">An email code only shows orders placed with that email. To manage your account, sign in with your phone number.</p></section>
      <?php else: ?>
      <section class="card-box"><h2>Details</h2><p><?= e(trim($me['first_name'] . ' ' . $me['last_name'])) ?><br><?= e(phone_display($me['phone'])) ?><br><?= e($me['email'] ?? 'No email') ?></p></section>
      <section class="card-box"><h2>Addresses</h2>
        <?php foreach ($addresses as $a): ?><p><strong><?= e($a['label']) ?></strong><br><?= e($a['street']) ?>, <?= e($a['zone_name']) ?><?= $a['landmark'] ? '<br><span class="muted">' . e($a['landmark']) . '</span>' : '' ?></p><?php endforeach ?>
        <?php if (!$addresses): ?><p class="muted">Saved when you check out.</p><?php endif ?>
      </section>
      <section class="card-box" id="password">
        <h2><?= $me['has_password'] ? 'Change password' : 'Add a password' ?></h2>
        <?php if (!$me['has_password']): ?><p class="small muted">Optional. You can keep signing in with a code.</p><?php endif ?>
        <?php if ($pwMsg): ?><p class="notice ok"><?= e($pwMsg) ?></p><?php endif ?>
        <?php if ($pwErr): ?><p class="notice err" role="alert"><?= e($pwErr) ?></p><?php endif ?>
        <form method="post" class="form" action="<?= url('account') ?>#password">
          <?= csrf_field() ?><input type="hidden" name="action" value="password">
          <label>New password<input name="password" type="password" minlength="8" autocomplete="new-password" required><span class="hint">At least 8 characters</span></label>
          <button class="btn btn-ghost btn-block">Save password</button>
        </form>
      </section>
      <?php endif ?>
      <a class="btn btn-ghost btn-block" href="<?= url('wishlist') ?>"><?= icon('heart') ?> Wishlist</a>
    </aside>
  </div>
</div>
