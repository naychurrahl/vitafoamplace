<?php
/** /shop · /{category} (old /shop/{category} redirects) · /brands/{brand} · /search?q=
 */
$S = Store::info();
$api = Store::api();
$q = array_intersect_key($_GET, array_flip(['q', 'ids', 'brand', 'type', 'firmness', 'weight', 'size', 'price_min', 'price_max', 'in_stock', 'on_sale', 'featured', 'new', 'sort', 'page']));
foreach (['brand', 'type', 'firmness'] as $k) if (isset($q[$k]) && is_array($q[$k])) $q[$k] = implode(',', $q[$k]);

$cat = $brandRow = null;
$base = '/shop';
if (isset($category) && str_starts_with(View::$path, 'shop/')) redirect("/$category" . ($_SERVER['QUERY_STRING'] ?? '' ? '?' . $_SERVER['QUERY_STRING'] : ''), 301);
if (isset($category)) {
    $cat = array_values(array_filter($api->get('/categories'), fn($c) => $c['slug'] === $category))[0] ?? throw new NotFound();
    $q['category'] = $category;
    $base = "/$category";
}
if (isset($brand)) {
    $brandRow = $api->get("/brands/$brand");
    $q['brand'] = $brand;
    $base = "/brands/$brand";
}
$isSearch = View::$path === 'search';
if ($isSearch) $base = '/search';

$res = $api->get('/products', $q);
$f = $res['facets'];
$sel = fn($k) => isset($_GET[$k]) ? (is_array($_GET[$k]) ? $_GET[$k] : explode(',', (string) $_GET[$k])) : [];
$ignore = ['category', 'sort', 'page'];
if ($brandRow) $ignore[] = 'brand';
if ($isSearch) $ignore[] = 'q';
$filtered = (bool) array_diff_key(array_filter($q, fn($v) => $v !== ''), array_flip($ignore));

if ($cat) {
    $h1 = $cat['name'];
    $intro = $cat['description'];
    $title = ($cat['seo_title'] ?? null) ?: $cat['name'] . ' in Nigeria | Prices, delivery in Abuja & Lagos | ' . $S['name'];
    $crumbs = [['Home', '/'], ['Shop', '/shop'], [$cat['name'], null]];
} elseif ($brandRow) {
    $h1 = $brandRow['name'];
    $intro = $brandRow['description'];
    $title = ($brandRow['slug'] === 'vitafoam' ? 'Original Vitafoam mattresses & pillows – official dealer' : 'Genuine ' . $brandRow['name'] . ' mattresses & pillows') . ' | ' . $S['name'];
    $crumbs = [['Home', '/'], ['Brands', '/brands'], [$brandRow['name'], null]];
} elseif ($isSearch) {
    $h1 = 'Results for “' . ($q['q'] ?? '') . '”';
    $intro = null;
    $title = 'Search: ' . ($q['q'] ?? '') . ' | ' . $S['name'];
    $crumbs = [['Home', '/'], ['Search', null]];
} else {
    $h1 = 'Shop all';
    $intro = 'Mattresses, pillows, toppers and more from Vitafoam, Mouka and Winco. Filter by weight, firmness, size and price.';
    $title = 'Shop mattresses, pillows & toppers online in Nigeria | ' . $S['name'];
    $crumbs = [['Home', '/'], ['Shop', null]];
}
View::meta(['title' => $title, 'description' => ($cat['seo_description'] ?? null) ?: ($intro ? mb_strimwidth($intro, 0, 158, '…') : null), 'canonical' => $base, 'noindex' => $isSearch || $filtered]);

