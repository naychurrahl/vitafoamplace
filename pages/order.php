<?php
$S = Store::info();
$token = (string) ($_GET['t'] ?? ($_SESSION['orders'][$number] ?? ''));
if ($token === '') throw new NotFound();
// Everything about an order needs the link plus one of: the owner signed in or the browser that placed it
// (full), or the order's phone typed below (summary: no name, contact or address). Anything less is a 404.
$api = Store::api()->withHeader('X-Order-Grant', $_SESSION['order_grants'][$number] ?? null);
$msg = null;
$gateErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'unlock') {
            $_SESSION['order_grants'][$number] = Store::api()->post("/orders/$number/unlock", ['token' => $token, 'phone' => $_POST['phone'] ?? ''])['grant'];
            $_SESSION['orders'][$number] = $token;
            redirect("order/$number?t=$token");
        } elseif ($action === 'receipt') {
            $name = $_FILES['receipt']['name'] ?? 'receipt.jpg';
            $msg = $api->post("/orders/$number/transfer-receipt", ['token' => $token, 'filename' => $name])['message'];
        } elseif ($action === 'pay') {
            $pay = $api->post('/payments/paystack/init', ['number' => $number, 'token' => $token, 'callback_url' => View::absolute('/pay/callback')]);
            redirect($pay['authorization_url']);
        }
    } catch (ApiError $e) {
        if ($action === 'unlock') $gateErr = $e->getMessage(); else $msg = $e->getMessage();
    }
}
$o = null;
try { $o = $api->get("/orders/$number", ['token' => $token]); } catch (ApiError $e) { if ($e->status !== 404) throw $e; }

if (!$o):
    unset($_SESSION['order_grants'][$number]); // expired or never had one
    View::meta(['title' => "View your order | {$S['name']}", 'noindex' => true]);
?>
<div class="wrap auth">
  <div class="card-box" id="details">
    <h1><?= icon('shield') ?> View your order</h1>
    <p class="muted">To keep your order private, enter the phone number you used when you placed it.</p>
    <?php if ($gateErr): ?><p class="notice err" role="alert"><?= e($gateErr) ?></p><?php endif ?>
    <form method="post" class="form">
      <?= csrf_field() ?><input type="hidden" name="action" value="unlock">
      <label>Phone number<input name="phone" type="tel" autocomplete="tel" inputmode="tel" value="<?= e($_POST['phone'] ?? '') ?>" required></label>
      <button class="btn btn-primary btn-block">View order</button>
    </form>
    <p class="small">Or <a href="<?= url('login', ['next' => 'account']) ?>">sign in with a code</a> to see all your orders with full details.</p>
    <p class="small muted">Can't get in? <a href="<?= e(Store::whatsapp('Hello, I need help viewing my order')) ?>" target="_blank" rel="noopener">WhatsApp us</a> or call <a href="tel:<?= e(phone_tel(Store::contact('phone'))) ?>"><?= e(Store::contact('phone')) ?></a>.</p>
  </div>
</div>
<?php
    return;
endif;

