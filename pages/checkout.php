<?php
$S = Store::info();
$cart = Store::cart(true);
if (!$cart['items']) redirect('cart');

$me = Store::me();
$errors = [];
$general = null;
$in = $_POST + [
    'first_name' => $me['first_name'] ?? '', 'last_name' => $me['last_name'] ?? '', 'phone' => $me['phone'] ?? '', 'email' => $me['email'] ?? '',
    'fulfilment' => 'delivery', 'zone_id' => '', 'street' => '', 'landmark' => '', 'note' => '', 'preferred_date' => '', 'time_slot' => 'afternoon',
    'payment_method' => 'pod', 'coupon' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $o = Store::api()->post('/orders', array_intersect_key($in, array_flip(['first_name', 'last_name', 'phone', 'email', 'fulfilment', 'zone_id', 'street', 'landmark', 'note', 'preferred_date', 'time_slot', 'payment_method', 'coupon'])) + ['location_id' => Store::location()['id']]);
        $_SESSION['orders'][$o['number']] = $o['access_token'];
        $_SESSION['order_grants'][$o['number']] = $o['grant']; // this browser placed it: show the full order without a phone check
        if ($o['next']['type'] === 'paystack') {
            $pay = Store::api()->withHeader('X-Order-Grant', $o['grant'])->post('/payments/paystack/init', ['number' => $o['number'], 'token' => $o['access_token'], 'callback_url' => View::absolute('/pay/callback')]);
            redirect($pay['authorization_url']);
        }
        if ($o['next']['type'] === 'whatsapp') $_SESSION['wa_open'] = $o['next']['url'];
        redirect("order/{$o['number']}?t={$o['access_token']}");
    } catch (ApiError $e) {
        $errors = $e->fields();
        $general = $e->getMessage();
    }
}

