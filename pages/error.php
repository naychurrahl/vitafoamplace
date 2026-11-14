<?php /** @var ApiError $error */ ?>
<div class="wrap">
  <div class="empty">
    <h1>Something went wrong</h1>
    <p>Please try again in a moment. If it keeps happening, <a href="<?= e(Store::whatsapp('Hello, your website showed an error.')) ?>">WhatsApp us</a> and we'll take your order directly.</p>
    <p class="muted small"><?= e($error->getMessage()) ?></p>
  </div>
</div>
