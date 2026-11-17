<?php
// Request-scoped access to the API with the shopper's cart and login tokens attached.

final class Store
{
    private static ?array $info = null;
    private static ?array $cart = null;

    public static function cookie(string $name): ?string
    {
        return $_COOKIE[Api::config()['storefront']['cookie_prefix'] . $name] ?? null;
    }

    public static function setCookie(string $name, ?string $value, int $days = 30): void
    {
        $n = Api::config()['storefront']['cookie_prefix'] . $name;
        // Cookies must go out before any HTML. View warms the cart and login state before the layout
        // renders, so this only trips if a template sets one late; then skip it rather than warn.
        if (!headers_sent()) setcookie($n, (string) $value, ['expires' => $value === null ? time() - 3600 : time() + $days * 86400, 'path' => base_path() . '/',
                                         'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
        if ($value === null) unset($_COOKIE[$n]); else $_COOKIE[$n] = $value;
    }

    public static function api(): Api
    {
        return (new Api())
            ->withHeader('X-Cart-Token', self::cookie('cart'))
            ->withHeader('Authorization', ($t = self::cookie('auth')) ? "Bearer $t" : null);
    }

    public static function info(): array
    {
        return self::$info ??= self::api()->get('/store');
    }

    /** Cart for the header count and cart pages. Never creates one. */
    public static function cart(bool $fresh = false): array
    {
        if ($fresh || self::$cart === null) {
            self::$cart = self::cookie('cart') ? self::api()->get('/cart') : ['token' => null, 'items' => [], 'count' => 0, 'subtotal' => 0];
            if (self::cookie('cart') && !self::$cart['token']) self::setCookie('cart', null);
        }
        return self::$cart;
    }

    /** Makes sure a cart exists (cookie set) before writing to it. */
    public static function ensureCart(): void
    {
        if (!self::cookie('cart') || !self::cart()['token']) {
            $c = (new Api())->post('/cart');
            self::setCookie('cart', $c['token']);
        }
    }

    public static function contact(string $type): ?string
    {
        foreach (self::info()['contacts'] as $c) if ($c['type'] === $type) return $c['value'];
        return null;
    }

    public static function contacts(string $type): array
    {
        return array_values(array_filter(self::info()['contacts'], fn($c) => $c['type'] === $type));
    }

    public static function whatsapp(string $text = ''): string
    {
        return 'https://wa.me/' . self::contact('whatsapp') . ($text !== '' ? '?text=' . rawurlencode($text) : '');
    }

    /** Active delivery zones grouped by city, e.g. ['Abuja' => [...], 'Lagos' => [...]], in CMS order. */
    public static function zoneCities(): array
    {
        $out = [];
        foreach (self::info()['zones'] as $z) $out[$z['city'] ?? 'Abuja'][] = $z;
        return $out;
    }

    public static function location(): array
    {
        return self::info()['locations'][0];
    }

    public static function me(): ?array
    {
        static $me = false;
        if ($me === false) {
            $me = null;
            if (self::cookie('auth')) {
                $r = self::api()->request('GET', '/me');
                if ($r['status'] === 200) $me = $r['data']; else self::setCookie('auth', null);
            }
        }
        return $me;
    }

    public static function hoursText(?array $h): string
    {
        if (!$h) return '';
        $days = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
        $groups = [];
        foreach ($days as $k => $label) {
            $v = $h[$k] ?? null;
            $key = $v ? implode('–', array_map(fn($t) => date('ga', strtotime($t)), $v)) : 'Closed';
            if ($groups && end($groups)['v'] === $key) $groups[array_key_last($groups)]['to'] = $label;
            else $groups[] = ['from' => $label, 'to' => $label, 'v' => $key];
        }
        return implode(' · ', array_map(fn($g) => ($g['from'] === $g['to'] ? $g['from'] : "{$g['from']}–{$g['to']}") . ' ' . $g['v'], $groups));
    }

    public static function openNow(?array $h): bool
    {
        $k = strtolower(date('D'));
        $v = $h[$k] ?? null;
        return $v && date('H:i') >= $v[0] && date('H:i') < $v[1];
    }
}
