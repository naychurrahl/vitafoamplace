<?php /** @var array $res paginated response  @var string $base */
if (($res['pages'] ?? 1) <= 1) return;
$q = $_GET;
?>
<nav class="pager" aria-label="Pages">
  <?php for ($i = 1; $i <= $res['pages']; $i++): $q['page'] = $i; ?>
    <?php if ($i === $res['page']): ?><span class="on" aria-current="page"><?= $i ?></span>
    <?php else: ?><a href="<?= url($base, $q) ?>"><?= $i ?></a><?php endif ?>
  <?php endfor ?>
</nav>
