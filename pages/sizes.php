<?php
/** /mattress-sizes — the standard Nigerian sizes, with links to every mattress in each size. */
$S = Store::info();
$facets = Store::api()->get('/products', ['category' => 'mattresses', 'per_page' => 1])['facets']['sizes'];
$counts = array_column($facets, 'count', 'value');
$sizes = [
    '6x3.5' => ['Single', '3.5ft × 6ft', '107 × 183cm', 'One adult or a child. Fits most single beds and bunk beds.'],
    '6x4'   => ['Small double', '4ft × 6ft', '122 × 183cm', 'One adult who wants more room, or a small double bed.'],
    '6x4.5' => ['Double', '4.5ft × 6ft', '137 × 183cm', 'The most common double size in Nigeria. Two adults, snugly.'],
    '6x5'   => ['Queen', '5ft × 6ft', '152 × 183cm', 'Two adults with room to move.'],
    '6x6'   => ['King', '6ft × 6ft', '183 × 183cm', 'Two adults, or a couple and a small child.'],
    '6x7'   => ['Super king', '7ft × 6ft', '213 × 183cm', 'The widest size, for large bed frames and families.'],
];
View::meta([
    'title' => "Mattress Sizes in Nigeria: Single, Double, Queen, King | {$S['name']}",
    'description' => 'Nigerian mattress sizes explained: 6ft × 3.5ft single to 6ft × 7ft super king, in feet and centimetres, with thickness and weight advice. Shop Vitafoam mattresses by size.',
    'canonical' => '/mattress-sizes',
]);
$faqs = [
    ['question' => 'What is the standard mattress size in Nigeria?', 'answer' => 'Nigerian mattresses are almost all 6ft long. The width changes: 3.5ft (single), 4ft, 4.5ft (double), 5ft (queen), 6ft (king) and 7ft (super king).'],
    ['question' => 'How thick should my mattress be?', 'answer' => 'For one adult, 6–8 inches is usually enough. For two adults or anyone over 100kg, choose 8 inches or more — thicker sizes are rated for more weight.'],
    ['question' => 'What size is a 6x6 mattress?', 'answer' => 'A 6x6 (king) mattress is 6ft wide and 6ft long — about 183 × 183cm.'],
];
?>
<div class="wrap narrow">
  <?php View::breadcrumbs([['Home', '/'], ['Mattresses', '/mattresses'], ['Size guide', null]]) ?>
  <header class="page-head">
    <h1>Mattress sizes in Nigeria</h1>
    <p class="lead">Nigerian mattresses are 6ft long; what changes is the width. Measure the <strong>inside of your bed frame</strong>, not your old mattress — old mattresses sag and spread.</p>
  </header>
  <div class="table-scroll"><table class="zones">
    <thead><tr><th>Name</th><th>Size (W × L)</th><th>In cm</th><th>Good for</th><th></th></tr></thead>
    <tbody><?php foreach ($sizes as $key => [$name, $ft, $cm, $note]): ?>
      <tr><td><strong><?= e($name) ?></strong></td><td><?= e($ft) ?></td><td><?= e($cm) ?></td><td><?= e($note) ?></td>
        <td><?php if (!empty($counts[$key])): ?><a href="<?= url('mattresses', ['size' => $key]) ?>"><?= (int) $counts[$key] ?> mattresses</a><?php endif ?></td></tr>
    <?php endforeach ?></tbody>
  </table></div>
  <section class="prose">
    <h2>Thickness and weight</h2>
    <p>Thickness runs from 4" to 12". Thicker mattresses carry more weight and last longer: every size on our product pages shows its <strong>maximum load per sleeper</strong>. Use the <a href="<?= url('buyers-guide') ?>">buyer's guide</a> to match a mattress to your weight, or compare prices in the <a href="<?= url('mattress-prices') ?>">mattress price guide</a>.</p>
  </section>
  <section><h2>Questions</h2><?php View::partial('faq-list', ['groups' => [['group' => '', 'items' => $faqs]], 'schema' => true]) ?></section>
</div>
