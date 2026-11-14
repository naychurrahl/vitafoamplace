<?php
/** @var array $S store config  @var string $content page HTML */
$m = View::$meta;
$title = $m['title'] ?? ($S['seo']['title'] ?? $S['name']);
$desc = $m['description'] ?? ($S['seo']['description'] ?? $S['tagline']);
$canonical = View::absolute($m['canonical'] ?? '/' . View::$path);
$theme = $S['theme'];
View::jsonld([
    '@type' => 'FurnitureStore', 'name' => $S['name'], 'url' => View::absolute('/'), 'telephone' => phone_tel(Store::contact('phone') ?? ''),
    'address' => ['@type' => 'PostalAddress', 'streetAddress' => Store::location()['street'], 'addressLocality' => 'Abuja', 'addressCountry' => 'NG'],
    'areaServed' => array_map(fn($c) => ['@type' => 'City', 'name' => $c], array_keys(Store::zoneCities())),
    'geo' => ['@type' => 'GeoCoordinates', 'latitude' => Store::location()['lat'], 'longitude' => Store::location()['lng']],
]);
?><!doctype html>
<html lang="en-NG">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($m['noindex'])): ?><meta name="robots" content="noindex"><?php endif ?>
<link rel="canonical" href="<?= e($canonical) ?>">
<meta property="og:type" content="<?= e($m['og_type'] ?? 'website') ?>">
<meta property="og:title" content="<?= e($title) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:url" content="<?= e($canonical) ?>">
<meta property="og:site_name" content="<?= e($S['name']) ?>">
<meta name="theme-color" content="<?= e($theme['primary']) ?>">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<meta name="base" content="<?= e(base_path()) ?>">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode("<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='{$theme['primary']}'/><text x='16' y='22' font-family='Georgia' font-size='18' font-weight='700' text-anchor='middle' fill='#fff'>" . mb_substr($S['name'], 0, 1) . "</text></svg>") ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/site.css') ?>">
<style>:root{--primary:<?= e($theme['primary']) ?>;--accent:<?= e($theme['accent']) ?>;--surface:<?= e($theme['surface']) ?>;--ink:<?= e($theme['ink']) ?>}</style>
<?php foreach (View::$jsonld as $ld): ?><script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<?php endforeach ?>
<script type="module" src="<?= asset('js/app.js') ?>"></script>
</head>
<body class="<?= e(View::$bodyClass) ?>">
<?php require __DIR__ . '/icons.php' ?>
<a class="skip" href="#main">Skip to content</a>
<?php require __DIR__ . '/header.php' ?>
<?php if ($f = flash()): ?><div class="wrap"><p class="notice <?= e($f['type']) ?> flash"><?= e($f['msg']) ?></p></div><?php endif ?>
<main id="main"><?= $content ?></main>
<?php require __DIR__ . '/footer.php' ?>
<a class="wa-float" href="<?= e(Store::whatsapp('Hello ' . $S['name'] . ', I need help choosing a mattress.')) ?>" target="_blank" rel="noopener" aria-label="Chat on WhatsApp"><?= icon('whatsapp') ?></a>
<div class="toast" role="status" aria-live="polite" hidden></div>
<?php if (Api::config()['api']['driver'] === 'mock'): ?>
<div class="demo-flag" title="The storefront is reading mock/data.json through mock/MockApi.php">Demo · mock data</div>
<?php endif ?>
</body>
</html>
