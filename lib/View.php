<?php
// Page rendering: runs pages/<name>.php into a buffer, then wraps it in partials/layout.php.
// Pages set their <head> data with View::meta() and can throw NotFound.

final class NotFound extends \RuntimeException {}

final class View
{
    public static array $meta = [];
    public static array $jsonld = [];
    public static string $bodyClass = '';
    public static string $path = '';

    public static function dispatch(string $page, array $params, string $path): void
    {
        self::$path = $path;
        try {
            if ($page === '404') throw new NotFound();
            self::render($page, $params);
        } catch (NotFound) {
            self::notFound();
        } catch (ApiError $e) {
            if ($e->status === 404) { self::notFound(); return; }
            http_response_code(500);
            self::$meta = ['title' => 'Something went wrong'];
            self::render('error', ['error' => $e]);
        }
    }

    /** Old URLs (e.g. from a previous site) are managed as redirects in the CMS; anything else is a real 404. */
    private static function notFound(): void
    {
        // Landing pages (e.g. /orthopedic-mattresses) live at whatever path the CMS gives them.
        $l = Store::api()->request('GET', '/landing', ['path' => '/' . self::$path]);
        if ($l['status'] === 200) { http_response_code(200); self::render('guide-page', ['landing' => $l['data']]); return; }
        // Categories live at the top level too (/mattresses, /pillows), straight from the CMS.
        foreach (Store::api()->get('/categories') as $c) {
            if ($c['slug'] === self::$path) { http_response_code(200); self::render('shop', ['category' => $c['slug']]); return; }
        }
        $r = Store::api()->request('GET', '/redirects/resolve', ['path' => '/' . self::$path]);
        if ($r['status'] === 200) redirect($r['data']['to'], (int) $r['data']['code']);
        http_response_code(404);
        self::$meta = [];
        self::$jsonld = [];
        self::render('404', []);
    }

    private static function render(string $page, array $params): void
    {
        extract($params);
        $S = Store::info();
        ob_start();
        try {
            require __DIR__ . "/../pages/$page.php";
        } catch (\Throwable $t) {
            ob_end_clean();
            throw $t;
        }
        $content = ob_get_clean();
        if (!empty(self::$meta['raw'])) { echo $content; return; }
        // Resolve cart and login now: either may need to clear a stale cookie, which can't happen once HTML is sent.
        Store::cart();
        Store::me();
        require __DIR__ . '/../partials/layout.php';
    }

    public static function meta(array $m): void
    {
        self::$meta = $m + self::$meta;
    }

    public static function jsonld(array $data): void
    {
        self::$jsonld[] = ['@context' => 'https://schema.org'] + $data;
    }

    public static function partial(string $name, array $vars = []): void
    {
        extract($vars);
        $S = Store::info();
        require __DIR__ . "/../partials/$name.php";
    }

    public static function absolute(string $path): string
    {
        $scheme = !empty($_SERVER['HTTPS']) ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url($path);
    }

    public static function breadcrumbs(array $crumbs): void
    {
        $items = [];
        foreach ($crumbs as $i => [$label, $href]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $label] + ($href ? ['item' => self::absolute($href)] : []);
        }
        self::jsonld(['@type' => 'BreadcrumbList', 'itemListElement' => $items]);
        self::partial('breadcrumbs', ['crumbs' => $crumbs]);
    }
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="i ' . $class . '" aria-hidden="true"><use href="#i-' . $name . '"/></svg>';
}

function stars(?float $avg, int $count = 0, bool $showCount = true): string
{
    if (!$avg) return '';
    $full = (int) round($avg);
    $s = '<span class="stars" aria-label="Rated ' . $avg . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) $s .= '<span class="' . ($i <= $full ? 'on' : '') . '">★</span>';
    return $s . '</span>' . ($showCount ? ' <span class="muted">' . $avg . ' (' . $count . ')</span>' : '');
}

function type_label(?string $t): string
{
    return ['orthopedic' => 'Orthopedic', 'semi-orthopedic' => 'Semi-orthopedic', 'spring' => 'Spring', 'hybrid' => 'Hybrid', 'memory' => 'Memory foam',
            'foam' => 'Foam', 'memory-pillow' => 'Memory pillow', 'fibre-pillow' => 'Fibre pillow', 'topper' => 'Topper', 'baby-mattress' => 'Baby mattress',
            'baby-seat' => 'Baby seat', 'sofa-bed' => 'Sofa bed'][$t ?? ''] ?? ucfirst((string) $t);
}

function firmness_label(?int $f): string
{
    return [1 => 'Soft', 2 => 'Medium-soft', 3 => 'Medium', 4 => 'Firm', 5 => 'Extra firm'][$f ?? 0] ?? '';
}

function good_label(string $g): string
{
    return ['back-pain' => 'Back pain', 'heavy-sleeper' => 'Heavier sleepers', 'back-sleeper' => 'Back sleepers', 'side-sleeper' => 'Side sleepers',
            'couples' => 'Couples', 'kids' => 'Children', 'guest-room' => 'Guest rooms', 'budget' => 'Budget', 'neck-pain' => 'Neck pain',
            'firm-mattress-fix' => 'Softening a firm bed'][$g] ?? ucfirst(str_replace('-', ' ', $g));
}

