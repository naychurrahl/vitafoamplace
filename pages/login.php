<?php
// Sign in two ways: a one-time code to your phone or email (anyone who has ordered), or a password (members).
// A phone code signs you in to that customer; an email code shows the orders placed with that email.
$S = Store::info();
$error = null;
$next = preg_match('#^[a-z0-9/_-]*$#i', $_GET['next'] ?? '') ? ($_GET['next'] ?? 'account') : 'account';
$via = ($_GET['via'] ?? '') === 'password' ? 'password' : 'code';
$otp = $_SESSION['otp_login'] ?? null; // ['to' => what they typed, 'demo' => code (demo only), 'at' => time]
$sentNote = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'password';
    try {
        if ($action === 'send') {
            $to = trim($_POST['to'] ?? ($otp['to'] ?? ''));
            $r = Store::api()->post('/auth/otp/send', ['to' => $to, 'purpose' => 'login']);
            $_SESSION['otp_login'] = $otp = ['to' => $to, 'demo' => $r['demo_code'] ?? null, 'at' => time()];
            $sentNote = 'sent';
        } elseif ($action === 'verify') {
            $r = Store::api()->post('/auth/otp/verify', ['to' => $otp['to'] ?? '', 'code' => $_POST['code'] ?? '']);
            unset($_SESSION['otp_login']);
            Store::setCookie('auth', $r['token'], 30);
            redirect($next ?: 'account');
        } elseif ($action === 'restart') {
            unset($_SESSION['otp_login']);
            $otp = null;
        } else {
            $r = Store::api()->post('/auth/login', ['login' => $_POST['login'] ?? '', 'password' => $_POST['password'] ?? '']);
            Store::setCookie('auth', $r['token'], 30);
            redirect($next ?: 'account');
        }
    } catch (ApiError $e) { $error = $e->getMessage() . (($f = $e->fields()) && isset($f['to']) ? ' ' . $f['to'] . '.' : ''); }
}
if ($otp && $otp['at'] < time() - 300) { unset($_SESSION['otp_login']); $otp = null; } // code expired: start over
$isEmail = $otp && str_contains($otp['to'], '@');
$masked = $otp ? ($isEmail ? $otp['to'] : preg_replace('/^(\d{4})\d+(\d{3})$/', '$1 *** $2', preg_replace('/\D/', '', $otp['to']))) : '';
View::meta(['title' => "Sign in | {$S['name']}", 'noindex' => true]);
$tab = fn($v, $label) => '<a role="tab" aria-selected="' . ($via === $v ? 'true' : 'false') . '" href="' . url('login', ['next' => $next, 'via' => $v]) . '">' . $label . '</a>';
?>
<div class="wrap auth">
  <div class="card-box">
    <h1>Sign in</h1>
    <p class="muted">See your orders and track deliveries. Ordered as a guest? Use a code: no account needed.</p>
    <nav class="auth-tabs" role="tablist"><?= $tab('code', 'Text me a code') ?><?= $tab('password', 'Use a password') ?></nav>
    <p class="notice err" role="alert" data-login-error<?= $error ? '' : ' hidden' ?>><?= e($error ?? '') ?></p>

    <?php if ($via === 'code' && !$otp): ?>
    <form method="post" class="form" action="<?= url('login', ['next' => $next]) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="send">
      <label>Phone number or email<input name="to" value="<?= e($_POST['to'] ?? '') ?>" autocomplete="username" placeholder="0803 123 4567" required>
        <span class="hint">The one you used when you ordered</span></label>
      <button class="btn btn-primary btn-block">Send me a code</button>
    </form>

    <?php elseif ($via === 'code'): ?>
    <p>If <strong><?= e($masked) ?></strong> has ordered with us, a 6-digit code is on its way by <?= $isEmail ? 'email' : 'text message' ?>. It lasts 5 minutes.</p>
    <?php if (!empty($otp['demo'])): ?><p class="notice small">Demo: no <?= $isEmail ? 'email' : 'SMS' ?> is sent. Your code is <strong><?= e($otp['demo']) ?></strong> (also in CMS › Outbox).</p>
    <?php elseif (Api::config()['api']['driver'] === 'mock'): ?><p class="notice small">Demo: no code was sent, because nobody has ordered with that <?= $isEmail ? 'email' : 'number' ?>.</p><?php endif ?>
    <form method="post" class="form" action="<?= url('login', ['next' => $next]) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="verify">
      <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus></label>
      <button class="btn btn-primary btn-block">Sign in</button>
    </form>
    <form method="post" class="row small" action="<?= url('login', ['next' => $next]) ?>">
      <?= csrf_field() ?>
      <button class="link-btn" name="action" value="send">Send a new code</button> ·
      <button class="link-btn" name="action" value="restart">Use a different <?= $isEmail ? 'email' : 'number' ?></button>
    </form>

    <?php else: ?>
    <form method="post" class="form" action="<?= url('login', ['next' => $next, 'via' => 'password']) ?>" data-ajax-login>
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <label>Phone number or email<input name="login" value="<?= e($_POST['login'] ?? '') ?>" autocomplete="username" required></label>
      <label>Password<input name="password" type="password" autocomplete="current-password" required></label>
      <button class="btn btn-primary btn-block">Sign in</button>
    </form>
    <p class="small">Forgot your password? <a href="<?= url('login', ['next' => 'account']) ?>">Sign in with a code</a>, then set a new one from your account.</p>
    <?php if (Api::config()['api']['driver'] === 'mock'): ?><p class="small muted">Demo: any member phone from the CMS customer list, password <code><?= e(Api::config()['mock']['demo_password']) ?></code>.</p><?php endif ?>
    <?php endif ?>

    <p class="small">New here? <a href="<?= url('register', ['next' => $next]) ?>">Create an account</a> · <a href="<?= url('checkout') ?>">Continue as guest</a></p>
  </div>
</div>
