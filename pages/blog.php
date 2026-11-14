<?php
$S = Store::info();
$api = Store::api();
$q = array_filter(['category' => $category ?? null, 'tag' => $tag ?? null, 'q' => $_GET['q'] ?? null, 'page' => $_GET['page'] ?? null]);
$res = $api->get('/posts', $q);
$cats = $api->get('/post-categories');
$heading = 'Blog';
if (isset($category)) $heading = (array_values(array_filter($cats, fn($c) => $c['slug'] === $category))[0]['name'] ?? throw new NotFound());
if (isset($tag)) $heading = 'Tagged: ' . ucfirst(str_replace('-', ' ', $tag));
$base = isset($category) ? "/blog/category/$category" : (isset($tag) ? "/blog/tag/$tag" : '/blog');
View::meta(['title' => "$heading — sleep and mattress advice | {$S['name']}", 'description' => 'Mattress buying guides, sleep health tips and care advice from the ' . $S['name'] . ' team in Abuja.', 'canonical' => $base, 'noindex' => !empty($_GET['q'])]);
?>
<div class="wrap">
  <?php View::breadcrumbs(array_values(array_filter([['Home', '/'], ['Blog', $heading === 'Blog' ? null : '/blog'], $heading !== 'Blog' ? [$heading, null] : null]))) ?>
  <header class="page-head"><h1><?= e($heading) ?></h1></header>
  <div class="blog-bar">
    <nav class="chips"><a class="chip-link <?= $base === '/blog' ? 'on' : '' ?>" href="<?= url('blog') ?>">All</a><?php foreach ($cats as $c): ?><a class="chip-link <?= ($category ?? '') === $c['slug'] ? 'on' : '' ?>" href="<?= url("blog/category/{$c['slug']}") ?>"><?= e($c['name']) ?></a><?php endforeach ?></nav>
    <form action="<?= url('blog') ?>" class="mini-search"><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search articles" aria-label="Search articles"></form>
  </div>
  <?php if (!$res['items']): ?><div class="empty"><h2>No articles found</h2></div><?php endif ?>
  <div class="post-grid">
    <?php foreach ($res['items'] as $p): ?>
    <a class="post-card" href="<?= url("blog/{$p['slug']}") ?>">
      <span class="post-cover"><span><?= e($p['category']['name'] ?? 'News') ?></span></span>
      <span class="muted small"><?= e(when($p['published_at'], 'j M Y')) ?> · <?= (int) $p['reading_minutes'] ?> min read</span>
      <h2><?= e($p['title']) ?></h2>
      <p class="muted"><?= e($p['excerpt']) ?></p>
    </a>
    <?php endforeach ?>
  </div>
  <?php View::partial('pagination', ['res' => $res, 'base' => $base]) ?>
</div>
