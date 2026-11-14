<?php
header('Content-Type: text/plain; charset=utf-8');
View::meta(['raw' => true]);
?>
User-agent: *
Disallow: /cart
Disallow: /checkout
Disallow: /order/
Disallow: /account
Disallow: /search
Disallow: /_api/
Disallow: /pay/
Disallow: /feeds/

Sitemap: <?= View::absolute('/sitemap.xml') ?>
