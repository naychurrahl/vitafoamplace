<?php
header('Content-Type: text/plain; charset=utf-8');
View::meta(['raw' => true]);
if (!empty(Store::info()['demo'])) { echo "# Demo store: keep it out of search results.\nUser-agent: *\nDisallow: /\n"; return; }
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