/** Product illustration (until real photos are uploaded in the CMS). */
function product_art(array $p, string $class = ''): string
{
    if (!empty($p['image'])) return '<img class="art ' . $class . '" src="' . e($p['image']) . '" alt="' . e(($p['image_alt'] ?? null) ?: ($p['media'][0]['alt'] ?? null) ?: $p['name']) . '" loading="lazy">';
    $brand = $p['brand']['color'] ?? '#14365d';
    $type = $p['type'] ?? 'foam';
    $id = 'a' . substr(md5($p['slug'] . $class), 0, 6);
    $label = e(strtoupper($p['brand']['name'] ?? ''));
    if (in_array($type, ['memory-pillow', 'fibre-pillow'], true)) {
        $fill = $type === 'memory-pillow' ? '#e9eef3' : '#f7f4ee';
        $svg = "<ellipse cx='200' cy='238' rx='150' ry='16' fill='#000' opacity='.08'/>"
             . "<path d='M70 120 Q60 80 110 78 L290 78 Q340 80 330 120 L330 190 Q340 228 290 228 L110 228 Q60 228 70 190 Z' fill='$fill' stroke='#d9d2c5' stroke-width='2'/>"
             . "<path d='M80 125 Q200 100 320 125' stroke='#d9d2c5' stroke-width='2' fill='none'/>"
             . "<rect x='70' y='196' width='260' height='6' rx='3' fill='$brand' opacity='.85'/>"
             . ($type === 'memory-pillow' ? "<path d='M110 150 Q200 135 290 150' stroke='#cfd8e1' stroke-width='10' fill='none' stroke-linecap='round'/>" : '');
    } elseif ($type === 'baby-seat') {
        $svg = "<ellipse cx='200' cy='245' rx='110' ry='14' fill='#000' opacity='.08'/>"
             . "<path d='M110 230 L120 150 Q125 90 200 90 Q275 90 280 150 L290 230 Z' fill='#f3e7d7' stroke='#e0cfb8' stroke-width='2'/>"
             . "<path d='M140 230 L150 170 Q200 150 250 170 L260 230 Z' fill='#fbf5ec'/><rect x='110' y='222' width='180' height='8' rx='4' fill='$brand'/>";
    } elseif ($type === 'sofa-bed') {
        $svg = "<ellipse cx='200' cy='248' rx='170' ry='14' fill='#000' opacity='.08'/>"
             . "<rect x='55' y='110' width='290' height='80' rx='14' fill='#5b6b7a'/><rect x='40' y='150' width='40' height='85' rx='10' fill='#4b5a68'/>"
             . "<rect x='320' y='150' width='40' height='85' rx='10' fill='#4b5a68'/><rect x='75' y='175' width='250' height='55' rx='10' fill='#6c7d8d'/>"
             . "<rect x='75' y='222' width='250' height='6' fill='$brand'/>";
    } else {
        $t = ['topper' => 2, 'baby-mattress' => 4, 'memory' => 11, 'hybrid' => 12, 'spring' => 11, 'orthopedic' => 10, 'semi-orthopedic' => 9][$type] ?? 7;
        $h = 18 + $t * 5.5;
        $w = $type === 'baby-mattress' ? 150 : 220;
        $x = 200 - ($w + 80) / 2;
        $top = 150 - $h / 2;
        $top_fill = $type === 'topper' ? '#fbf8f2' : '#f7f4ee';
        $quilt = '';
        if (in_array($type, ['orthopedic', 'memory', 'hybrid', 'semi-orthopedic', 'spring'], true)) {
            for ($i = 1; $i < 7; $i++) {
                $qx = $x + $i * ($w / 7);
                $quilt .= "<line x1='$qx' y1='" . ($top + 50) . "' x2='" . ($qx + 80) . "' y2='$top' stroke='#e3ddd1' stroke-width='1.4'/>";
            }
            $quilt .= "<path d='M" . ($x + 20) . ' ' . ($top + 40) . ' L' . ($x + 90) . " $top' stroke='#e3ddd1' stroke-width='1.4'/>";
        }
        $front_y = $top + 50;
        $band = $type === 'topper' ? '' : "<rect x='$x' y='" . ($front_y + $h - 10) . "' width='$w' height='10' fill='$brand'/>"
              . "<text x='" . ($x + 14) . "' y='" . ($front_y + $h / 2 + 2) . "' font-family='Inter,Arial,sans-serif' font-size='11' font-weight='700' letter-spacing='2' fill='$brand' opacity='.8'>$label</text>";
        $svg = "<ellipse cx='" . (200 + 10) . "' cy='" . ($front_y + $h + 14) . "' rx='" . ($w / 2 + 60) . "' ry='14' fill='#000' opacity='.09'/>"
             . "<path d='M$x $front_y L" . ($x + 80) . " $top L" . ($x + $w + 80) . " $top L" . ($x + $w) . " $front_y Z' fill='$top_fill' stroke='#e3ddd1'/>"
             . "<clipPath id='$id'><path d='M$x $front_y L" . ($x + 80) . " $top L" . ($x + $w + 80) . " $top L" . ($x + $w) . " $front_y Z'/></clipPath><g clip-path='url(#$id)'>$quilt</g>"
             . "<rect x='$x' y='$front_y' width='$w' height='$h' fill='#ece6da'/>"
             . "<path d='M" . ($x + $w) . " $front_y L" . ($x + $w + 80) . " $top L" . ($x + $w + 80) . ' ' . ($top + $h) . ' L' . ($x + $w) . ' ' . ($front_y + $h) . " Z' fill='#ddd5c6'/>"
             . $band
             . ($type === 'topper' ? '' : "<path d='M" . ($x + $w) . ' ' . ($front_y + $h - 10) . ' L' . ($x + $w + 80) . ' ' . ($top + $h - 10) . ' L' . ($x + $w + 80) . ' ' . ($top + $h) . ' L' . ($x + $w) . ' ' . ($front_y + $h) . " Z' fill='$brand' opacity='.8'/>");
    }
    return "<svg class='art $class' viewBox='0 0 400 280' role='img' aria-label='" . e($p['name']) . "'>$svg</svg>";
}
