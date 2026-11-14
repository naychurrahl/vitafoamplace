<?php
header('Content-Type: application/xml; charset=utf-8');
View::meta(['raw' => true]);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach (Store::api()->get('/sitemap') as $u) {
    echo '  <url><loc>' . e(View::absolute($u['loc'])) . '</loc>' . (!empty($u['lastmod']) ? '<lastmod>' . e($u['lastmod']) . '</lastmod>' : '') . "</url>\n";
}
echo '</urlset>';
