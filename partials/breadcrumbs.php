<nav class="crumbs" aria-label="Breadcrumb">
  <?php foreach ($crumbs as $i => [$label, $href]): ?>
    <?php if ($i) echo icon('chev') ?>
    <?php if ($href && $i < count($crumbs) - 1): ?><a href="<?= url($href) ?>"><?= e($label) ?></a><?php else: ?><span aria-current="page"><?= e($label) ?></span><?php endif ?>
  <?php endforeach ?>
</nav>