$zones = $S['zones'];
$quote = Store::api()->post('/checkout/quote', ['fulfilment' => $in['fulfilment'], 'zone_id' => $in['zone_id']]);
$earliest = $quote['earliest_date'];
if (!$quote['pay_on_delivery'] && $in['payment_method'] === 'pod') $in['payment_method'] = 'paystack'; // courier areas (Lagos) pay before dispatch
$dates = []; // from today; days before the earliest possible date are shown disabled (JS re-enables them when the area changes)
for ($d = 0; count($dates) < 8; $d++) {
    $day = date('Y-m-d', strtotime("+$d days"));
    if (date('w', strtotime($day)) === '0') continue;
    $dates[$day] = $day === date('Y-m-d') ? 'Today, ' . date('j M') : ($day === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow, ' . date('j M', strtotime($day)) : date('D j M', strtotime($day)));
}
if ($in['preferred_date'] === '' || $in['preferred_date'] < $earliest) $in['preferred_date'] = $earliest;
$loc = Store::location();
View::meta(['title' => 'Checkout | ' . $S['name'], 'noindex' => true]);
View::$bodyClass = 'checkout-page';
$err = fn($k) => isset($errors[$k]) ? '<p class="err" id="err-' . $k . '">' . e($errors[$k]) . '</p>' : '';
$bad = fn($k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="err-' . $k . '"' : '';
?>
<div class="wrap">
  <h1 class="page-title">Checkout</h1>
  <p class="muted">No account needed. <?php if (!$me): ?><a href="<?= url('login', ['next' => 'checkout']) ?>">Sign in</a> to use saved details.<?php endif ?></p>
  <?php if ($general): ?><p class="notice err" role="alert"><?= e($general) ?><?= isset($errors['cart']) ? ' — ' . e($errors['cart']) . ' <a href="' . url('cart') . '">Edit cart</a>' : '' ?></p><?php endif ?>

  <form class="checkout" method="post" action="<?= url('checkout') ?>" data-checkout novalidate>
    <?= csrf_field() ?>
    <div class="co-main">
      <section class="co-step">
        <h2><span>1</span> Your details</h2>
        <div class="grid2">
          <label>First name *<input name="first_name" value="<?= e($in['first_name']) ?>" autocomplete="given-name" required<?= $bad('first_name') ?>><?= $err('first_name') ?></label>
          <label>Last name<input name="last_name" value="<?= e($in['last_name']) ?>" autocomplete="family-name"></label>
          <label>Phone number *<input name="phone" type="tel" inputmode="tel" value="<?= e($in['phone']) ?>" placeholder="0803 123 4567" autocomplete="tel" required data-phone<?= $bad('phone') ?>><span class="hint">We'll call or text this number about delivery</span><?= $err('phone') ?></label>
          <label>Email <span class="muted">(optional)</span><input name="email" type="email" value="<?= e($in['email']) ?>" autocomplete="email"<?= $bad('email') ?>><span class="hint">For your receipt</span><?= $err('email') ?></label>
        </div>
      </section>

      <section class="co-step">
        <h2><span>2</span> Delivery or pickup</h2>
        <div class="choice-row">
          <label class="choice"><input type="radio" name="fulfilment" value="delivery" <?= $in['fulfilment'] === 'delivery' ? 'checked' : '' ?> data-fulfilment>
            <span><?= icon('truck') ?><strong>Deliver to me</strong><small><?= e(implode(' & ', array_keys(Store::zoneCities()))) ?>, from <?= naira(min(array_column($zones, 'fee'))) ?></small></span></label>
          <label class="choice"><input type="radio" name="fulfilment" value="pickup" <?= $in['fulfilment'] === 'pickup' ? 'checked' : '' ?> data-fulfilment>
            <span><?= icon('store') ?><strong>Pick up — free</strong><small><?= e($loc['name']) ?></small></span></label>
        </div>

        <div class="co-delivery" data-when="delivery" <?= $in['fulfilment'] === 'pickup' ? 'hidden' : '' ?>>
          <label>Area *
            <select name="zone_id" data-zone<?= $bad('zone_id') ?>>
              <option value="">Choose your area</option>
              <?php foreach (Store::zoneCities() as $city => $cz): ?><optgroup label="<?= e($city) ?>">
              <?php foreach ($cz as $z): ?><option value="<?= (int) $z['id'] ?>" data-fee="<?= (int) $z['fee'] ?>" data-same-day="<?= $z['same_day'] ? 1 : 0 ?>" data-pod="<?= $z['pay_on_delivery'] ? 1 : 0 ?>" <?= (string) $in['zone_id'] === (string) $z['id'] ? 'selected' : '' ?>><?= e($z['name']) ?>, <?= e($city) ?> — <?= naira($z['fee']) ?><?= $z['same_day'] ? '' : ' (' . e($z['lead_days'] > 1 ? $z['eta_text'] : 'next day') . ')' ?></option><?php endforeach ?>
              </optgroup><?php endforeach ?>
            </select><?= $err('zone_id') ?>
            <span class="hint">Outside these areas? <a href="<?= e(Store::whatsapp('Hello, I need delivery outside your listed areas.')) ?>" target="_blank" rel="noopener">Ask about courier delivery</a></span>
          </label>
          <label>Street address *<input name="street" value="<?= e($in['street']) ?>" placeholder="House number, street, estate" autocomplete="street-address"<?= $bad('street') ?>><?= $err('street') ?></label>
          <div class="grid2">
            <label>Landmark<input name="landmark" value="<?= e($in['landmark']) ?>" placeholder="e.g. opposite the mosque"></label>
            <label>Note for the rider<input name="note" value="<?= e($in['note']) ?>" placeholder="e.g. call at the gate"></label>
          </div>
        </div>
        <div class="co-pickup notice" data-when="pickup" <?= $in['fulfilment'] === 'pickup' ? '' : 'hidden' ?>>
          <strong><?= e($loc['name']) ?></strong><br><?= e($loc['street']) ?> · <?= e($loc['landmark']) ?><br><span class="muted"><?= e(Store::hoursText($loc['hours'])) ?></span>
          <br><a href="<?= url('visit-us') ?>" target="_blank">Map and directions</a>
        </div>
      </section>

      <section class="co-step">
        <h2><span>3</span> When</h2>
        <div class="grid2">
          <label>Preferred date *
            <select name="preferred_date" data-date<?= $bad('preferred_date') ?>>
              <?php foreach ($dates as $v => $l): ?><option value="<?= $v ?>" <?= $in['preferred_date'] === $v ? 'selected' : '' ?> <?= $v < $earliest ? 'disabled' : '' ?>><?= e($l) ?></option><?php endforeach ?>
            </select><?= $err('preferred_date') ?>
            <span class="hint" data-cutoff-hint>Order before <?= e(date('g a', strtotime($S['same_day_cutoff']))) ?> for same-day delivery<?= $earliest === date('Y-m-d') ? '' : ' — today\'s cutoff has passed' ?></span>
          </label>
          <label>Time
            <select name="time_slot">
              <?php foreach (['morning' => 'Morning (9am–12pm)', 'afternoon' => 'Afternoon (12pm–4pm)', 'evening' => 'Evening (4pm–7pm)'] as $v => $l): ?><option value="<?= $v ?>" <?= $in['time_slot'] === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
            </select>
          </label>
        </div>
      </section>

      <section class="co-step">
        <h2><span>4</span> Payment</h2>
        <?= $err('payment_method') ?>
        <p class="notice small" data-pod-note <?= $quote['pay_on_delivery'] ? 'hidden' : '' ?>>Pay on delivery isn't available for courier deliveries such as Lagos. Pay online or by bank transfer, and we dispatch the next working day.</p>
        <div class="pay-list">
          <?php foreach ($S['payment_methods'] as $pm): $off = $pm['type'] === 'pod' && !$quote['pay_on_delivery']; ?>
          <label class="pay<?= $off ? ' is-off' : '' ?>" <?= $pm['type'] === 'pod' ? 'data-pod-option' : '' ?>>
            <input type="radio" name="payment_method" value="<?= e($pm['type']) ?>" <?= $in['payment_method'] === $pm['type'] && !$off ? 'checked' : '' ?> <?= $off ? 'disabled' : '' ?>>
            <span><strong><?= e($pm['label']) ?></strong><small><?= e($pm['instructions']) ?></small></span>
          </label>
          <?php endforeach ?>
        </div>
      </section>
    </div>

    <aside class="summary co-summary">
      <h2>Your order</h2>
      <ul class="mini-lines">
        <?php foreach ($cart['items'] as $i): ?>
        <li><span class="qtyb"><?= (int) $i['qty'] ?></span><span><strong><?= e($i['product']['name']) ?></strong><small class="muted"><?= e(str_replace(' X ', ' × ', $i['size_label'])) ?></small></span><span><?= naira($i['line_total']) ?></span></li>
        <?php endforeach ?>
      </ul>
      <a class="small" href="<?= url('cart') ?>">Edit cart</a>
      <label class="coupon">Discount code<span class="row"><input name="coupon" value="<?= e($in['coupon']) ?>" placeholder="e.g. WELCOME5" data-coupon><button type="button" class="btn btn-ghost btn-sm" data-apply-coupon>Apply</button></span><?= $err('coupon') ?><span class="err" data-coupon-err hidden></span></label>
      <div class="sum-row"><span>Subtotal</span><strong data-q="subtotal"><?= naira($quote['subtotal']) ?></strong></div>
      <div class="sum-row"><span>Delivery</span><strong data-q="delivery"><?= $quote['fee_pending'] ? '<span class="muted">Choose area</span>' : ($quote['delivery_fee'] ? naira($quote['delivery_fee']) : 'Free') ?></strong></div>
      <div class="sum-row discount" data-q-row="discount" <?= $quote['discount'] ? '' : 'hidden' ?>><span>Discount</span><strong data-q="discount">−<?= naira($quote['discount']) ?></strong></div>
      <div class="sum-row total"><span>Total</span><strong data-q="total"><?= naira($quote['total']) ?></strong></div>
      <button class="btn btn-primary btn-lg btn-block" data-place>Place order</button>
      <p class="small muted center">By placing your order you agree to our <a href="<?= url('terms') ?>">terms</a>. Your details are encrypted and only used for this order.</p>
    </aside>
  </form>
</div>
