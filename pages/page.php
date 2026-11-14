<?php
// CMS pages: /about /returns /warranty /privacy /terms
$S = Store::info();
$slug = View::$path;
$p = Store::api()->get("/pages/$slug");
View::meta(['title' => "{$p['title']} | {$S['name']}", 'description' => mb_strimwidth(strip_tags(md($p['body'])), 0, 158, '…'), 'canonical' => "/$slug"]);
$brands = $slug === 'warranty' ? Store::api()->get('/brands') : [];
?>
<div class="wrap narrow">
  <?php View::breadcrumbs([['Home', '/'], [$p['title'], null]]) ?>
  <article class="prose page-body">
    <h1><?= e($p['title']) ?></h1>
    <?= md($p['body']) ?>
    <?php if ($brands): ?>
      <table class="specs"><thead><tr><th>Brand</th><th>Warranty</th></tr></thead><tbody>
      <?php foreach ($brands as $b): ?><tr><td><a href="<?= url("brands/{$b['slug']}") ?>"><?= e($b['name']) ?></a></td><td><?= e($b['warranty_note']) ?></td></tr><?php endforeach ?>
      </tbody></table>
    <?php endif ?>
  </article>
</div>
