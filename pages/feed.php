<?php
// /feeds/google.xml — Google Merchant Center product feed (RSS 2.0 + g: namespace), one item per size.
// Merchant Center rejects items without an image, so products appear once real photos are uploaded in the CMS.
header('Content-Type: application/xml; charset=utf-8');
View::meta(['raw' => true]);
$S = Store::info();
$feed = Store::api()->get('/feed/products');
$x = fn($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
<channel>
  <title><?= $x($S['name']) ?></title>
  <link><?= $x(View::absolute('/')) ?></link>
  <description><?= $x($S['tagline']) ?></description>
<?php foreach ($feed['items'] as $i): ?>
  <item>
    <g:id><?= $x($i['id']) ?></g:id>
    <g:item_group_id><?= $x($i['item_group_id']) ?></g:item_group_id>
    <g:title><?= $x($i['title']) ?></g:title>
    <g:description><?= $x($i['description']) ?></g:description>
    <g:link><?= $x(View::absolute($i['path'])) ?></g:link>
<?php if ($i['image']): ?>    <g:image_link><?= $x($i['image']) ?></g:image_link>
<?php endif ?>
    <g:brand><?= $x($i['brand']) ?></g:brand>
    <g:condition><?= $x($i['condition']) ?></g:condition>
    <g:availability><?= $x($i['availability']) ?></g:availability>
    <g:price><?= number_format($i['price'], 2, '.', '') ?> <?= $x($feed['currency']) ?></g:price>
<?php if ($i['sale_price']): ?>    <g:sale_price><?= number_format($i['sale_price'], 2, '.', '') ?> <?= $x($feed['currency']) ?></g:sale_price>
<?php endif ?>
    <g:size><?= $x($i['size']) ?></g:size>
    <g:product_type><?= $x($i['product_type']) ?></g:product_type>
    <g:google_product_category><?= $x($i['google_category']) ?></g:google_product_category>
    <g:identifier_exists>no</g:identifier_exists>
  </item>
<?php endforeach ?>
</channel>
</rss>
