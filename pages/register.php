<?php
// Two steps: your details → we text a code to your phone → code + password. The code proves the phone
// is yours, which matters because an account sees every order placed with its phone number.
$S = Store::info();
$errors = []; $general = null;
$next = preg_match('#^[a-z0-9/_-]*$#i', $_GET['next'] ?? '') ? ($_GET['next'] ?? 'account') : 'account';
$reg = $_SESSION['register'] ?? null; // details from step 1 + demo code
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'details';
    try {
        if ($action === 'details' || $action === 'resend') {
            $d = $action === 'resend' && $reg ? $reg : array_map('trim', array_intersect_key($_POST, array_flip(['first_name', 'last_name', 'phone', 'email'])));
            if (($d['first_name'] ?? '') === '') throw new ApiError(422, ['error' => 'Check the highlighted fields', 'fields' => ['first_name' => 'Enter your first name']]);
            $r = Store::api()->post('/auth/otp/send', ['to' => $d['phone'] ?? '', 'purpose' => 'register']);
            $_SESSION['register'] = $reg = $d + ['demo' => $r['demo_code'] ?? null, 'at' => time()];
        } elseif ($action === 'restart') {
            unset($_SESSION['register']);
            $reg = null;
        } elseif ($action === 'create' && $reg) {
            $r = Store::api()->post('/auth/register', array_intersect_key($reg, array_flip(['first_name', 'last_name', 'phone', 'email'])) + ['code' => $_POST['code'] ?? '', 'password' => $_POST['password'] ?? '']);
            unset($_SESSION['register']);
            Store::setCookie('auth', $r['token'], 30);
            redirect($next ?: 'account');
        }
    } catch (ApiError $e) {
        $errors = $e->fields();
        if (isset($errors['to'])) $errors['phone'] = $errors['to'];
        $general = $e->getMessage();
        if (array_intersect_key($errors, array_flip(['first_name', 'phone', 'email']))) { unset($_SESSION['register']); $reg = null; } // back to step 1
    }
}
if ($reg && $reg['at'] < time() - 300) { unset($_SESSION['register']); $reg = null; $general = 'That code expired. Send a new one.'; }
View::meta(['title' => "Create an account | {$S['name']}", 'noindex' => true]);
$err = fn($k) => isset($errors[$k]) ? '<p class="err">' . e($errors[$k]) . '</p>' : '';
$v = fn($k) => e($_POST[$k] ?? ($reg[$k] ?? ''));
?>
<div class="wrap auth">
  <div class="card-box">
    <h1>Create an account</h1>
    <?php if ($general): ?><p class="notice err" role="alert"><?= e($general) ?></p><?php endif ?>

    <?php if (!$reg): ?>
    <p class="muted">Already ordered as a guest? Use the same phone number and your past orders will appear in your account.</p>
    <form method="post" class="form" action="<?= url('register', ['next' => $next]) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="details">
      <div class="grid2">
        <label>First name<input name="first_name" value="<?= $v('first_name') ?>" autocomplete="given-name" required><?= $err('first_name') ?></label>
        <label>Last name<input name="last_name" value="<?= $v('last_name') ?>" autocomplete="family-name"></label>
      </div>
      <label>Phone number<input name="phone" type="tel" value="<?= $v('phone') ?>" autocomplete="tel" required><span class="hint">We'll text you a code to confirm it</span><?= $err('phone') ?></label>
      <label>Email <span class="muted">(optional)</span><input name="email" type="email" value="<?= $v('email') ?>" autocomplete="email"><?= $err('email') ?></label>
      <button class="btn btn-primary btn-block">Continue</button>
    </form>

    <?php else: ?>
    <p>We've texted a 6-digit code to <strong><?= e(phone_display(preg_replace('/\D/', '', $reg['phone']))) ?></strong>. It lasts 5 minutes.</p>
    <?php if (!empty($reg['demo'])): ?><p class="notice small">Demo: no SMS is sent. Your code is <strong><?= e($reg['demo']) ?></strong> (also in CMS › Outbox).</p><?php endif ?>
    <form method="post" class="form" action="<?= url('register', ['next' => $next]) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="create">
      <label>Code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required autofocus><?= $err('code') ?></label>
      <label>Choose a password<input name="password" type="password" minlength="8" autocomplete="new-password" required><span class="hint">At least 8 characters</span><?= $err('password') ?></label>
      <button class="btn btn-primary btn-block">Create account</button>
    </form>
    <form method="post" class="row small" action="<?= url('register', ['next' => $next]) ?>">
      <?= csrf_field() ?>
      <button class="link-btn" name="action" value="resend">Send a new code</button> ·
      <button class="link-btn" name="action" value="restart">Change my details</button>
    </form>
    <?php endif ?>
    <p class="small">Have an account? <a href="<?= url('login', ['next' => $next]) ?>">Sign in</a></p>
  </div>
</div>
