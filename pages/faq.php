<?php
$S = Store::info();
$groups = Store::api()->get('/faqs');
View::meta(['title' => "Frequently asked questions | {$S['name']}", 'description' => 'Answers about ordering, delivery times and fees, paying on delivery, choosing a mattress, returns and warranty.', 'canonical' => '/faq']);
?>
<div class="wrap narrow">
  <?php View::breadcrumbs([['Home', '/'], ['FAQ', null]]) ?>
  <header class="page-head"><h1>Frequently asked questions</h1>
    <nav class="chips"><?php foreach ($groups as $g): ?><a class="chip-link" href="#<?= e(strtolower(preg_replace('/\W+/', '-', $g['group']))) ?>"><?= e($g['group']) ?></a><?php endforeach ?></nav>
  </header>
  <?php View::partial('faq-list', ['groups' => $groups, 'schema' => true]) ?>
  <p class="notice">Still have a question? <a href="<?= e(Store::whatsapp()) ?>" target="_blank" rel="noopener">WhatsApp us</a> or <a href="<?= url('contact') ?>">send a message</a>.</p>
</div>
