<?php /** @var array $groups [{group, items[]}]  @var bool $schema */
$all = [];
?>
<div class="faq">
<?php foreach ($groups as $g): ?>
  <?php if (count($groups) > 1): ?><h3 class="faq-group" id="<?= e(strtolower(preg_replace('/\W+/', '-', $g['group']))) ?>"><?= e($g['group']) ?></h3><?php endif ?>
  <?php foreach ($g['items'] as $f): $all[] = $f ?>
  <details>
    <summary><?= e($f['question']) ?></summary>
    <div class="faq-a"><?= md($f['answer']) ?></div>
  </details>
  <?php endforeach ?>
<?php endforeach ?>
</div>
<?php
if (!empty($schema)) {
    View::jsonld(['@type' => 'FAQPage', 'mainEntity' => array_map(fn($f) => ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']]], $all)]);
}
