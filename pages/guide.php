<?php
$S = Store::info();
$api = Store::api();
$asked = isset($_GET['weight_kg']);
$in = [
    'weight_kg' => (int) ($_GET['weight_kg'] ?? 80), 'position' => $_GET['position'] ?? 'mixed', 'back_pain' => !empty($_GET['back_pain']),
    'shared' => !empty($_GET['shared']), 'size' => $_GET['size'] ?? '', 'budget' => (int) ($_GET['budget'] ?? 0),
];
$res = $asked ? $api->post('/guide/recommend', $in) : null;
$pages = $api->get('/guide-pages');
$sizes = ['6x3.5' => '6ft × 3.5ft (single)', '6x4' => '6ft × 4ft', '6x4.5' => '6ft × 4.5ft (double)', '6x5' => '6ft × 5ft (queen)', '6x6' => '6ft × 6ft (king)', '6x7' => '6ft × 7ft (super king)'];
View::meta(['title' => "Buyer's guide: find the right mattress for your weight | {$S['name']}",
            'description' => 'Answer five quick questions about your weight, sleeping position and budget, and see the mattresses built for you, with the reasons why.',
            'canonical' => '/buyers-guide', 'noindex' => $asked]);
$opt = fn($name, $val, $cur) => '<option value="' . e($val) . '"' . ((string) $cur === (string) $val ? ' selected' : '') . '>';
?>
<div class="wrap">
  <?php View::breadcrumbs([['Home', '/'], ["Buyer's guide", null]]) ?>
  <header class="page-head">
    <h1>Find the right mattress</h1>
    <p class="lead">Five quick questions. We match you on weight capacity first, then firmness and type — the way our showroom staff would.</p>
  </header>

  <div class="guide">
    <form class="guide-form card-box" method="get" action="<?= url('buyers-guide') ?>" data-guide>
      <label>How much do you weigh?
        <select name="weight_kg">
          <?php foreach ([60 => 'Under 60kg', 80 => '60–80kg', 100 => '80–100kg', 120 => '100–120kg', 140 => 'Over 120kg'] as $v => $l): ?><?= $opt('weight_kg', $v, $in['weight_kg']) . e($l) ?></option><?php endforeach ?>
        </select>
      </label>
      <fieldset><legend>How do you usually sleep?</legend>
        <div class="chips">
        <?php foreach (['back' => 'On my back', 'side' => 'On my side', 'stomach' => 'On my stomach', 'mixed' => 'It changes'] as $v => $l): ?>
          <label class="chip"><input type="radio" name="position" value="<?= $v ?>" <?= $in['position'] === $v ? 'checked' : '' ?>><span><?= e($l) ?></span></label>
        <?php endforeach ?>
        </div>
      </fieldset>
      <div class="chips">
        <label class="chip"><input type="checkbox" name="back_pain" value="1" <?= $in['back_pain'] ? 'checked' : '' ?>><span>I have back pain</span></label>
        <label class="chip"><input type="checkbox" name="shared" value="1" <?= $in['shared'] ? 'checked' : '' ?>><span>I share the bed</span></label>
      </div>
      <label>Bed size
        <select name="size"><option value="">Any size</option><?php foreach ($sizes as $v => $l): ?><?= $opt('size', $v, $in['size']) . e($l) ?></option><?php endforeach ?></select>
      </label>
      <label>Budget
        <select name="budget"><option value="0">No limit</option><?php foreach ([100000, 200000, 300000, 500000, 800000] as $b): ?><?= $opt('budget', $b, $in['budget']) ?>Up to <?= naira($b) ?></option><?php endforeach ?></select>
      </label>
      <button class="btn btn-primary btn-block">Show my matches</button>
    </form>

    <section class="guide-results" data-guide-results>
      <?php if (!$res): ?>
        <div class="guide-intro card-box">
          <h2>How we choose</h2>
          <ol class="steps">
            <li><strong>Weight capacity.</strong> Every size has a max load per sleeper. We only show sizes rated above your weight.</li>
            <li><strong>Firmness.</strong> 4–5 for back pain and heavier sleepers; 3–4 for side sleepers, who need some give at the shoulder.</li>
            <li><strong>Type.</strong> Orthopedic for support, memory foam and hybrid for pressure relief and couples.</li>
          </ol>
          <h3>Popular guides</h3>
          <div class="guide-links"><?php foreach ($pages as $g): ?><a href="<?= url("buyers-guide/{$g['slug']}") ?>"><?= e($g['title']) ?> <?= icon('chev') ?></a><?php endforeach ?></div>
        </div>
      <?php else: ?>
        <div class="understood">
          <strong>What we understood:</strong>
          <?= e($in['weight_kg'] >= 140 ? 'over 120kg' : "about {$in['weight_kg']}kg") ?> ·
          <?= e(['back' => 'back sleeper', 'side' => 'side sleeper', 'stomach' => 'stomach sleeper', 'mixed' => 'mixed sleeper'][$in['position']] ?? '') ?>
          <?= $in['back_pain'] ? ' · back pain' : '' ?><?= $in['shared'] ? ' · sharing' : '' ?>
          <?= $in['size'] ? ' · ' . e($sizes[$in['size']] ?? $in['size']) : '' ?><?= $in['budget'] ? ' · up to ' . naira($in['budget']) : '' ?>
          <span class="muted">→ ideal firmness <?= implode('–', array_map('intval', array_reverse($res['understood']['ideal_firmness']))) ?> of 5</span>
        </div>
        <?php if (!$res['items']): ?>
          <div class="empty"><h2>Nothing fits all of that</h2><p>Try a bigger budget or another size, or <a href="<?= e(Store::whatsapp('Hello, I need help choosing a mattress.')) ?>" target="_blank" rel="noopener">ask us on WhatsApp</a>.</p></div>
        <?php endif ?>
        <ol class="matches">
          <?php foreach ($res['items'] as $n => $m): $p = $m['product']; ?>
          <li class="match">
            <a class="match-art" href="<?= url("product/{$p['slug']}", ['size' => $m['variant']['id']]) ?>"><?= product_art($p) ?></a>
            <div class="match-body">
              <div class="match-top"><span class="score"><?= (int) $m['score'] ?>% match</span><?php if ($n === 0): ?><span class="pill pill-ok">Best match</span><?php endif ?></div>
              <h3><a href="<?= url("product/{$p['slug']}", ['size' => $m['variant']['id']]) ?>"><?= e($p['name']) ?></a></h3>
              <p class="muted"><?= e($p['brand']['name']) ?> · <?= e(type_label($p['type'])) ?> · firmness <?= (int) $p['firmness'] ?>/5</p>
              <ul class="reasons"><?php foreach ($m['reasons'] as $r): ?><li><?= icon('check') ?> <?= e($r) ?></li><?php endforeach ?></ul>
            </div>
            <div class="match-buy">
              <span class="muted small"><?= e(str_replace(' X ', ' × ', $m['variant']['size_label'])) ?></span>
              <strong class="price"><?= naira($m['variant']['price']) ?></strong>
              <a class="btn btn-primary btn-sm" href="<?= url("product/{$p['slug']}", ['size' => $m['variant']['id']]) ?>">View</a>
            </div>
          </li>
          <?php endforeach ?>
        </ol>
      <?php endif ?>
    </section>
  </div>
</div>