$showMattressFacets = !$cat || $cat['slug'] === 'mattresses';
$typePages = $cat ? array_values(array_filter($api->get('/guide-pages'), fn($g) => in_array($g['kind'], ['type', 'size'], true) && $g['category'] === $cat['slug'])) : [];
$sorts = ['recommended' => 'Recommended', 'popular' => 'Best selling', 'newest' => 'Newest', 'price_asc' => 'Price: low to high', 'price_desc' => 'Price: high to low', 'name' => 'Name A–Z'];
$weights = [60 => 'Up to 60kg', 80 => '60–80kg', 100 => '80–100kg', 120 => '100–120kg', 130 => 'Over 120kg'];
?>
<div class="wrap">
  <?php View::breadcrumbs($crumbs) ?>
  <header class="page-head">
    <h1><?= e($h1) ?></h1>
    <?php if ($intro): ?><p class="lead"><?= e($intro) ?></p><?php endif ?>
    <?php if ($typePages): ?><nav class="chips type-chips" aria-label="Shop by type and size"><?php foreach ($typePages as $t): ?><a class="chip-link" href="<?= url($t['path']) ?>"><?= e(preg_replace('/ in Nigeria.*$/', '', $t['title'])) ?></a><?php endforeach ?><?php if ($cat['slug'] === 'pillows'): ?><a class="chip-link" href="<?= url('pillow-prices') ?>">Price guide</a><?php elseif ($cat['slug'] === 'mattresses'): ?><a class="chip-link" href="<?= url('mattress-prices') ?>">Price guide</a><a class="chip-link" href="<?= url('mattress-sizes') ?>">Size guide</a><?php endif ?></nav><?php endif ?>
  </header>

  <div class="shop" data-shop>
    <aside class="filters" id="filters" data-filters>
      <form method="get" action="<?= url($base) ?>" data-filter-form>
        <div class="filters-head"><strong><?= icon('filter') ?> Filters</strong><?php if ($filtered): ?><a href="<?= url($base, $isSearch ? ['q' => $q['q'] ?? ''] : []) ?>">Clear all</a><?php endif ?>
          <button type="button" class="icon-btn filters-close" data-filters-close aria-label="Close filters"><?= icon('close') ?></button></div>
        <?php if ($isSearch): ?><input type="hidden" name="q" value="<?= e($q['q'] ?? '') ?>"><?php endif ?>
        <?php if (isset($_GET['sort'])): ?><input type="hidden" name="sort" value="<?= e($_GET['sort']) ?>"><?php endif ?>

        <?php if (!$cat && count($f['categories']) > 1): ?>
        <fieldset><legend>Category</legend>
          <?php foreach ($f['categories'] as $c): ?><a class="facet-link" href="<?= url($c['slug']) ?>"><?= e($c['name']) ?> <span><?= (int) $c['count'] ?></span></a><?php endforeach ?>
        </fieldset>
        <?php endif ?>

        <?php if ($showMattressFacets): ?>
        <fieldset><legend>Your weight</legend>
          <?php foreach ($weights as $w => $label): ?>
          <label class="opt"><input type="radio" name="weight" value="<?= $w ?>" <?= ($_GET['weight'] ?? '') == $w ? 'checked' : '' ?>> <?= e($label) ?></label>
          <?php endforeach ?>
          <?php if (!empty($_GET['weight'])): ?><label class="opt"><input type="radio" name="weight" value=""> Any weight</label><?php endif ?>
        </fieldset>
        <?php if ($f['types']): ?>
        <fieldset><legend>Mattress type</legend>
          <?php foreach ($f['types'] as $t): ?><label class="opt"><input type="checkbox" name="type[]" value="<?= e($t['value']) ?>" <?= in_array($t['value'], $sel('type'), true) ? 'checked' : '' ?>> <?= e(type_label($t['value'])) ?> <span><?= (int) $t['count'] ?></span></label><?php endforeach ?>
        </fieldset>
        <?php endif ?>
        <fieldset><legend>Firmness</legend>
          <div class="chips">
          <?php foreach ([1, 2, 3, 4, 5] as $n): ?><label class="chip"><input type="checkbox" name="firmness[]" value="<?= $n ?>" <?= in_array((string) $n, $sel('firmness'), true) ? 'checked' : '' ?>><span><?= $n ?> · <?= e(firmness_label($n)) ?></span></label><?php endforeach ?>
          </div>
        </fieldset>
        <fieldset><legend>Size</legend>
          <select name="size"><option value="">Any size</option>
            <?php foreach ($f['sizes'] as $s): ?><option value="<?= e($s['value']) ?>" <?= ($_GET['size'] ?? '') === $s['value'] ? 'selected' : '' ?>><?= e($s['label']) ?></option><?php endforeach ?>
          </select>
        </fieldset>
        <?php endif ?>

        <?php if (!$brandRow && count($f['brands']) > 1): ?>
        <fieldset><legend>Brand</legend>
          <?php foreach ($f['brands'] as $b): ?><label class="opt"><input type="checkbox" name="brand[]" value="<?= e($b['slug']) ?>" <?= in_array($b['slug'], $sel('brand'), true) ? 'checked' : '' ?>> <?= e($b['name']) ?> <span><?= (int) $b['count'] ?></span></label><?php endforeach ?>
        </fieldset>
        <?php endif ?>

        <fieldset><legend>Price (₦)</legend>
          <div class="row2"><input type="number" name="price_min" inputmode="numeric" placeholder="Min" value="<?= e($_GET['price_min'] ?? '') ?>" step="1000" min="0"><input type="number" name="price_max" inputmode="numeric" placeholder="Max" value="<?= e($_GET['price_max'] ?? '') ?>" step="1000" min="0"></div>
        </fieldset>
        <fieldset>
          <label class="opt"><input type="checkbox" name="in_stock" value="1" <?= !empty($_GET['in_stock']) ? 'checked' : '' ?>> In stock only</label>
          <label class="opt"><input type="checkbox" name="on_sale" value="1" <?= !empty($_GET['on_sale']) ? 'checked' : '' ?>> On sale</label>
        </fieldset>
        <button class="btn btn-primary btn-block" data-filter-submit>Show results</button>
      </form>
    </aside>

    <section class="results" data-results>
      <div class="results-bar">
        <button type="button" class="btn btn-ghost filters-open" data-filters-open><?= icon('filter') ?> Filters</button>
        <span class="muted"><strong><?= (int) $res['total'] ?></strong> product<?= $res['total'] === 1 ? '' : 's' ?></span>
        <form method="get" action="<?= url($base) ?>" class="sort" data-sort-form>
          <?php foreach ($_GET as $k => $v): if ($k === 'sort' || $k === 'page') continue; foreach ((array) $v as $vv): ?><input type="hidden" name="<?= e(is_array($v) ? $k . '[]' : $k) ?>" value="<?= e($vv) ?>"><?php endforeach; endforeach ?>
          <label>Sort <select name="sort" data-autosubmit><?php foreach ($sorts as $k => $l): ?><option value="<?= $k ?>" <?= ($_GET['sort'] ?? 'recommended') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></label>
          <noscript><button class="btn btn-ghost">Sort</button></noscript>
        </form>
      </div>
      <?php if ($res['items']): ?>
        <div class="grid grid-3"><?php foreach ($res['items'] as $p) View::partial('product-card', ['p' => $p]) ?></div>
        <?php View::partial('pagination', ['res' => $res, 'base' => $base]) ?>
      <?php else: ?>
        <div class="empty">
          <h2>No products match those filters</h2>
          <p>Try a different size or weight, or <a href="<?= url($base) ?>">clear the filters</a>. Can't find what you need? <a href="<?= e(Store::whatsapp('Hello, I am looking for: ' . ($q['q'] ?? 'a mattress'))) ?>" target="_blank" rel="noopener">Ask us on WhatsApp</a>.</p>
        </div>
      <?php endif ?>
    </section>
  </div>

  <?php if ($cat && (!empty($cat['buying_advice']) || !empty($cat['faqs'])) && empty($_GET['page'])): ?>
  <div class="landing-more">
    <?php if (!empty($cat['buying_advice'])): ?><section class="prose"><?= md($cat['buying_advice']) ?></section><?php endif ?>
    <?php if (!empty($cat['faqs'])): ?><section><h2>Questions</h2><?php View::partial('faq-list', ['groups' => [['group' => '', 'items' => $cat['faqs']]], 'schema' => true]) ?></section><?php endif ?>
  </div>
  <?php endif ?>
</div>
