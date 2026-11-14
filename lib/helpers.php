<?php
// Small helpers shared by the storefront and CMS templates.

date_default_timezone_set('Africa/Lagos');

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function naira(int|float|null $n): string
{
    return '₦' . number_format((float) ($n ?? 0));
}

function phone_display(string $p): string
{
    $d = preg_replace('/\D/', '', $p);
    if (str_starts_with($d, '234')) $d = '0' . substr($d, 3);
    return strlen($d) === 11 ? substr($d, 0, 4) . ' ' . substr($d, 4, 3) . ' ' . substr($d, 7) : $p;
}

function phone_tel(string $p): string
{
    $d = preg_replace('/\D/', '', $p);
    return '+234' . ltrim(str_starts_with($d, '234') ? substr($d, 3) : $d, '0');
}

function when(?string $dt, string $fmt = 'j M Y, g:ia'): string
{
    return $dt ? date($fmt, strtotime($dt)) : '';
}

function ago(?string $dt): string
{
    if (!$dt) return '';
    $s = time() - strtotime($dt);
    return match (true) {
        $s < 60 => 'just now',
        $s < 3600 => floor($s / 60) . ' min ago',
        $s < 86400 => floor($s / 3600) . ' h ago',
        $s < 172800 => 'yesterday',
        $s < 604800 => floor($s / 86400) . ' days ago',
        default => date('j M', strtotime($dt)),
    };
}

function status_label(string $s): string
{
    return [
        'placed' => 'Placed', 'confirmed' => 'Confirmed', 'packed' => 'Packed', 'out_for_delivery' => 'Out for delivery',
        'delivered' => 'Delivered', 'ready_for_pickup' => 'Ready for pickup', 'collected' => 'Collected', 'cancelled' => 'Cancelled',
        'unpaid' => 'Unpaid', 'pending' => 'Pending', 'part_paid' => 'Part paid', 'paid' => 'Paid', 'refunded' => 'Refunded',
        'pod' => 'Pay on delivery', 'paystack' => 'Paystack', 'transfer' => 'Bank transfer', 'whatsapp' => 'WhatsApp',
        'web' => 'Website', 'walk_in' => 'Walk-in', 'phone' => 'Phone', 'delivery' => 'Delivery', 'pickup' => 'Pickup',
    ][$s] ?? ucfirst(str_replace('_', ' ', $s));
}

/** Minimal, safe Markdown for CMS content: ## headings, **bold**, [links](/x), - lists, paragraphs. */
function md(string $text): string
{
    $out = [];
    $text = str_replace(["\r\n", "\r"], "\n", $text); // form posts use CRLF
    foreach (preg_split('/\n{2,}/', trim($text)) as $block) {
        $b = e($block);
        $b = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $b);
        $b = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', fn($m) => '<a href="' . (str_starts_with($m[2], '/') ? url($m[2]) : $m[2]) . '">' . $m[1] . '</a>', $b);
        if (str_starts_with($b, '## ')) $out[] = '<h2>' . substr($b, 3) . '</h2>';
        elseif (str_starts_with($b, '### ')) $out[] = '<h3>' . substr($b, 4) . '</h3>';
        elseif (preg_match('/^- /', $b)) $out[] = '<ul>' . implode('', array_map(fn($l) => '<li>' . preg_replace('/^- /', '', $l) . '</li>', explode("\n", $b))) . '</ul>';
        else $out[] = '<p>' . nl2br($b) . '</p>';
    }
    return implode("\n", $out);
}

/** Base path of the current app (works at / with php -S, or in a subfolder under Apache). */
function base_path(): string
{
    static $b = null;
    return $b ??= rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
}

function url(string $path = '/', array $query = []): string
{
    $q = array_filter($query, fn($v) => $v !== null && $v !== '' && $v !== []);
    return base_path() . '/' . ltrim($path, '/') . ($q ? '?' . http_build_query($q) : '');
}

function asset(string $path): string
{
    $file = dirname($_SERVER['SCRIPT_FILENAME']) . '/assets/' . $path;
    return url('assets/' . $path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function redirect(string $to, int $code = 303): never
{
    header('Location: ' . (preg_match('#^https?://#', $to) ? $to : url($to)), true, $code);
    exit;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Go back, refresh the page and try again.');
    }
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if ($msg !== null) { $_SESSION['flash'] = ['msg' => $msg, 'type' => $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
