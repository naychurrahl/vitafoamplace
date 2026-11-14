<?php
$S = Store::info();
$api = Store::api();
$msg = null; $errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try { $msg = $api->post("/posts/$slug/comments", ['name' => $_POST['name'] ?? '', 'body' => $_POST['body'] ?? ''])['message']; }
    catch (ApiError $e) { $errors = $e->fields(); }
}
$p = $api->get("/posts/$slug");
$comments = $api->get("/posts/$slug/comments");
View::meta(['title' => "{$p['title']} | {$S['name']}", 'description' => $p['excerpt'], 'canonical' => "/blog/$slug", 'og_type' => 'article']);
View::jsonld(['@type' => 'BlogPosting', 'headline' => $p['title'], 'datePublished' => date('c', strtotime($p['published_at'])), 'author' => ['@type' => 'Organization', 'name' => $S['name']], 'description' => $p['excerpt']]);
$share = View::absolute("/blog/$slug");
?>
<div class="wrap">
  <?php View::breadcrumbs(array_values(array_filter([['Home', '/'], ['Blog', '/blog'], $p['category'] ? [$p['category']['name'], "/blog/category/{$p['category']['slug']}"] : null, [$p['title'], null]]))) ?>
  <div class="article-layout">
    <article class="article">
      <header>
        <h1><?= e($p['title']) ?></h1>
        <p class="muted"><?= e(when($p['published_at'], 'j F Y')) ?> · <?= (int) $p['reading_minutes'] ?> min read · <?= number_format($p['view_count']) ?> views</p>
      </header>
      <div class="prose"><?= md($p['body']) ?></div>
      <?php if ($p['tags']): ?><p class="tags-row"><?php foreach ($p['tags'] as $t): ?><a class="pill" href="<?= url("blog/tag/{$t['slug']}") ?>">#<?= e($t['name']) ?></a><?php endforeach ?></p><?php endif ?>
      <p class="share">Share:
        <a href="https://wa.me/?text=<?= rawurlencode($p['title'] . ' ' . $share) ?>" target="_blank" rel="noopener">WhatsApp</a> ·
        <a href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode($share) ?>" target="_blank" rel="noopener">Facebook</a> ·
        <a href="https://x.com/intent/post?url=<?= rawurlencode($share) ?>&text=<?= rawurlencode($p['title']) ?>" target="_blank" rel="noopener">X</a></p>

      <section class="comments" id="comments">
        <h2>Comments (<?= count($comments) ?>)</h2>
        <?php foreach ($comments as $c): ?><div class="comment"><strong><?= e($c['name']) ?></strong> <span class="muted small"><?= e(ago($c['created_at'])) ?></span><p><?= e($c['body']) ?></p></div><?php endforeach ?>
        <?php if ($msg): ?><p class="notice ok"><?= e($msg) ?></p><?php else: ?>
        <form method="post" action="<?= url("blog/$slug") ?>#comments" class="form">
          <?= csrf_field() ?>
          <label>Name<input name="name" value="<?= e($_POST['name'] ?? '') ?>" required></label><?php if (isset($errors['name'])): ?><p class="err"><?= e($errors['name']) ?></p><?php endif ?>
          <label>Comment<textarea name="body" rows="3" required><?= e($_POST['body'] ?? '') ?></textarea></label><?php if (isset($errors['body'])): ?><p class="err"><?= e($errors['body']) ?></p><?php endif ?>
          <button class="btn btn-primary">Post comment</button>
        </form>
        <?php endif ?>
      </section>
    </article>
    <aside class="article-side">
      <div class="card-box cta-box"><h3>Need help choosing?</h3><p>Match a mattress to your weight and sleep style in under a minute.</p><a class="btn btn-primary btn-block" href="<?= url('buyers-guide') ?>">Buyer's guide</a></div>
      <h3>Recent posts</h3>
      <?php foreach ($p['recent'] as $r): ?><a class="post-row" href="<?= url("blog/{$r['slug']}") ?>"><strong><?= e($r['title']) ?></strong><span class="muted small"><?= e(when($r['published_at'], 'j M Y')) ?></span></a><?php endforeach ?>
    </aside>
  </div>
</div>