View::meta(['title' => "Order {$o['number']} | {$S['name']}", 'noindex' => true]);
$summary = ($o['access'] ?? 'full') === 'summary';
$waOpen = $_SESSION['wa_open'] ?? null;
unset($_SESSION['wa_open']);
$flow = $o['fulfilment'] === 'pickup' ? ['placed', 'confirmed', 'ready_for_pickup', 'collected'] : ['placed', 'confirmed', 'packed', 'out_for_delivery', 'delivered'];
$done = array_column($o['events'], 'created_at', 'status');
$fresh = ($o['events'][0]['created_at'] ?? '') > date('Y-m-d H:i:s', time() - 900);
?>
<div class="wrap narrow">
  <?php if ($fresh && !$summary): ?>
  <div class="confirm-hero">
    <span class="tick"><?= icon('check') ?></span>
    <h1>Thank you, <?= e($o['contact']['first_name']) ?>!</h1>
    <p class="lead">Your order <strong><?= e($o['number']) ?></strong> has been placed. We've sent a text to <?= e(phone_display($o['contact']['phone'])) ?>.</p>
  </div>
  <?php else: ?>
  <h1 class="page-title">Order <?= e($o['number']) ?></h1>
  <?php endif ?>
  <?php if ($msg): ?><p class="notice ok"><?= e($msg) ?></p><?php endif ?>

  <?php if ($o['payment_method']['type'] === 'whatsapp' && !$summary): ?>
  <div class="notice wa-box"><p><strong>Finish your order on WhatsApp.</strong> We've written the message for you.</p>
    <a class="btn btn-wa" href="<?= e($o['whatsapp_url']) ?>" target="_blank" rel="noopener" <?= $waOpen ? 'data-autoopen' : '' ?>><?= icon('whatsapp') ?> Open WhatsApp</a></div>
  <?php elseif ($o['payment_method']['type'] === 'transfer' && $o['payment_status'] === 'unpaid'): ?>
  <div class="notice"><p><strong>Pay by bank transfer</strong></p><p><?= e($o['payment_method']['instructions']) ?></p><p>Amount: <strong><?= naira($o['total']) ?></strong> · Narration: <strong><?= e($o['number']) ?></strong></p>
    <form method="post" enctype="multipart/form-data" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="receipt"><input type="file" name="receipt" accept="image/*,.pdf" required><button class="btn btn-primary btn-sm">Upload receipt</button></form>
    <p class="small muted">Or <a href="<?= e(Store::whatsapp("Payment receipt for order {$o['number']}")) ?>" target="_blank" rel="noopener">send it on WhatsApp</a>.</p></div>
  <?php elseif ($o['payment_method']['type'] === 'paystack' && $o['payment_status'] !== 'paid'): ?>
  <div class="notice err"><p><strong>Payment not completed.</strong> Your order is saved — you can pay now, or pay on delivery instead by calling us.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="pay"><button class="btn btn-primary">Pay <?= naira($o['total']) ?> now</button></form></div>
  <?php endif ?>

  <section class="card-box">
    <h2>Status</h2>
    <ol class="timeline">
      <?php foreach ($flow as $st): $isDone = isset($done[$st]); ?>
      <li class="<?= $isDone ? 'done' : '' ?><?= $o['status'] === $st ? ' current' : '' ?>"><span class="dot"></span><strong><?= e(status_label($st)) ?></strong><?php if ($isDone): ?><span class="muted small"><?= e(when($done[$st])) ?></span><?php endif ?></li>
      <?php endforeach ?>
      <?php if ($o['status'] === 'cancelled'): ?><li class="done cancelled current"><span class="dot"></span><strong>Cancelled</strong><span class="muted small"><?= e(when($done['cancelled'])) ?></span></li><?php endif ?>
    </ol>
  </section>

  <div class="grid2">
    <?php if ($summary): ?>
    <section class="card-box">
      <h2><?= icon('shield') ?> <?= $o['fulfilment'] === 'pickup' ? 'Pickup' : 'Delivery' ?></h2>
      <p><?php if ($o['location']['kind'] === 'store'): ?><strong><?= e($o['location']['name']) ?></strong><?php else: ?><?= e($o['location']['zone_name'] ?? '') ?><?= !empty($o['location']['city']) ? ', ' . e($o['location']['city']) : '' ?><?php endif ?></p>
      <p><?= icon('clock') ?> <?= e(date('l j M', strtotime($o['preferred_date']))) ?><?= $o['time_slot'] ? ', ' . e($o['time_slot']) : '' ?></p>
      <p class="muted small">Your name, phone and address are hidden on shared links.</p>
      <a class="btn btn-ghost btn-sm" href="<?= url('login', ['next' => 'account']) ?>">Sign in to see them</a>
    </section>
    <?php else: ?>
    <section class="card-box">
      <h2><?= $o['fulfilment'] === 'pickup' ? 'Pickup' : 'Delivery' ?></h2>
      <p><?= e(trim($o['contact']['first_name'] . ' ' . $o['contact']['last_name'])) ?> · <?= e(phone_display($o['contact']['phone'])) ?></p>
      <p><?php if ($o['location']['kind'] === 'store'): ?><strong><?= e($o['location']['name']) ?></strong><br><?php endif ?><?= e($o['location']['street']) ?><?= $o['location']['zone_name'] ? ', ' . e($o['location']['zone_name']) : '' ?><?php if ($o['location']['landmark']): ?><br><span class="muted"><?= e($o['location']['landmark']) ?></span><?php endif ?></p>
      <p><?= icon('clock') ?> <?= e(date('l j M', strtotime($o['preferred_date']))) ?><?= $o['time_slot'] ? ', ' . e($o['time_slot']) : '' ?></p>
    </section>
    <?php endif ?>
    <section class="card-box">
      <h2>Payment</h2>
      <p><?= e($o['payment_method']['label']) ?></p>
      <p><span class="pill <?= $o['payment_status'] === 'paid' ? 'pill-ok' : 'pill-warn' ?>"><?= e(status_label($o['payment_status'])) ?></span></p>
    </section>
  </div>

  <section class="card-box">
    <h2>Items</h2>
    <ul class="mini-lines">
      <?php foreach ($o['items'] as $i): ?><li><span class="qtyb"><?= (int) $i['qty'] ?></span><span><strong><?= e($i['product_name']) ?></strong><small class="muted"><?= e($i['brand_name']) ?> · <?= e(str_replace(' X ', ' × ', $i['size_label'])) ?></small></span><span><?= naira($i['line_total']) ?></span></li><?php endforeach ?>
    </ul>
    <div class="sum-row"><span>Subtotal</span><span><?= naira($o['subtotal']) ?></span></div>
    <div class="sum-row"><span>Delivery</span><span><?= $o['delivery_fee'] ? naira($o['delivery_fee']) : 'Free' ?></span></div>
    <?php if ($o['discount']): ?><div class="sum-row"><span>Discount (<?= e($o['coupon_code']) ?>)</span><span>−<?= naira($o['discount']) ?></span></div><?php endif ?>
    <div class="sum-row total"><span>Total</span><strong><?= naira($o['total']) ?></strong></div>
  </section>

  <div class="help-row">
    <a class="btn btn-ghost" href="tel:<?= e(phone_tel(Store::contact('phone'))) ?>"><?= icon('phone') ?> Call us</a>
    <a class="btn btn-wa" href="<?= e(Store::whatsapp("Hello, a question about order {$o['number']}")) ?>" target="_blank" rel="noopener"><?= icon('whatsapp') ?> WhatsApp</a>
    <a class="btn btn-ghost" href="<?= url('account') ?>">All your orders</a>
  </div>
  <p class="small muted center">Bookmark this page to check your order. On another device you'll be asked for the order's phone number, or sign in with a code to see everything.</p>
</div>
