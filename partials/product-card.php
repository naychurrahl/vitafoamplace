<?php /** @var array $p product card (size pages add $p['size'], priced for that size) */
$href = url("product/{$p['slug']}", ['size' => $p['size'] ?? null]); ?>
<article class="card<?= $p['in_stock'] ? '' : ' is-out' ?>">
  <a class="card-media" href="<?= $href ?>" tabindex="-1" aria-hidden="true">
    <?= product_art($p) ?>
    <span class="tags">
      <?php if ($p['on_sale']): ?><span class="tag tag-sale">Sale</span><?php endif ?>
      <?php if ($p['new_arrival']): ?><span class="tag tag-new">New</span><?php endif ?>
      <?php if (!$p['in_stock']): ?><span class="tag tag-out">Out of stock</span><?php endif ?>
    </span>
  </a>
  <button class="wish" type="button" data-wish="<?= (int) $p['id'] ?>" aria-label="Save <?= e($p['name']) ?> to wishlist" aria-pressed="false"><?= icon('heart') ?></button>
  <div class="card-body">
    <div class="card-brand"><?= e($p['brand']['name']) ?> · <?= e(type_label($p['type'])) ?></div>
    <h3 class="card-title"><a href="<?= $href ?>"><?= e($p['name']) ?></a></h3>
    <?php if ($p['firmness'] || $p['max_load_kg']): ?>
    <div class="card-specs">
      <?php if ($p['firmness']): ?><span class="firm" title="Firmness <?= (int) $p['firmness'] ?> of 5"><?php for ($i = 1; $i <= 5; $i++): ?><i class="<?= $i <= $p['firmness'] ? 'on' : '' ?>"></i><?php endfor ?> <?= e(firmness_label($p['firmness'])) ?></span><?php endif ?>
      <?php if ($p['max_load_kg']): ?><span class="load"><?= icon('weight') ?> up to <?= (int) $p['max_load_kg'] ?>kg</span><?php endif ?>
    </div>
    <?php endif ?>
    <div class="card-foot">
      <div class="price">
        <?php if ($p['price_from'] !== $p['price_to']): ?><small>from</small> <?php endif ?><?= naira($p['price_from']) ?>
        <?php if ($p['compare_at_from']): ?><s><?= naira($p['compare_at_from']) ?></s><?php endif ?>
      </div>
      <?php if ($p['rating']['count']): ?><div class="card-rating"><?= stars($p['rating']['avg'], $p['rating']['count']) ?></div><?php endif ?>
    </div>
    <?php if ($p['sizes'] > 1): ?><div class="muted small"><?= (int) $p['sizes'] ?> <?= isset($p['size']) ? 'thicknesses' : 'sizes' ?></div><?php endif ?>
  </div>
</article>
