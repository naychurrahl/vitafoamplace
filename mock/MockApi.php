<?php
/**
 * MockApi — stands in for the backend while the storefront and CMS are demoed.
 *
 * Same paths, methods and response shapes as backend/doc/openapi.yaml, but it reads
 * and writes mock/data.json (copied to mock/runtime/db.json on first use, with dates
 * moved forward so "today" always has orders). Delete the runtime file to reset.
 *
 * handle() returns [httpStatus, payload]. Errors are {"error": "...", "fields"?: {...}}.
 */
final class MockApi
{
    private static ?MockApi $instance = null;

    private array $db = [];
    private array $headers = [];
    private array $query = [];
    private array $body = [];
    private bool $dirty = false;

    /** Admin resources that are plain lists: path segment => [db key, searchable fields] */
    private const COLLECTIONS = [
        'zones'           => ['zones', ['name']],
        'locations'       => ['locations', ['name', 'street']],
        'payment-methods' => ['payment_methods', ['label', 'type']],
        'contacts'        => ['store.contacts', ['value', 'label', 'type']],
        'domains'         => ['store.domains', ['domain']],
        'brands'          => ['brands', ['name']],
        'categories'      => ['categories', ['name']],
        'posts'           => ['posts', ['title', 'slug']],
        'post-categories' => ['post_categories', ['name']],
        'post-tags'       => ['post_tags', ['name']],
        'comments'        => ['post_comments', ['name', 'body']],
        'pages'           => ['pages', ['title', 'slug']],
        'faqs'            => ['faqs', ['question', 'group_name']],
        'guide-pages'     => ['guide_pages', ['title', 'slug']],
        'banners'         => ['banners', ['title', 'placement']],
        'reviews'         => ['reviews', ['name', 'title', 'body']],
        'messages'        => ['contact_messages', ['name', 'subject', 'message', 'phone']],
        'subscribers'     => ['subscribers', ['email', 'phone']],
        'coupons'         => ['coupons', ['code']],
        'staff'           => ['staff', ['first_name', 'last_name', 'email']],
        'roles'           => ['roles', ['name']],
        'redirects'       => ['redirects', ['from_path', 'to_path']],
        'outbox'          => ['outbox', ['recipient', 'template']],
        'audit'           => ['audit', ['entity', 'action']],
    ];

    private const ORDER_FLOW = [
        'placed'           => ['confirmed', 'cancelled'],
        'confirmed'        => ['packed', 'ready_for_pickup', 'cancelled'],
        'packed'           => ['out_for_delivery', 'cancelled'],
        'out_for_delivery' => ['delivered', 'cancelled'],
        'ready_for_pickup' => ['collected', 'cancelled'],
        'delivered'        => [],
        'collected'        => [],
        'cancelled'        => [],
    ];

    public const IMPORT_COLUMNS = [
        'product_name', 'product_slug', 'brand', 'category', 'type', 'firmness', 'warranty_years', 'description',
        'sku', 'size_label', 'width_ft', 'length_ft', 'thickness_in', 'max_load_kg', 'price', 'compare_at_price', 'stock', 'image_urls',
    ];

    public static function instance(array $config): self
    {
        return self::$instance ??= new self($config);
    }

    private function __construct(private array $config)
    {
        date_default_timezone_set('Africa/Lagos');
        // Paths in the config may be relative to the web root (this folder's parent), so the web root
        // can have any name (public/, public_html/...).
        foreach (['seed', 'state', 'uploads_dir'] as $k) {
            if (isset($this->config[$k]) && !preg_match('#^([a-z]:)?[\\\\/]#i', $this->config[$k])) {
                $this->config[$k] = dirname(__DIR__) . '/' . $this->config[$k];
            }
        }
        $state = $this->config['state'];
        if (is_file($state)) {
            $this->db = json_decode((string) file_get_contents($state), true) ?: [];
        }
        if (!$this->db) {
            $this->reseed();
        }
    }

    public function __destruct()
    {
        if ($this->dirty) {
            $this->save();
        }
    }

    // ------------------------------------------------------------------ plumbing

    private function reseed(): void
    {
        $seed = json_decode((string) file_get_contents($this->config['seed']), true);
        $shift = (int) round((strtotime(date('Y-m-d')) - strtotime(substr($seed['_generated_at'], 0, 10))) / 86400);
        if ($shift !== 0) {
            array_walk_recursive($seed, function (&$v, $k) use ($shift) {
                if (is_string($v) && $k !== '_generated_at' && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $v)) {
                    $fmt = strlen($v) > 10 ? 'Y-m-d H:i:s' : 'Y-m-d';
                    $v = date($fmt, strtotime("$v $shift days"));
                }
            });
        }
        $seed['sessions'] = [];
        $this->db = $seed;
        $this->dirty = true;
        $this->save();
    }

    private function save(): void
    {
        $file = $this->config['state'];
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, json_encode($this->db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        $this->dirty = false;
    }

    /** @return array{0:int,1:mixed} */
    public function handle(string $method, string $path, array $query = [], array $body = [], array $headers = []): array
    {
        $this->query = $query;
        $this->body = $body;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?? '', '/');
        $method = strtoupper($method);

        try {
            foreach ($this->routes() as [$m, $pattern, $handler]) {
                if ($m !== $method) continue;
                $rx = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
                if (!preg_match($rx, $path, $mm)) continue;
                $params = array_map('urldecode', array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY));
                if (str_starts_with($pattern, '/admin/') && $pattern !== '/admin/auth/login' && !$this->staff()) {
                    return [401, ['error' => 'Unauthorized']];
                }
                if (str_starts_with($pattern, '/me') && !$this->session('customer')) {
                    return [401, ['error' => 'Unauthorized']];
                }
                $res = $this->$handler(...array_values($params));
                if ($method !== 'GET') $this->dirty = true;
                return is_array($res) && isset($res['__status']) ? [$res['__status'], $res['body']] : [200, $res];
            }
            // generic admin collections
            if (preg_match('#^/admin/([a-z-]+)(?:/(\d+))?$#', $path, $mm) && isset(self::COLLECTIONS[$mm[1]])) {
                if (!$this->staff()) return [401, ['error' => 'Unauthorized']];
                $res = $this->collection($method, $mm[1], isset($mm[2]) ? (int) $mm[2] : null);
                if ($method !== 'GET') $this->dirty = true;
                return isset($res['__status']) ? [$res['__status'], $res['body']] : [$method === 'POST' ? 201 : 200, $res];
            }
            return [404, ['error' => "No mock for $method $path"]];
        } catch (MockHttpError $e) {
            return [$e->status, $e->payload];
        }
    }

    private function routes(): array
    {
        return [
            // public
            ['GET', '/store', 'getStore'],
            ['GET', '/categories', 'getCategories'],
            ['GET', '/brands', 'getBrands'],
            ['GET', '/brands/{slug}', 'getBrand'],
            ['GET', '/products', 'getProducts'],
            ['GET', '/products/{slug}', 'getProduct'],
            ['GET', '/products/{slug}/related', 'getRelated'],
            ['GET', '/products/{slug}/reviews', 'getReviews'],
            ['POST', '/products/{slug}/reviews', 'postReview'],
            ['GET', '/search/suggest', 'searchSuggest'],
            ['POST', '/guide/recommend', 'guideRecommend'],
            ['GET', '/guide-pages', 'getGuidePages'],
            ['GET', '/guide-pages/{slug}', 'getGuidePage'],
            ['GET', '/landing', 'getLanding'],
            ['GET', '/price-guide', 'getPriceGuide'],
            ['GET', '/feed/products', 'getProductFeed'],
            ['POST', '/delivery/quote', 'deliveryQuote'],
            ['POST', '/cart', 'createCart'],
            ['GET', '/cart', 'getCart'],
            ['DELETE', '/cart', 'clearCart'],
            ['POST', '/cart/items', 'addCartItem'],
            ['PUT', '/cart/items/{id}', 'updateCartItem'],
            ['DELETE', '/cart/items/{id}', 'removeCartItem'],
            ['POST', '/checkout/quote', 'checkoutQuote'],
            ['POST', '/orders', 'placeOrder'],
            ['GET', '/orders/{number}', 'getOrder'],
            ['POST', '/orders/{number}/transfer-receipt', 'transferReceipt'],
            ['POST', '/orders/{number}/unlock', 'unlockOrder'],
            ['POST', '/payments/paystack/init', 'paystackInit'],
            ['GET', '/payments/paystack/verify', 'paystackVerify'],
            ['GET', '/posts', 'getPosts'],
            ['GET', '/posts/{slug}', 'getPost'],
            ['GET', '/posts/{slug}/comments', 'getComments'],
            ['POST', '/posts/{slug}/comments', 'postComment'],
            ['GET', '/post-categories', 'getPostCategories'],
            ['GET', '/pages/{slug}', 'getPage'],
            ['GET', '/faqs', 'getFaqs'],
            ['GET', '/banners', 'getBanners'],
            ['POST', '/contact', 'postContact'],
            ['POST', '/newsletter', 'postNewsletter'],
            ['GET', '/sitemap', 'getSitemap'],
            ['GET', '/redirects/resolve', 'resolveRedirect'],
            // customer
            ['POST', '/auth/register', 'register'],
            ['POST', '/auth/login', 'login'],
            ['POST', '/auth/logout', 'logout'],
            ['GET', '/me', 'getMe'],
            ['PUT', '/me', 'putMe'],
            ['GET', '/me/orders', 'getMyOrders'],
            ['GET', '/me/orders/{number}', 'getMyOrder'],
            ['GET', '/me/addresses', 'getMyAddresses'],
            ['POST', '/me/password', 'setMyPassword'],
            ['POST', '/auth/otp/send', 'otpSend'],
            ['POST', '/auth/otp/verify', 'otpVerify'],
            // admin
            ['POST', '/admin/auth/login', 'adminLogin'],
            ['POST', '/admin/auth/logout', 'logout'],
            ['GET', '/admin/me', 'adminMe'],
            ['GET', '/admin/dashboard', 'adminDashboard'],
            ['GET', '/admin/stores', 'adminStores'],
            ['GET', '/admin/stores/{id}', 'adminStore'],
            ['PUT', '/admin/stores/{id}', 'adminUpdateStore'],
            ['GET', '/admin/products', 'adminProducts'],
            ['POST', '/admin/products', 'adminCreateProduct'],
            ['GET', '/admin/products/{id}', 'adminProduct'],
            ['PUT', '/admin/products/{id}', 'adminUpdateProduct'],
            ['DELETE', '/admin/products/{id}', 'adminArchiveProduct'],
            ['POST', '/admin/products/{id}/publish', 'adminPublishProduct'],
            ['POST', '/admin/products/{id}/media', 'adminUploadMedia'],
            ['GET', '/admin/inventory', 'adminInventory'],
            ['POST', '/admin/inventory/adjust', 'adminAdjustStock'],
            ['GET', '/admin/inventory/movements', 'adminMovements'],
            ['GET', '/admin/imports/template', 'adminImportTemplate'],
            ['POST', '/admin/imports', 'adminImportPreview'],
            ['GET', '/admin/imports/{id}', 'adminImport'],
            ['POST', '/admin/imports/{id}/apply', 'adminImportApply'],
            ['GET', '/admin/exports/catalogue', 'adminExport'],
            ['GET', '/admin/orders', 'adminOrders'],
            ['POST', '/admin/orders', 'adminCreateOrder'],
            ['GET', '/admin/orders/{id}', 'adminOrder'],
            ['POST', '/admin/orders/{id}/status', 'adminOrderStatus'],
            ['POST', '/admin/orders/{id}/payments', 'adminOrderPayment'],
            ['POST', '/admin/orders/{id}/notes', 'adminOrderNote'],
            ['GET', '/admin/customers', 'adminCustomers'],
            ['GET', '/admin/customers/{id}', 'adminCustomer'],
            ['PUT', '/admin/customers/{id}', 'adminUpdateCustomer'],
            ['GET', '/admin/reports/sales', 'adminSalesReport'],
            ['POST', '/admin/outbox/{id}/retry', 'adminRetryOutbox'],
            ['DELETE', '/admin/cache', 'adminClearCache'],
            ['POST', '/admin/demo/reset', 'adminReset'],
        ];
    }

    private function fail(int $status, string $error, array $fields = []): never
    {
        throw new MockHttpError($status, $fields ? ['error' => $error, 'fields' => $fields] : ['error' => $error]);
    }

    private static function status(int $code, mixed $body): array
    {
        return ['__status' => $code, 'body' => $body];
    }

    private function in(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    private function bearer(): ?string
    {
        $h = $this->headers['authorization'] ?? '';
        return str_starts_with($h, 'Bearer ') ? substr($h, 7) : null;
    }

    private function session(string $type): ?array
    {
        $t = $this->bearer();
        if (!$t) return null;
        foreach ($this->db['sessions'] ?? [] as $s) {
            if ($s['token'] === $t && $s['type'] === $type) return $s;
        }
        return null;
    }

    private function staff(): ?array
    {
        $s = $this->session('staff');
        return $s ? $this->find('staff', $s['user_id']) : null;
    }

    private function customer(): ?array
    {
        $s = $this->session('customer');
        return $s && $s['user_id'] ? $this->find('customers', $s['user_id']) : null;
    }

    private function &ref(string $key): array
    {
        if (str_contains($key, '.')) {
            [$a, $b] = explode('.', $key, 2);
            $this->db[$a][$b] ??= [];
            return $this->db[$a][$b];
        }
        $this->db[$key] ??= [];
        return $this->db[$key];
    }

    private function find(string $key, int|string|null $id, string $field = 'id'): ?array
    {
        foreach ($this->ref($key) as $row) {
            if ((string) ($row[$field] ?? '') === (string) $id) return $row;
        }
        return null;
    }

    private function nextId(string $key): int
    {
        return max(array_merge([0], array_column($this->ref($key), 'id'))) + 1;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function paginate(array $rows, int $perDefault = 24): array
    {
        $per = max(1, min(200, (int) ($this->query['per_page'] ?? $perDefault)));
        $page = max(1, (int) ($this->query['page'] ?? 1));
        return ['items' => array_values(array_slice($rows, ($page - 1) * $per, $per)), 'total' => count($rows), 'page' => $page, 'per_page' => $per,
                'pages' => (int) max(1, ceil(count($rows) / $per))];
    }

    private static function normPhone(string $p): string
    {
        $d = preg_replace('/\D/', '', $p);
        if (str_starts_with($d, '234')) $d = '0' . substr($d, 3);
        return $d;
    }

    /**
     * Admin search: case-insensitive text match, plus phone numbers by digits, so
     * "0804 533 1886", "+234 804 533 1886" and "533-1886" all find 08045331886.
     */
    private static function searchHit(string $q, string $text, array $phones = []): bool
    {
        $q = trim($q);
        if (str_contains(mb_strtolower($text), mb_strtolower($q))) return true;
        if (!$phones || !preg_match('/^[\d\s+\-().]+$/', $q) || strlen($d = preg_replace('/\D/', '', $q)) < 3) return false;
        if (strlen($d) > 3) $d = self::normPhone($d);
        foreach ($phones as $p) if ($p !== null && str_contains(self::normPhone((string) $p), $d)) return true;
        return false;
    }

    private static function validPhone(string $p): bool
    {
        return (bool) preg_match('/^0[789][01]\d{8}$/', self::normPhone($p));
    }

    // ------------------------------------------------------------------ views (derived values)

    private function brand(int $id): ?array { return $this->find('brands', $id); }
    private function category(int $id): ?array { return $this->find('categories', $id); }
    private function zone(?int $id): ?array { return $id ? $this->find('zones', $id) : null; }

    private function variantIndex(): array
    {
        static $idx = null;
        if ($idx === null || $this->dirty) {
            $idx = [];
            foreach ($this->db['products'] as $p) foreach ($p['variants'] as $v) $idx[$v['id']] = [$p, $v];
        }
        return $idx;
    }

    private function rating(int $productId): array
    {
        $r = array_filter($this->db['reviews'], fn($x) => $x['product_id'] === $productId && $x['status'] === 'approved');
        $n = count($r);
        return ['avg' => $n ? round(array_sum(array_column($r, 'rating')) / $n, 1) : null, 'count' => $n];
    }

    private function card(array $p): array
    {
        $vs = array_values(array_filter($p['variants'], fn($v) => $v['status'] === 'active'));
        $prices = array_column($vs, 'price') ?: [0];
        $compare = array_filter(array_column($vs, 'compare_at_price'));
        $b = $this->brand($p['brand_id']);
        $c = $this->category($p['category_id']);
        $loads = array_filter(array_column($vs, 'max_load_kg'));
        return [
            'id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['listing']['name_override'] ?: $p['name'],
            'brand' => ['name' => $b['name'], 'slug' => $b['slug'], 'color' => $b['color'] ?? null],
            'category' => ['name' => $c['name'], 'slug' => $c['slug']],
            'type' => $p['type'], 'firmness' => $p['firmness'], 'max_load_kg' => $loads ? max($loads) : null,
            'price_from' => min($prices), 'price_to' => max($prices),
            'compare_at_from' => $compare ? min($compare) : null, 'on_sale' => (bool) $compare,
            'in_stock' => array_sum(array_column($vs, 'stock')) > 0, 'sizes' => count($vs),
            'image' => $p['media'][0]['url'] ?? null, 'image_alt' => $p['media'][0]['alt'] ?? null,
            'featured' => $p['listing']['featured'], 'new_arrival' => $p['listing']['new_arrival'],
            'warranty_years' => $p['warranty_years'], 'good_for' => $p['good_for'],
            'rating' => $this->rating($p['id']),
        ];
    }

    private function detail(array $p): array
    {
        $out = $this->card($p);
        $out += [
            'description' => $p['listing']['description_override'] ?: $p['description'],
            'density' => $p['density'], 'materials' => $p['materials'], 'cover' => $p['cover'],
            'specs' => $p['specs'] ?: (object) [], 'media' => $p['media'],
            'variants' => array_values(array_map(fn($v) => [
                'id' => $v['id'], 'slug' => self::sizeSlug($v), 'sku' => $v['sku'], 'size_label' => $v['size_label'], 'width_ft' => $v['width_ft'],
                'length_ft' => $v['length_ft'], 'thickness_in' => $v['thickness_in'], 'max_load_kg' => $v['max_load_kg'],
                'price' => $v['price'], 'compare_at_price' => $v['compare_at_price'], 'stock' => $v['stock'],
                'in_stock' => $v['stock'] > 0,
            ], array_filter($p['variants'], fn($v) => $v['status'] === 'active'))),
            'seo' => $p['listing']['seo'] ?? null,
            'faqs' => $p['faqs'] ?? [],
            'pickup' => ['available' => true, 'location' => $this->db['locations'][0]['name']],
        ];
        return $out;
    }

    /** Readable size key for URLs: 6ft × 4.5ft × 8" → "6x4.5x8" (?size=6x4.5x8). Falls back to the variant id. */
    public static function sizeSlug(array $v): string
    {
        if (empty($v['width_ft']) || empty($v['length_ft'])) return (string) $v['id'];
        $n = fn($x) => rtrim(rtrim(number_format((float) $x, 1, '.', ''), '0'), '.');
        return $n($v['length_ft']) . 'x' . $n($v['width_ft']) . (!empty($v['thickness_in']) ? 'x' . $n($v['thickness_in']) : '');
    }

    private function listed(): array
    {
        return array_values(array_filter($this->db['products'], fn($p) => $p['status'] === 'active' && $p['listing']['listed']));
    }

    private function orderStatus(array $o): string
    {
        return end($o['events'])['status'] ?? 'placed';
    }

    private function orderTotals(array $o): array
    {
        $sub = array_sum(array_map(fn($i) => $i['unit_price'] * $i['qty'], $o['items']));
        return [$sub, $sub + $o['delivery_fee'] - $o['discount']];
    }

    private function paymentStatus(array $o, float $total): array
    {
        $paid = array_sum(array_map(fn($p) => $p['status'] === 'success' ? $p['amount'] : 0, $o['payments']));
        $pending = array_filter($o['payments'], fn($p) => $p['status'] === 'pending');
        $st = $paid >= $total && $total > 0 ? 'paid' : ($paid > 0 ? 'part_paid' : ($pending ? 'pending' : 'unpaid'));
        return [$st, $paid];
    }

    private function orderView(array $o, bool $admin = false): array
    {
        $idx = $this->variantIndex();
        [$sub, $total] = $this->orderTotals($o);
        [$pst, $paid] = $this->paymentStatus($o, $total);
        $items = array_map(function ($i) use ($idx) {
            [$p, $v] = $idx[$i['variant_id']] ?? [null, null];
            return ['variant_id' => $i['variant_id'], 'product_id' => $p['id'] ?? null, 'slug' => $p['slug'] ?? null,
                    'product_name' => $p['name'] ?? 'Unknown', 'brand_name' => $p ? $this->brand($p['brand_id'])['name'] : null,
                    'size_label' => $v['size_label'] ?? null, 'sku' => $v['sku'] ?? null, 'unit_price' => $i['unit_price'],
                    'qty' => $i['qty'], 'line_total' => $i['unit_price'] * $i['qty']];
        }, $o['items']);
        $loc = $o['location'];
        if ($loc['kind'] === 'store') {
            $l = $this->find('locations', $loc['location_id']);
            $location = ['kind' => 'store', 'name' => $l['name'], 'street' => $l['street'], 'landmark' => $l['landmark'], 'zone_name' => null];
        } else {
            $location = ['kind' => 'delivery', 'name' => null, 'street' => $loc['street'], 'landmark' => $loc['landmark'],
                         'zone_id' => $loc['zone_id'], 'zone_name' => $this->zone($loc['zone_id'])['name'] ?? null, 'city' => $this->zone($loc['zone_id'])['city'] ?? null];
        }
        $pm = $this->find('payment_methods', $o['payment_method'], 'type');
        $events = array_values(array_filter($o['events'], fn($e) => $admin || $e['is_public']));
        if ($admin) {
            $events = array_map(fn($e) => $e + ['staff_name' => $e['staff_id'] ? $this->staffName($e['staff_id']) : null], $events);
        }
        $view = [
            'id' => $o['id'], 'number' => $o['number'], 'created_at' => $o['created_at'], 'channel' => $o['channel'],
            'status' => $this->orderStatus($o), 'payment_status' => $pst, 'paid' => $paid,
            'contact' => $o['contact'], 'fulfilment' => $o['fulfilment'], 'location' => $location,
            'note' => $o['note'], 'preferred_date' => $o['preferred_date'], 'time_slot' => $o['time_slot'],
            'items' => $items, 'subtotal' => $sub, 'delivery_fee' => $o['delivery_fee'], 'discount' => $o['discount'],
            'coupon_code' => $o['coupon_code'], 'total' => $total,
            'payment_method' => ['type' => $o['payment_method'], 'label' => $pm['label'] ?? $o['payment_method'], 'instructions' => $pm['instructions'] ?? null],
            'events' => $events,
        ];
        if ($admin) {
            $view['payments'] = $o['payments'];
            $view['internal_notes'] = array_map(fn($n) => $n + ['staff_name' => $this->staffName($n['staff_id'])], $o['internal_notes']);
            $view['customer_id'] = $o['customer_id'];
            $view['access_token'] = $o['access_token'];
            $view['next_statuses'] = array_values(array_filter(self::ORDER_FLOW[$view['status']],
                fn($s) => $o['fulfilment'] === 'pickup' ? !in_array($s, ['packed', 'out_for_delivery', 'delivered']) : !in_array($s, ['ready_for_pickup', 'collected'])));
        }
        return $view;
    }

    private function staffName(?int $id): ?string
    {
        $s = $id ? $this->find('staff', $id) : null;
        return $s ? $s['first_name'] . ' ' . $s['last_name'] : null;
    }

    // ------------------------------------------------------------------ public: store & catalogue

    private function getStore(): array
    {
        $s = $this->db['store'];
        unset($s['domains']);
        $s['locations'] = array_values(array_filter($this->db['locations'], fn($l) => $l['status'] === 'active'));
        $s['zones'] = array_values(array_filter($this->db['zones'], fn($z) => $z['status'] === 'active'));
        $s['payment_methods'] = array_values(array_filter($this->db['payment_methods'], fn($m) => $m['enabled']));
        usort($s['contacts'], fn($a, $b) => $a['sort'] <=> $b['sort']);
        $s['now'] = $this->now();
        $s['same_day_open'] = date('H:i') < $s['same_day_cutoff'];
        // The mock is always the demo; the storefront and CMS take their demo badge, hints and noindex from this.
        $s['demo'] = true;
        $s['demo_password'] = $this->config['demo_password'];
        return $s;
    }

    private function getCategories(): array
    {
        $counts = array_count_values(array_column($this->listed(), 'category_id'));
        $out = [];
        foreach ($this->db['categories'] as $c) {
            if (!empty($counts[$c['id']])) $out[] = $c + ['product_count' => $counts[$c['id']]];
        }
        usort($out, fn($a, $b) => $a['sort'] <=> $b['sort']);
        return $out;
    }

    private function getBrands(): array
    {
        $counts = array_count_values(array_column($this->listed(), 'brand_id'));
        $out = [];
        foreach ($this->db['brands'] as $b) {
            if (!empty($counts[$b['id']])) $out[] = $b + ['product_count' => $counts[$b['id']]];
        }
        return $out;
    }

    private function getBrand(string $slug): array
    {
        foreach ($this->getBrands() as $b) if ($b['slug'] === $slug) return $b;
        $this->fail(404, 'Brand not found');
    }

    private function getProducts(): array
    {
        $q = $this->query;
        $list = $this->listed();
        $csv = fn($k) => isset($q[$k]) && $q[$k] !== '' ? explode(',', (string) $q[$k]) : [];

        if ($ids = $csv('ids')) {
            $list = array_filter($list, fn($p) => in_array((string) $p['id'], $ids, true));
        }
        if (!empty($q['category'])) {
            $c = $this->find('categories', $q['category'], 'slug');
            $list = array_filter($list, fn($p) => $c && $p['category_id'] === $c['id']);
        }
        $scoped = $list; // facets are counted on this set
        if ($b = $csv('brand')) {
            $ids = array_column(array_filter($this->db['brands'], fn($x) => in_array($x['slug'], $b, true)), 'id');
            $list = array_filter($list, fn($p) => in_array($p['brand_id'], $ids, true));
        }
        if ($t = $csv('type')) $list = array_filter($list, fn($p) => in_array($p['type'], $t, true));
        if ($f = $csv('firmness')) $list = array_filter($list, fn($p) => in_array((string) $p['firmness'], $f, true));
        if (!empty($q['weight'])) {
            $w = (int) $q['weight'];
            $list = array_filter($list, fn($p) => (bool) array_filter($p['variants'], fn($v) => ($v['max_load_kg'] ?? 0) >= $w));
        }
        if (!empty($q['size'])) {
            [$l, $wd] = array_pad(explode('x', strtolower((string) $q['size'])), 2, '');
            $list = array_filter($list, fn($p) => (bool) array_filter($p['variants'], fn($v) => (string) $v['width_ft'] === $wd && (string) $v['length_ft'] === $l));
        }
        if (!empty($q['q'])) {
            $needle = mb_strtolower(trim((string) $q['q']));
            $list = array_filter($list, function ($p) use ($needle) {
                $hay = mb_strtolower($p['name'] . ' ' . $this->brand($p['brand_id'])['name'] . ' ' . $p['type'] . ' ' . implode(' ', $p['good_for']));
                foreach (preg_split('/\s+/', $needle) as $w) if (!str_contains($hay, $w)) return false;
                return true;
            });
        }
        if (!empty($q['featured'])) $list = array_filter($list, fn($p) => $p['listing']['featured']);
        if (!empty($q['new'])) $list = array_filter($list, fn($p) => $p['listing']['new_arrival']);

        $cards = array_map(fn($p) => $this->card($p), $list);
        if (!empty($q['price_min'])) $cards = array_filter($cards, fn($c) => $c['price_to'] >= (int) $q['price_min']);
        if (!empty($q['price_max'])) $cards = array_filter($cards, fn($c) => $c['price_from'] <= (int) $q['price_max']);
        if (!empty($q['in_stock'])) $cards = array_filter($cards, fn($c) => $c['in_stock']);
        if (!empty($q['on_sale'])) $cards = array_filter($cards, fn($c) => $c['on_sale']);

        $cards = array_values($cards);
        $sort = $q['sort'] ?? 'recommended';
        $views = array_column(array_map(fn($p) => ['id' => $p['id'], 'v' => $p['listing']['view_count']], $this->db['products']), 'v', 'id');
        usort($cards, match ($sort) {
            'price_asc'  => fn($a, $b) => $a['price_from'] <=> $b['price_from'],
            'price_desc' => fn($a, $b) => $b['price_from'] <=> $a['price_from'],
            'name'       => fn($a, $b) => strcmp($a['name'], $b['name']),
            'newest'     => fn($a, $b) => $b['id'] <=> $a['id'],
            'popular'    => fn($a, $b) => $views[$b['id']] <=> $views[$a['id']],
            default      => fn($a, $b) => [$b['featured'], $b['in_stock'], $views[$b['id']]] <=> [$a['featured'], $a['in_stock'], $views[$a['id']]],
        });

        $res = $this->paginate($cards, 24);
        $res['facets'] = $this->facets($scoped);
        return $res;
    }

    private function facets(array $products): array
    {
        $count = fn(callable $key) => array_count_values(array_filter(array_map($key, $products), fn($x) => $x !== null && $x !== ''));
        $brands = $count(fn($p) => $p['brand_id']);
        $cats = $count(fn($p) => $p['category_id']);
        $sizes = [];
        foreach ($products as $p) {
            foreach (array_unique(array_map(fn($v) => $v['width_ft'] ? $v['length_ft'] . 'x' . $v['width_ft'] : null, $p['variants'])) as $s) {
                if ($s) $sizes[$s] = ($sizes[$s] ?? 0) + 1;
            }
        }
        uksort($sizes, fn($a, $b) => (float) explode('x', $a)[1] <=> (float) explode('x', $b)[1]);
        $prices = [];
        foreach ($products as $p) foreach ($p['variants'] as $v) $prices[] = $v['price'];
        return [
            'categories' => array_values(array_map(fn($id, $n) => ['slug' => $this->category($id)['slug'], 'name' => $this->category($id)['name'], 'count' => $n], array_keys($cats), $cats)),
            'brands' => array_values(array_map(fn($id, $n) => ['slug' => $this->brand($id)['slug'], 'name' => $this->brand($id)['name'], 'count' => $n], array_keys($brands), $brands)),
            'types' => array_map(fn($k, $n) => ['value' => $k, 'count' => $n], array_keys($t = $count(fn($p) => $p['category_id'] === 1 ? $p['type'] : null)), $t),
            'firmness' => array_map(fn($k, $n) => ['value' => $k, 'count' => $n], array_keys($f = $count(fn($p) => $p['firmness'])), $f),
            'sizes' => array_map(fn($k, $n) => ['value' => $k, 'label' => str_replace('x', 'ft × ', $k) . 'ft', 'count' => $n], array_keys($sizes), $sizes),
            'price' => ['min' => $prices ? min($prices) : 0, 'max' => $prices ? max($prices) : 0],
        ];
    }

    private function productBySlug(string $slug): array
    {
        foreach ($this->listed() as $p) if ($p['slug'] === $slug) return $p;
        $this->fail(404, 'Product not found');
    }

    private function getProduct(string $slug): array
    {
        $p = $this->productBySlug($slug);
        foreach ($this->db['products'] as &$row) if ($row['id'] === $p['id']) $row['listing']['view_count']++;
        unset($row);
        $this->dirty = true;
        return $this->detail($p);
    }

    private function getRelated(string $slug): array
    {
        $p = $this->productBySlug($slug);
        $same = array_filter($this->listed(), fn($x) => $x['id'] !== $p['id'] && $x['category_id'] === $p['category_id']);
        usort($same, fn($a, $b) => [abs(($a['firmness'] ?? 0) - ($p['firmness'] ?? 0)), $a['brand_id'] === $p['brand_id'] ? 0 : 1] <=> [abs(($b['firmness'] ?? 0) - ($p['firmness'] ?? 0)), $b['brand_id'] === $p['brand_id'] ? 0 : 1]);
        $out = array_map(fn($x) => $this->card($x), array_slice($same, 0, $p['category_id'] === 1 ? 3 : 4));
        if ($p['category_id'] === 1) { // cross-sell the most popular pillow
            $pillows = array_values(array_filter($this->listed(), fn($y) => $y['category_id'] === 2));
            usort($pillows, fn($a, $b) => $b['listing']['view_count'] <=> $a['listing']['view_count']);
            if ($pillows) $out[] = $this->card($pillows[0]);
        }
        return $out;
    }

    private function getReviews(string $slug): array
    {
        $p = $this->productBySlug($slug);
        $rows = array_values(array_filter($this->db['reviews'], fn($r) => $r['product_id'] === $p['id'] && $r['status'] === 'approved'));
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        $breakdown = array_fill_keys([5, 4, 3, 2, 1], 0);
        foreach ($rows as $r) $breakdown[$r['rating']]++;
        $res = $this->paginate($rows, 10);
        $res['summary'] = $this->rating($p['id']) + ['breakdown' => $breakdown];
        return $res;
    }

    private function postReview(string $slug): array
    {
        $p = $this->productBySlug($slug);
        $rating = (int) $this->in('rating');
        $errors = [];
        if ($rating < 1 || $rating > 5) $errors['rating'] = 'Choose 1 to 5 stars';
        if (trim((string) $this->in('name')) === '') $errors['name'] = 'Enter your name';
        if (mb_strlen(trim((string) $this->in('body'))) < 10) $errors['body'] = 'Write at least 10 characters';
        if ($errors) $this->fail(422, 'Check the highlighted fields', $errors);
        $r = ['id' => $this->nextId('reviews'), 'product_id' => $p['id'], 'name' => trim($this->in('name')), 'rating' => $rating,
              'title' => trim((string) $this->in('title')) ?: null, 'body' => trim($this->in('body')), 'verified' => false,
              'status' => 'pending', 'created_at' => $this->now()];
        $this->db['reviews'][] = $r;
        return ['id' => $r['id'], 'status' => 'pending', 'message' => 'Thanks! Your review will appear once it has been checked.'];
    }

    private function searchSuggest(): array
    {
        $q = mb_strtolower(trim((string) ($this->query['q'] ?? '')));
        if (mb_strlen($q) < 2) return ['products' => [], 'categories' => [], 'brands' => []];
        $products = array_filter($this->listed(), fn($p) => str_contains(mb_strtolower($p['name'] . ' ' . $p['type']), $q));
        return [
            'products' => array_map(fn($p) => ['slug' => $p['slug'], 'name' => $p['name'], 'price_from' => $this->card($p)['price_from'], 'brand' => $this->brand($p['brand_id'])['name']], array_slice(array_values($products), 0, 6)),
            'categories' => array_values(array_map(fn($c) => ['slug' => $c['slug'], 'name' => $c['name']], array_filter($this->db['categories'], fn($c) => str_contains(mb_strtolower($c['name']), $q)))),
            'brands' => array_values(array_map(fn($b) => ['slug' => $b['slug'], 'name' => $b['name']], array_filter($this->db['brands'], fn($b) => str_contains(mb_strtolower($b['name']), $q)))),
        ];
    }

    // ------------------------------------------------------------------ buyer's guide

    private function guideRecommend(): array
    {
        $w = (int) $this->in('weight_kg', 0);
        $pos = (string) $this->in('position', 'mixed');
        $pain = filter_var($this->in('back_pain', false), FILTER_VALIDATE_BOOLEAN);
        $shared = filter_var($this->in('shared', false), FILTER_VALIDATE_BOOLEAN);
        $size = (string) $this->in('size', '');
        $budget = (int) $this->in('budget', 0);

        $ideal = match (true) { $pain || $w >= 100 => [5, 4], $pos === 'side' => [3, 4], $pos === 'back' || $pos === 'stomach' => [4, 5], default => [3, 4] };
        $results = [];
        foreach ($this->listed() as $p) {
            if ($p['category_id'] !== 1) continue;
            $fit = array_filter($p['variants'], function ($v) use ($w, $size, $budget) {
                if ($v['status'] !== 'active') return false;
                if ($w && ($v['max_load_kg'] ?? 0) < $w) return false;
                if ($size && ($v['length_ft'] . 'x' . $v['width_ft']) !== $size) return false;
                if ($budget && $v['price'] > $budget) return false;
                return true;
            });
            if (!$fit) continue;
            usort($fit, fn($a, $b) => [$b['stock'] > 0, -$a['price']] <=> [$a['stock'] > 0, -$b['price']]);
            $v = $fit[0];
            $score = 50;
            $reasons = [];
            if ($w) { $reasons[] = "Rated for {$v['max_load_kg']}kg, above your {$w}kg"; $score += min(15, ($v['max_load_kg'] - $w) / 2); }
            if (in_array($p['firmness'], $ideal, true)) { $score += $p['firmness'] === $ideal[0] ? 25 : 18; $reasons[] = "Firmness {$p['firmness']}/5 suits " . ($pain ? 'back pain' : match ($pos) { 'side' => 'side sleepers', 'back' => 'back sleepers', 'stomach' => 'stomach sleepers', default => 'most sleepers' }); }
            if ($pain && in_array($p['type'], ['orthopedic', 'semi-orthopedic'], true)) { $score += 12; $reasons[] = ucfirst($p['type']) . ' support for your spine'; }
            if ($shared && in_array($p['type'], ['hybrid', 'memory', 'spring'], true)) { $score += 10; $reasons[] = 'Limits motion transfer when sharing'; }
            if ($shared && $v['thickness_in'] >= 8) { $score += 4; }
            if (in_array('heavy-sleeper', $p['good_for'], true) && $w >= 100) $score += 6;
            if ($budget) { $reasons[] = 'Within your budget'; $score += 5 * (1 - $v['price'] / $budget); }
            if ($v['stock'] > 0) $score += 5; else $reasons[] = 'Currently out of stock in this size';
            $results[] = ['score' => (int) round(min(99, $score)), 'reasons' => $reasons, 'product' => $this->card($p),
                          'variant' => ['id' => $v['id'], 'size_label' => $v['size_label'], 'price' => $v['price'], 'max_load_kg' => $v['max_load_kg'], 'in_stock' => $v['stock'] > 0]];
        }
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
        return [
            'understood' => ['weight_kg' => $w ?: null, 'position' => $pos, 'back_pain' => $pain, 'shared' => $shared, 'size' => $size ?: null, 'budget' => $budget ?: null,
                             'ideal_firmness' => $ideal],
            'items' => array_slice($results, 0, 6),
            'total' => count($results),
        ];
    }

    private function getGuidePages(): array
    {
        $kind = $this->query['kind'] ?? null;
        return array_values(array_map(fn($g) => ['slug' => $g['slug'], 'path' => $g['path'] ?? "/buyers-guide/{$g['slug']}", 'kind' => $g['kind'] ?? 'guide', 'title' => $g['title'], 'intro' => $g['intro'],
                                          'category' => array_key_exists('category', $g['rules']) ? $g['rules']['category'] : 'mattresses'],
            array_filter($this->db['guide_pages'], fn($g) => $g['status'] === 'published' && (!$kind || ($g['kind'] ?? 'guide') === $kind))));
    }

    /** Landing page (buyer's guide or product-type page) with the products its rules match. */
    private function landingView(array $g): array
    {
        $r = $g['rules'] + ['category' => 'mattresses'];
        $cat = $this->find('categories', $r['category'], 'slug');
        $items = array_filter($this->listed(), function ($p) use ($r, $cat) {
            if ($cat && $p['category_id'] !== $cat['id']) return false;
            if (!empty($r['brand']) && $this->brand($p['brand_id'])['slug'] !== $r['brand']) return false;
            if (!empty($r['types']) && !in_array($p['type'], $r['types'], true)) return false;
            if (!empty($r['firmness']) && !in_array($p['firmness'], $r['firmness'], true)) return false;
            return (bool) array_filter($p['variants'], fn($v) => $v['status'] === 'active'
                && (empty($r['min_load_kg']) || ($v['max_load_kg'] ?? 0) >= $r['min_load_kg'])
                && (empty($r['max_price']) || $v['price'] <= $r['max_price'])
                && (empty($r['size']) || ($v['length_ft'] . 'x' . $v['width_ft']) === $r['size']));
        });
        $cards = array_values(array_map(function ($p) use ($r) {
            $c = $this->card($p);
            if (empty($r['size'])) return $c;
            // Size pages (/king-size-mattresses) price each card for that size and link straight to it.
            $vs = array_values(array_filter($p['variants'], fn($v) => $v['status'] === 'active' && ($v['length_ft'] . 'x' . $v['width_ft']) === $r['size']));
            $prices = array_column($vs, 'price');
            $compare = array_filter(array_column($vs, 'compare_at_price'));
            return ['price_from' => min($prices), 'price_to' => max($prices), 'compare_at_from' => $compare ? min($compare) : null, 'sizes' => count($vs),
                    'in_stock' => array_sum(array_column($vs, 'stock')) > 0, 'size' => self::sizeSlug($vs[0])] + $c;
        }, $items));
        usort($cards, fn($a, $b) => [$b['in_stock'], $b['featured']] <=> [$a['in_stock'], $a['featured']] ?: $a['price_from'] <=> $b['price_from']);
        $kind = $g['kind'] ?? 'guide';
        $related = array_values(array_filter($this->getGuidePages(), fn($x) => $x['slug'] !== $g['slug']
            && match ($kind) { 'guide', 'local' => $x['kind'] === $kind, default => in_array($x['kind'], ['type', 'size'], true) && $x['category'] === $r['category'] }));
        $zones = !empty($g['city']) ? array_values(array_filter($this->db['zones'], fn($z) => $z['status'] === 'active' && ($z['city'] ?? null) === $g['city'])) : [];
        return $g + ['path' => "/buyers-guide/{$g['slug']}", 'kind' => 'guide', 'city' => null, 'body' => null, 'faqs' => [], 'seo_title' => null, 'seo_description' => null]
            + ['items' => $cards, 'price' => $cards ? ['min' => min(array_column($cards, 'price_from')), 'max' => max(array_column($cards, 'price_to'))] : null,
               'related' => $related, 'zones' => $zones];
    }

    private function getGuidePage(string $slug): array
    {
        $g = $this->find('guide_pages', $slug, 'slug');
        if (!$g || $g['status'] !== 'published') $this->fail(404, 'Guide page not found');
        return $this->landingView($g);
    }

    private function getLanding(): array
    {
        $path = '/' . trim((string) ($this->query['path'] ?? ''), '/');
        foreach ($this->db['guide_pages'] as $g) {
            if (($g['path'] ?? null) === $path && $g['status'] === 'published') return $this->landingView($g);
        }
        $this->fail(404, 'No landing page at that path');
    }

    /** Every listed size and price in a category — the "price guide" pages. */
    private function getPriceGuide(): array
    {
        $cat = $this->find('categories', (string) ($this->query['category'] ?? 'mattresses'), 'slug') ?? $this->fail(404, 'Category not found');
        $brand = $this->query['brand'] ?? null;
        $rows = [];
        foreach ($this->listed() as $p) {
            if ($p['category_id'] !== $cat['id']) continue;
            $b = $this->brand($p['brand_id']);
            if ($brand && $b['slug'] !== $brand) continue;
            $vs = array_values(array_filter($p['variants'], fn($v) => $v['status'] === 'active'));
            usort($vs, fn($x, $y) => [$x['width_ft'], $x['thickness_in'], $x['price']] <=> [$y['width_ft'], $y['thickness_in'], $y['price']]);
            $rows[] = ['product' => $this->card($p), 'variants' => array_map(fn($v) => array_intersect_key($v, array_flip(['id', 'size_label', 'width_ft', 'length_ft', 'thickness_in', 'max_load_kg', 'price', 'compare_at_price'])) + ['slug' => self::sizeSlug($v), 'in_stock' => $v['stock'] > 0], $vs)];
        }
        usort($rows, fn($x, $y) => [$x['product']['brand']['name'] !== 'Vitafoam', $x['product']['brand']['name'], $x['product']['price_from']] <=> [$y['product']['brand']['name'] !== 'Vitafoam', $y['product']['brand']['name'], $y['product']['price_from']]);
        $all = array_merge(...array_map(fn($r) => array_column($r['variants'], 'price'), $rows ?: [['variants' => []]]));
        return ['category' => ['name' => $cat['name'], 'slug' => $cat['slug']], 'brand' => $brand, 'updated_at' => date('Y-m-d'),
                'price' => $all ? ['min' => min($all), 'max' => max($all)] : null, 'products' => $rows,
                'brands' => array_values(array_unique(array_map(fn($r) => $r['product']['brand']['slug'], $rows)))];
    }

    /** One row per in-catalogue size, for the Google Merchant Center feed. */
    private function getProductFeed(): array
    {
        $items = [];
        foreach ($this->listed() as $p) {
            $b = $this->brand($p['brand_id']);
            $c = $this->category($p['category_id']);
            foreach ($p['variants'] as $v) {
                if ($v['status'] !== 'active') continue;
                $items[] = ['id' => $v['sku'], 'item_group_id' => 'P' . $p['id'], 'title' => "{$p['name']} – " . str_replace(' X ', ' × ', $v['size_label']),
                            'description' => trim(strtok($p['description'], "\n")), 'path' => "/product/{$p['slug']}?size=" . self::sizeSlug($v), 'image' => $p['media'][0]['url'] ?? null,
                            'brand' => $b['name'], 'price' => $v['compare_at_price'] ?: $v['price'], 'sale_price' => $v['compare_at_price'] ? $v['price'] : null,
                            'availability' => $v['stock'] > 0 ? 'in_stock' : 'out_of_stock', 'condition' => 'new',
                            'product_type' => $c['name'] . ($p['type'] ? ' > ' . ucfirst(str_replace('-', ' ', $p['type'])) : ''),
                            'size' => $v['size_label'], 'google_category' => $c['slug'] === 'pillows' ? 'Home & Garden > Linens & Bedding > Bedding > Pillows' : 'Furniture > Beds & Accessories > Mattresses'];
            }
        }
        return ['currency' => 'NGN', 'items' => $items];
    }

    // ------------------------------------------------------------------ delivery, cart, checkout

    private function earliestDate(?array $zone, string $fulfilment): string
    {
        $cut = $this->db['store']['same_day_cutoff'];
        $sameDay = date('H:i') < $cut && ($fulfilment === 'pickup' || ($zone['same_day'] ?? false));
        if ($sameDay) return date('Y-m-d');
        // Otherwise count working days (no Sunday delivery): 1 for next-day areas, more for courier areas such as Lagos.
        $d = date('Y-m-d');
        for ($n = $fulfilment === 'pickup' ? 1 : max(1, (int) ($zone['lead_days'] ?? 1)); $n > 0;) {
            $d = date('Y-m-d', strtotime("$d +1 day"));
            if (date('w', strtotime($d)) !== '0') $n--;
        }
        return $d;
    }

    private function deliveryQuote(): array
    {
        $ful = $this->in('fulfilment', 'delivery');
        $zone = $ful === 'delivery' ? $this->zone((int) $this->in('zone_id')) : null;
        if ($ful === 'delivery' && !$zone) $this->fail(422, 'Choose your area', ['zone_id' => 'Choose your area']);
        $earliest = $this->earliestDate($zone, $ful);
        return [
            'fulfilment' => $ful, 'fee' => $zone['fee'] ?? 0, 'zone' => $zone ? ['id' => $zone['id'], 'name' => $zone['name'], 'city' => $zone['city'] ?? null] : null,
            'pay_on_delivery' => $ful === 'pickup' || ($zone['pay_on_delivery'] ?? true),
            'same_day_available' => $earliest === date('Y-m-d'), 'earliest_date' => $earliest,
            'cutoff' => $this->db['store']['same_day_cutoff'],
            'eta' => match (true) {
                $ful === 'pickup' => 'Ready for pickup in about 2 hours',
                $earliest === date('Y-m-d') => 'Arrives today',
                ($zone['lead_days'] ?? 1) > 1 => "{$zone['eta_text']}: from " . date('l j M', strtotime($earliest)),
                default => 'Arrives ' . date('l j M', strtotime($earliest)),
            },
            'time_slots' => [['value' => 'morning', 'label' => 'Morning (9am–12pm)'], ['value' => 'afternoon', 'label' => 'Afternoon (12pm–4pm)'], ['value' => 'evening', 'label' => 'Evening (4pm–7pm)']],
        ];
    }

    private function &cart(bool $create = false): array
    {
        $t = $this->headers['x-cart-token'] ?? '';
        foreach ($this->db['carts'] as &$c) {
            if ($t !== '' && $c['token'] === $t) return $c;
        }
        unset($c);
        if (!$create) $this->fail(404, 'Cart not found');
        $this->db['carts'][] = ['token' => bin2hex(random_bytes(16)), 'items' => [], 'customer_id' => null, 'created_at' => $this->now()];
        $this->dirty = true;
        $last = array_key_last($this->db['carts']);
        return $this->db['carts'][$last];
    }

    private function cartView(array $c): array
    {
        $idx = $this->variantIndex();
        $items = [];
        foreach ($c['items'] as $i) {
            if (!isset($idx[$i['variant_id']])) continue;
            [$p, $v] = $idx[$i['variant_id']];
            $items[] = ['id' => $i['id'], 'variant_id' => $v['id'], 'qty' => $i['qty'],
                        'product' => ['id' => $p['id'], 'slug' => $p['slug'], 'name' => $p['name'], 'brand' => $this->brand($p['brand_id'])['name'],
                                      'brand_color' => $this->brand($p['brand_id'])['color'] ?? null, 'type' => $p['type'], 'category' => $this->category($p['category_id'])['slug']],
                        'size_label' => $v['size_label'], 'sku' => $v['sku'], 'unit_price' => $v['price'], 'compare_at_price' => $v['compare_at_price'],
                        'line_total' => $v['price'] * $i['qty'], 'stock' => $v['stock']];
        }
        return ['token' => $c['token'], 'items' => $items, 'count' => array_sum(array_column($items, 'qty')),
                'subtotal' => array_sum(array_column($items, 'line_total'))];
    }

    private function createCart(): array
    {
        $c = $this->cart(true);
        return $this->cartView($c);
    }

    private function getCart(): array
    {
        $t = $this->headers['x-cart-token'] ?? '';
        foreach ($this->db['carts'] as $c) if ($t !== '' && $c['token'] === $t) return $this->cartView($c);
        return ['token' => null, 'items' => [], 'count' => 0, 'subtotal' => 0];
    }

    private function clearCart(): array
    {
        $c = &$this->cart();
        $c['items'] = [];
        return $this->cartView($c);
    }

    private function addCartItem(): array
    {
        $vid = (int) $this->in('variant_id');
        $qty = max(1, (int) $this->in('qty', 1));
        $idx = $this->variantIndex();
        if (!isset($idx[$vid]) || $idx[$vid][0]['status'] !== 'active') $this->fail(422, 'Choose a size', ['variant_id' => 'Choose a size']);
        $stock = $idx[$vid][1]['stock'];
        $c = &$this->cart(true);
        foreach ($c['items'] as &$i) {
            if ($i['variant_id'] === $vid) {
                if ($i['qty'] + $qty > $stock) $this->fail(409, "Only $stock left in this size");
                $i['qty'] += $qty;
                return self::status(200, $this->cartView($c));
            }
        }
        unset($i);
        if ($qty > $stock) $this->fail(409, $stock ? "Only $stock left in this size" : 'This size is out of stock');
        $c['items'][] = ['id' => (max(array_merge([0], array_column($c['items'], 'id'))) + 1), 'variant_id' => $vid, 'qty' => $qty];
        return $this->cartView($c);
    }

    private function updateCartItem(string $id): array
    {
        $qty = (int) $this->in('qty', 1);
        $c = &$this->cart();
        $idx = $this->variantIndex();
        foreach ($c['items'] as $k => &$i) {
            if ($i['id'] === (int) $id) {
                if ($qty <= 0) { unset($c['items'][$k]); $c['items'] = array_values($c['items']); return $this->cartView($c); }
                $stock = $idx[$i['variant_id']][1]['stock'] ?? 0;
                if ($qty > $stock) $this->fail(409, "Only $stock left in this size");
                $i['qty'] = $qty;
                return $this->cartView($c);
            }
        }
        $this->fail(404, 'Item not in cart');
    }

    private function removeCartItem(string $id): array
    {
        $c = &$this->cart();
        $c['items'] = array_values(array_filter($c['items'], fn($i) => $i['id'] !== (int) $id));
        return $this->cartView($c);
    }

    private function coupon(?string $code, float $subtotal, float $fee): array
    {
        if (!$code) return [0, null];
        $c = $this->find('coupons', strtoupper(trim($code)), 'code');
        if (!$c || $c['status'] !== 'active') $this->fail(422, 'That code is not valid', ['coupon' => 'That code is not valid']);
        if ($c['min_subtotal'] && $subtotal < $c['min_subtotal']) $this->fail(422, 'Spend ₦' . number_format($c['min_subtotal']) . ' to use this code', ['coupon' => 'Spend ₦' . number_format($c['min_subtotal']) . ' to use this code']);
        $d = match ($c['type']) { 'percent' => round($subtotal * $c['value'] / 100), 'fixed' => min($subtotal, $c['value']), 'free_delivery' => $fee, default => 0 };
        return [$d, $c['code']];
    }

    private function checkoutQuote(): array
    {
        $cart = $this->getCart();
        $ful = $this->in('fulfilment', 'delivery');
        $zone = $ful === 'delivery' ? $this->zone((int) $this->in('zone_id')) : null;
        $fee = $zone['fee'] ?? 0;
        [$disc, $code] = $this->coupon($this->in('coupon'), $cart['subtotal'], $fee);
        return ['subtotal' => $cart['subtotal'], 'delivery_fee' => $fee, 'discount' => $disc, 'coupon_code' => $code,
                'total' => $cart['subtotal'] + $fee - $disc, 'count' => $cart['count'],
                'earliest_date' => $this->earliestDate($zone, $ful), 'fee_pending' => $ful === 'delivery' && !$zone,
                'pay_on_delivery' => $ful === 'pickup' || ($zone['pay_on_delivery'] ?? true)];
    }

    private function newOrderNumber(): string
    {
        $prefix = $this->db['store']['order_prefix'];
        do {
            $n = $prefix . '-' . date('ymd') . str_pad((string) random_int(10, 99), 2, '0', STR_PAD_LEFT);
        } while ($this->find('orders', $n, 'number'));
        return $n;
    }

    private function validateContact(array &$errors): void
    {
        if (trim((string) $this->in('first_name')) === '') $errors['first_name'] = 'Enter your first name';
        if (!self::validPhone((string) $this->in('phone'))) $errors['phone'] = 'Enter a Nigerian mobile number, e.g. 0803 123 4567';
        $email = trim((string) $this->in('email'));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email or leave it blank';
    }

    private function createOrder(array $items, string $channel, ?int $staffId = null): array
    {
        $errors = [];
        $this->validateContact($errors);
        $ful = $this->in('fulfilment', 'delivery');
        $zone = null;
        if ($ful === 'delivery') {
            $zone = $this->zone((int) $this->in('zone_id'));
            if (!$zone) $errors['zone_id'] = 'Choose your area';
            if (trim((string) $this->in('street')) === '') $errors['street'] = 'Enter your street address';
        } elseif ($ful !== 'pickup') {
            $errors['fulfilment'] = 'Choose delivery or pickup';
        }
        $pmType = (string) $this->in('payment_method');
        $pm = $this->find('payment_methods', $pmType, 'type');
        if (!$pm || !$pm['enabled']) $errors['payment_method'] = 'Choose how you want to pay';
        elseif ($pmType === 'pod' && $zone && !($zone['pay_on_delivery'] ?? true)) $errors['payment_method'] = "Pay on delivery isn't available for {$zone['name']}, {$zone['city']}. Pay online or by bank transfer.";
        $earliest = $this->earliestDate($zone, $ful);
        $date = (string) $this->in('preferred_date', $earliest);
        if ($channel !== 'walk_in' && $date < $earliest) $errors['preferred_date'] = 'The earliest date is ' . date('D j M', strtotime($earliest));
        if (!$items) $errors['cart'] = 'Your cart is empty';
        $idx = $this->variantIndex();
        foreach ($items as $i) {
            [$p, $v] = $idx[$i['variant_id']] ?? [null, null];
            if (!$v) { $errors['cart'] = 'An item in your cart is no longer available'; continue; }
            if ($v['stock'] < $i['qty']) $errors['cart'] = "{$p['name']} ({$v['size_label']}): only {$v['stock']} left";
        }
        if ($errors) $this->fail(422, 'Check the highlighted fields', $errors);

        $fee = $zone['fee'] ?? 0;
        $sub = array_sum(array_map(fn($i) => $idx[$i['variant_id']][1]['price'] * $i['qty'], $items));
        [$disc, $code] = $this->coupon($this->in('coupon'), $sub, $fee);
        $phone = self::normPhone((string) $this->in('phone'));
        $me = $this->customer();
        $existing = array_values(array_filter($this->db['customers'], fn($c) => $c['phone'] === $phone))[0] ?? null;

        $order = [
            'id' => $this->nextId('orders'), 'number' => $this->newOrderNumber(), 'access_token' => bin2hex(random_bytes(16)),
            'customer_id' => $me['id'] ?? ($existing && $existing['has_password'] ? $existing['id'] : null),
            'contact' => ['first_name' => trim($this->in('first_name')), 'last_name' => trim((string) $this->in('last_name')) ?: null,
                          'phone' => $phone, 'email' => trim((string) $this->in('email')) ?: null],
            'fulfilment' => $ful,
            'location' => $ful === 'pickup' ? ['kind' => 'store', 'location_id' => (int) $this->in('location_id', 1)]
                                             : ['kind' => 'delivery', 'zone_id' => $zone['id'], 'street' => trim($this->in('street')), 'landmark' => trim((string) $this->in('landmark')) ?: null],
            'note' => trim((string) $this->in('note')) ?: null,
            'preferred_date' => $date, 'time_slot' => $this->in('time_slot') ?: null,
            'items' => array_map(fn($i) => ['variant_id' => $i['variant_id'], 'qty' => $i['qty'], 'unit_price' => $idx[$i['variant_id']][1]['price']], $items),
            'delivery_fee' => $fee, 'discount' => $disc, 'coupon_code' => $code, 'payment_method' => $pmType, 'payments' => [],
            'events' => [['status' => 'placed', 'note' => $channel === 'web' ? null : 'Created in CMS', 'is_public' => true, 'staff_id' => $staffId, 'created_at' => $this->now()]],
            'channel' => $channel, 'internal_notes' => [], 'created_at' => $this->now(),
        ];
        foreach ($items as $i) $this->moveStock($i['variant_id'], -$i['qty'], 'sale', $order['id'], $staffId);
        if (!$existing) {
            $this->db['customers'][] = ['id' => $this->nextId('customers'), 'first_name' => $order['contact']['first_name'], 'last_name' => $order['contact']['last_name'],
                                        'phone' => $phone, 'email' => $order['contact']['email'], 'has_password' => false, 'status' => 'active',
                                        'addresses' => [], 'created_at' => $this->now(), 'last_login_at' => null];
        }
        $this->db['orders'][] = $order;
        $this->db['outbox'][] = ['id' => $this->nextId('outbox'), 'channel' => 'sms', 'recipient' => $phone, 'template' => 'order_placed',
                                 'status' => 'sent', 'attempts' => 1, 'last_error' => null, 'created_at' => $this->now(), 'sent_at' => $this->now()];
        return $order;
    }

    private function placeOrder(): array
    {
        $cart = &$this->cart();
        $order = $this->createOrder(array_map(fn($i) => ['variant_id' => $i['variant_id'], 'qty' => $i['qty']], $cart['items']), $this->in('payment_method') === 'whatsapp' ? 'whatsapp' : 'web');
        $cart['items'] = [];
        $view = $this->orderView($order);
        $next = match ($order['payment_method']) {
            'paystack' => ['type' => 'paystack', 'action' => 'POST /payments/paystack/init'],
            'whatsapp' => ['type' => 'whatsapp', 'url' => $this->whatsappLink($view)],
            'transfer' => ['type' => 'transfer', 'instructions' => $view['payment_method']['instructions']],
            default => ['type' => 'pod'],
        };
        // The shopper who placed it can see the full order straight away.
        return ['number' => $order['number'], 'access_token' => $order['access_token']] + $this->issueOrderGrant($order['number']) + ['total' => $view['total'], 'next' => $next];
    }

    private function whatsappLink(array $o): string
    {
        $wa = array_values(array_filter($this->db['store']['contacts'], fn($c) => $c['type'] === 'whatsapp'))[0]['value'] ?? '';
        $lines = ["Hello, I just placed order {$o['number']}:"];
        foreach ($o['items'] as $i) $lines[] = "• {$i['qty']} × {$i['product_name']} ({$i['size_label']}) — ₦" . number_format($i['line_total']);
        $lines[] = 'Total: ₦' . number_format($o['total']);
        $lines[] = $o['fulfilment'] === 'pickup' ? 'Pickup at the showroom' : "Deliver to: {$o['location']['street']}, {$o['location']['zone_name']}, {$o['location']['city']}";
        return 'https://wa.me/' . $wa . '?text=' . rawurlencode(implode("\n", $lines));
    }

    // Order links. Everything about an order (reading it, uploading a receipt, paying) needs the
    // link token AND one of these; anything less is a plain 404, as for an order that doesn't exist.
    //  - 'full':    the signed-in owner (account, or code sign-in), or the browser that placed it (checkout grant)
    //  - 'summary': link + the order's phone (unlock grant). Status, items, totals, pay, receipt upload;
    //               no name, contact or address. Signing in shows those.
    private const ORDER_GRANT_TTL = 43200; // 12 hours

    private function issueOrderGrant(string $number, string $level = 'full'): array
    {
        $now = time();
        $this->db['order_grants'] = array_filter($this->db['order_grants'] ?? [], fn($g) => $g['expires'] > $now);
        $grant = 'og_' . bin2hex(random_bytes(16));
        $this->db['order_grants'][$grant] = ['number' => $number, 'level' => $level, 'expires' => $now + self::ORDER_GRANT_TTL];
        $this->dirty = true;
        return ['grant' => $grant, 'expires_at' => date('Y-m-d H:i:s', $now + self::ORDER_GRANT_TTL)];
    }

    /** @return 'full'|'summary'|null */
    private function orderAccess(array $o): ?string
    {
        if ($this->session('customer')) foreach ($this->myOrders() as $m) if ($m['number'] === $o['number']) return 'full';
        $g = $this->db['order_grants'][$this->headers['x-order-grant'] ?? ''] ?? null;
        return $g && $g['number'] === $o['number'] && $g['expires'] > time() ? ($g['level'] ?? 'full') : null;
    }

    /** @return array{0:array,1:string} the order and the access level */
    private function gatedOrder(string $number, string $token): array
    {
        $o = $this->find('orders', $number, 'number');
        $level = $o && hash_equals($o['access_token'], $token) ? $this->orderAccess($o) : null;
        if (!$level) $this->fail(404, 'Order not found');
        return [$o, $level];
    }

    private function getOrder(string $number): array
    {
        [$o, $level] = $this->gatedOrder($number, (string) ($this->query['token'] ?? ''));
        $v = $this->orderView($o);
        if ($level === 'summary') {
            unset($v['id'], $v['contact'], $v['note'], $v['channel']);
            $v['location'] = $v['location']['kind'] === 'store' ? $v['location'] : ['kind' => 'delivery', 'zone_name' => $v['location']['zone_name'], 'city' => $v['location']['city']];
            return $v + ['access' => 'summary'];
        }
        $v['whatsapp_url'] = $this->whatsappLink($v);
        return $v + ['access' => 'full'];
    }

    private function unlockOrder(string $number): array
    {
        // Wrong link and wrong phone get the same answer, so a guesser learns nothing either way.
        $o = $this->find('orders', $number, 'number');
        $ok = $o && hash_equals($o['access_token'], (string) $this->in('token', '')) && $o['contact']['phone'] === self::normPhone((string) $this->in('phone', ''));
        $this->loginGuard('order', $number, $ok, "We couldn't find that order. Check the phone number and try again.", 404);
        return $this->issueOrderGrant($number, 'summary');
    }

    private function &orderRef(string $field, string $value): array
    {
        foreach ($this->db['orders'] as &$o) if ((string) $o[$field] === $value) return $o;
        unset($o);
        $this->fail(404, 'Order not found');
    }

    private function transferReceipt(string $number): array
    {
        $this->gatedOrder($number, (string) $this->in('token', ''));
        $o = &$this->orderRef('number', $number);
        [, $total] = $this->orderTotals($o);
        $o['payments'][] = ['id' => count($o['payments']) + 1, 'method' => 'transfer', 'reference' => 'TRF_' . substr(md5((string) microtime(true)), 0, 10),
                            'amount' => $total, 'status' => 'pending', 'receipt' => (string) $this->in('filename', 'receipt.jpg'), 'paid_at' => null];
        return ['status' => 'pending', 'message' => "Receipt received. We'll confirm your payment shortly."];
    }

    private function paystackInit(): array
    {
        $this->gatedOrder((string) $this->in('number'), (string) $this->in('token', ''));
        $o = &$this->orderRef('number', (string) $this->in('number'));
        [, $total] = $this->orderTotals($o);
        $ref = 'PSK_' . substr(md5($o['number'] . microtime(true)), 0, 12);
        $o['payments'][] = ['id' => count($o['payments']) + 1, 'method' => 'paystack', 'reference' => $ref, 'amount' => $total, 'status' => 'pending', 'receipt' => null, 'paid_at' => null];
        // A real response points at checkout.paystack.com. The mock points at the storefront's fake checkout page.
        return ['reference' => $ref, 'amount' => $total, 'authorization_url' => '/pay/mock?reference=' . $ref, 'access_code' => 'mock_' . substr($ref, 4)];
    }

    private function paystackVerify(): array
    {
        $ref = (string) ($this->query['reference'] ?? '');
        $outcome = ($this->query['outcome'] ?? 'success') === 'success' ? 'success' : 'failed';
        foreach ($this->db['orders'] as &$o) {
            foreach ($o['payments'] as &$p) {
                if ($p['reference'] === $ref) {
                    if ($p['status'] === 'pending') { $this->dirty = true; $p['status'] = $outcome; $p['paid_at'] = $outcome === 'success' ? $this->now() : null; }
                    return ['reference' => $ref, 'status' => $p['status'], 'number' => $o['number'], 'access_token' => $o['access_token'], 'amount' => $p['amount']];
                }
            }
            unset($p);
        }
        $this->fail(404, 'Payment not found');
    }

    // ------------------------------------------------------------------ content

    private function postView(array $p, bool $full = false): array
    {
        $c = $p['category_id'] ? $this->find('post_categories', $p['category_id']) : null;
        $out = ['id' => $p['id'], 'slug' => $p['slug'], 'title' => $p['title'], 'excerpt' => $p['excerpt'], 'published_at' => $p['published_at'],
                'category' => $c ? ['name' => $c['name'], 'slug' => $c['slug']] : null, 'view_count' => $p['view_count'],
                'reading_minutes' => max(1, (int) ceil(str_word_count($p['body']) / 200)),
                'tags' => array_values(array_map(fn($t) => ['name' => $t['name'], 'slug' => $t['slug']], array_filter($this->db['post_tags'], fn($t) => in_array($t['id'], $p['tags'], true))))];
        if ($full) {
            $out['body'] = $p['body'];
            $out['author'] = $this->staffName($p['author_id']);
        }
        return $out;
    }

    private function published(): array
    {
        $rows = array_values(array_filter($this->db['posts'], fn($p) => $p['status'] === 'published'));
        usort($rows, fn($a, $b) => strcmp($b['published_at'], $a['published_at']));
        return $rows;
    }

    private function getPosts(): array
    {
        $rows = $this->published();
        if (!empty($this->query['category'])) {
            $c = $this->find('post_categories', $this->query['category'], 'slug');
            $rows = array_filter($rows, fn($p) => $c && $p['category_id'] === $c['id']);
        }
        if (!empty($this->query['tag'])) {
            $t = $this->find('post_tags', $this->query['tag'], 'slug');
            $rows = array_filter($rows, fn($p) => $t && in_array($t['id'], $p['tags'], true));
        }
        if (!empty($this->query['q'])) {
            $q = mb_strtolower((string) $this->query['q']);
            $rows = array_filter($rows, fn($p) => str_contains(mb_strtolower($p['title'] . ' ' . $p['body']), $q));
        }
        return $this->paginate(array_values(array_map(fn($p) => $this->postView($p), $rows)), 9);
    }

    private function getPost(string $slug): array
    {
        foreach ($this->db['posts'] as &$p) {
            if ($p['slug'] === $slug && $p['status'] === 'published') {
                $p['view_count']++;
                $this->dirty = true;
                $view = $this->postView($p, true);
                $view['recent'] = array_map(fn($x) => $this->postView($x), array_slice(array_values(array_filter($this->published(), fn($x) => $x['slug'] !== $slug)), 0, 4));
                return $view;
            }
        }
        $this->fail(404, 'Post not found');
    }

    private function getComments(string $slug): array
    {
        $p = $this->find('posts', $slug, 'slug') ?? $this->fail(404, 'Post not found');
        return array_values(array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name'], 'body' => $c['body'], 'created_at' => $c['created_at']],
            array_filter($this->db['post_comments'], fn($c) => $c['post_id'] === $p['id'] && $c['status'] === 'approved')));
    }

    private function postComment(string $slug): array
    {
        $p = $this->find('posts', $slug, 'slug') ?? $this->fail(404, 'Post not found');
        $errors = [];
        if (trim((string) $this->in('name')) === '') $errors['name'] = 'Enter your name';
        if (mb_strlen(trim((string) $this->in('body'))) < 3) $errors['body'] = 'Write a comment';
        if ($errors) $this->fail(422, 'Check the highlighted fields', $errors);
        $this->db['post_comments'][] = ['id' => $this->nextId('post_comments'), 'post_id' => $p['id'], 'name' => trim($this->in('name')),
                                        'body' => trim($this->in('body')), 'status' => 'pending', 'created_at' => $this->now()];
        return ['status' => 'pending', 'message' => 'Thanks! Your comment will appear once approved.'];
    }

    private function getPostCategories(): array
    {
        $pub = $this->published();
        return array_values(array_filter(array_map(fn($c) => $c + ['count' => count(array_filter($pub, fn($p) => $p['category_id'] === $c['id']))], $this->db['post_categories']), fn($c) => $c['count'] > 0));
    }

    private function getPage(string $slug): array
    {
        $p = $this->find('pages', $slug, 'slug');
        if (!$p || $p['status'] !== 'published') $this->fail(404, 'Page not found');
        return $p;
    }

    private function getFaqs(): array
    {
        $groups = [];
        $faqs = $this->db['faqs'];
        usort($faqs, fn($a, $b) => [$a['sort'], $a['id']] <=> [$b['sort'], $b['id']]); // CMS order
        foreach ($faqs as $f) {
            if ($f['status'] !== 'active') continue;
            $groups[$f['group_name']][] = ['id' => $f['id'], 'question' => $f['question'], 'answer' => $f['answer']];
        }
        return array_map(fn($g, $items) => ['group' => $g, 'items' => $items], array_keys($groups), $groups);
    }

    private function getBanners(): array
    {
        $pl = $this->query['placement'] ?? null;
        $now = $this->now();
        $rows = array_filter($this->db['banners'], fn($b) => $b['status'] === 'active' && (!$pl || $b['placement'] === $pl)
            && (!$b['starts_at'] || $b['starts_at'] <= $now) && (!$b['ends_at'] || $b['ends_at'] >= $now));
        usort($rows, fn($a, $b) => $a['sort'] <=> $b['sort']);
        return array_values($rows);
    }

    private function postContact(): array
    {
        $errors = [];
        if (trim((string) $this->in('name')) === '') $errors['name'] = 'Enter your name';
        if (!self::validPhone((string) $this->in('phone'))) $errors['phone'] = 'Enter a Nigerian mobile number';
        if (mb_strlen(trim((string) $this->in('message'))) < 5) $errors['message'] = 'Write your message';
        if ($errors) $this->fail(422, 'Check the highlighted fields', $errors);
        $this->db['contact_messages'][] = ['id' => $this->nextId('contact_messages'), 'name' => trim($this->in('name')), 'phone' => self::normPhone($this->in('phone')),
                                           'email' => trim((string) $this->in('email')) ?: null, 'subject' => trim((string) $this->in('subject')) ?: null,
                                           'message' => trim($this->in('message')), 'status' => 'new', 'created_at' => $this->now()];
        return ['message' => "Thanks, we've got your message. We usually reply within an hour during opening times."];
    }

    private function postNewsletter(): array
    {
        $email = trim((string) $this->in('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $this->fail(422, 'Enter a valid email', ['email' => 'Enter a valid email']);
        if (!$this->find('subscribers', $email, 'email')) {
            $this->db['subscribers'][] = ['id' => $this->nextId('subscribers'), 'email' => $email, 'phone' => null, 'created_at' => $this->now()];
        }
        return ['message' => "You're subscribed."];
    }

    private function getSitemap(): array
    {
        $u = [['loc' => '/', 'lastmod' => date('Y-m-d')], ['loc' => '/shop'], ['loc' => '/brands'], ['loc' => '/buyers-guide'], ['loc' => '/blog'],
              ['loc' => '/visit-us'], ['loc' => '/faq'], ['loc' => '/delivery-and-payment'], ['loc' => '/contact']];
        foreach ($this->getCategories() as $c) $u[] = ['loc' => "/{$c['slug']}"];
        foreach ($this->getBrands() as $b) $u[] = ['loc' => "/brands/{$b['slug']}"];
        foreach ($this->listed() as $p) $u[] = ['loc' => "/product/{$p['slug']}", 'lastmod' => substr($p['created_at'], 0, 10)];
        foreach ($this->getGuidePages() as $g) $u[] = ['loc' => $g['path']];
        foreach (['/mattress-prices', '/pillow-prices', '/mattress-sizes'] as $x) $u[] = ['loc' => $x, 'lastmod' => date('Y-m-d')];
        foreach ($this->published() as $p) $u[] = ['loc' => "/blog/{$p['slug']}", 'lastmod' => substr($p['published_at'], 0, 10)];
        foreach ($this->db['pages'] as $p) if ($p['status'] === 'published') $u[] = ['loc' => '/' . $p['slug']];
        return $u;
    }

    private function resolveRedirect(): array
    {
        $r = $this->find('redirects', (string) ($this->query['path'] ?? ''), 'from_path');
        if (!$r) $this->fail(404, 'No redirect');
        return ['to' => $r['to_path'], 'code' => $r['code']];
    }

    // ------------------------------------------------------------------ customer accounts

    /** $id is null for an email-code session: it sees orders placed with that email, nothing else. */
    private function startSession(string $type, ?int $id, array $extra = []): string
    {
        $t = $type . '_' . bin2hex(random_bytes(16));
        $this->db['sessions'][] = ['token' => $t, 'type' => $type, 'user_id' => $id, 'created_at' => $this->now()] + $extra;
        $this->dirty = true;
        return $t;
    }

    // Brute-force guard for anything that checks a secret (logins, order phone checks). Silent: a locked login looks exactly like a wrong
    // password (same 401, same message), so nobody is told about a cooldown.
    // - Misses are counted in two buckets: this account from this IP (locks at 5) and this
    //   account from anywhere (locks at 20, for attacks spread over many IPs).
    // - Every miss after the first slows the reply: 1s, 2s, 4s, then 8s each.
    // - While locked, the right password is refused until it's been entered 3 times in a row
    //   from the same IP; the 3rd signs in. Any miss resets that run.
    // - Otherwise the lock lifts 15 minutes after the last miss. A sign-in clears everything.
    private const LOGIN_WINDOW = 900, LOGIN_MAX_IP = 5, LOGIN_MAX_ACCOUNT = 20, LOGIN_UNLOCK_RUN = 3, LOGIN_MAX_DELAY = 8;

    /** Returns if the sign-in may go ahead; otherwise replies 401 $wrong (after any delay). */
    private function loginGuard(string $scope, string $who, bool $ok, string $wrong, int $status = 401): void
    {
        $now = time();
        $ip = $this->headers['x-client-ip'] ?? 'unknown';
        $buckets = ["$scope|$who|$ip" => self::LOGIN_MAX_IP, "$scope|$who" => self::LOGIN_MAX_ACCOUNT];
        $runKey = "$scope|$who|$ip|run";
        $this->db['login_attempts'] = array_filter($this->db['login_attempts'] ?? [], fn($a) => $a['until'] > $now);
        $att = &$this->db['login_attempts'];
        $this->dirty = true;

        $fails = 0; $locked = false; $early = false;
        foreach ($buckets as $k => $max) {
            if (!isset($att[$k])) continue;
            $fails = max($fails, $att[$k]['fails']);
            $locked = $locked || $att[$k]['fails'] >= $max;
            $early = $early || ($att[$k]['next_at'] ?? 0) > $now;
        }
        // A second request while the last one is still being slowed down (scripts firing in parallel): refuse unread.
        if ($early) $this->fail($status, $wrong);

        $run = ($att[$runKey]['n'] ?? 0) + 1;
        if ($ok && (!$locked || $run >= self::LOGIN_UNLOCK_RUN)) {
            foreach ([...array_keys($buckets), $runKey] as $k) unset($att[$k]);
            return;
        }
        if ($ok) {
            $att[$runKey] = ['n' => $run, 'until' => $now + self::LOGIN_WINDOW]; // locked: counts towards unlocking, but looks like a miss
        } else {
            unset($att[$runKey]);
            $fails++;
            foreach ($buckets as $k => $max) $att[$k] = ['fails' => ($att[$k]['fails'] ?? 0) + 1, 'until' => $now + self::LOGIN_WINDOW];
        }
        $delay = $fails <= 1 ? 0 : min(self::LOGIN_MAX_DELAY, 2 ** ($fails - 2));
        foreach ($buckets as $k => $max) if (isset($att[$k])) $att[$k]['next_at'] = $now + $delay;
        if ($delay) { $this->save(); sleep($delay); } // save first so a parallel request sees next_at
        $this->fail($status, $wrong);
    }

    private function login(): array
    {
        // Members sign in with their phone OR email, plus password.
        [$ch, $who] = self::otpTarget((string) ($this->in('login') ?? $this->in('phone') ?? ''));
        $c = array_values(array_filter($this->db['customers'], fn($c) => $c['has_password']
            && ($ch === 'sms' ? $c['phone'] === $who : strcasecmp((string) $c['email'], $who) === 0)))[0] ?? null;
        $this->loginGuard('customer', $who, $c && $this->in('password') === $this->config['demo_password'], 'Wrong phone number, email or password');
        if ($c['status'] !== 'active') $this->fail(403, 'This account is blocked. Contact us for help.');
        foreach ($this->db['customers'] as &$row) if ($row['id'] === $c['id']) $row['last_login_at'] = $this->now();
        return self::status(200, ['token' => $this->startSession('customer', $c['id'], ['via' => 'password']), 'customer' => $this->meView($c)]);
    }

    // ------------------------------------------------------------------ one-time codes
    // A 6-digit code by SMS (phone) or email. 5 minutes, single use, 5 guesses per code.
    // Resends: one a minute, five an hour per destination (also caps what an attacker can spend on our SMS).
    // Login codes only go to a phone/email that has ordered or registered; the reply is the same either way.
    private const OTP_TTL = 300, OTP_RESEND = 60, OTP_PER_HOUR = 5, OTP_TRIES = 5;

    /** @return array{0:'sms'|'email',1:string} */
    private static function otpTarget(string $to): array
    {
        $to = trim($to);
        return str_contains($to, '@') ? ['email', mb_strtolower($to)] : ['sms', self::normPhone($to)];
    }

    private function emailHasOrders(string $email): bool
    {
        foreach ($this->db['orders'] as $o) if (strcasecmp((string) $o['contact']['email'], $email) === 0) return true;
        return false;
    }

    private function otpSend(): array
    {
        $purpose = (string) $this->in('purpose', 'login');
        if (!in_array($purpose, ['login', 'register'], true)) $this->fail(422, 'Check the highlighted fields', ['purpose' => 'login or register']);
        [$ch, $to] = self::otpTarget((string) ($this->in('to') ?? $this->in('phone') ?? ''));
        if ($ch === 'sms' && !self::validPhone($to)) $this->fail(422, 'Check the highlighted fields', ['to' => 'Enter a Nigerian mobile number or an email address']);
        if ($ch === 'email' && ($purpose === 'register' || !filter_var($to, FILTER_VALIDATE_EMAIL))) {
            $this->fail(422, 'Check the highlighted fields', ['to' => $purpose === 'register' ? 'Use your phone number' : 'Enter a valid email address']);
        }
        $member = array_values(array_filter($this->db['customers'], fn($c) => $c['phone'] === $to))[0] ?? null;
        if ($purpose === 'register' && $member && $member['has_password']) $this->fail(422, 'Check the highlighted fields', ['phone' => 'An account with this phone already exists. Sign in instead.']);

        $key = "$purpose|$to";
        $now = time();
        $rec = $this->db['otps'][$key] ?? ['sent' => []];
        $rec['sent'] = array_values(array_filter($rec['sent'], fn($t) => $t > $now - 3600));
        if ($rec['sent'] && end($rec['sent']) > $now - self::OTP_RESEND) {
            $wait = end($rec['sent']) + self::OTP_RESEND - $now;
            $this->fail(429, "Wait $wait seconds before asking for another code.", ['retry_after' => (string) $wait]);
        }
        if (count($rec['sent']) >= self::OTP_PER_HOUR) $this->fail(429, "That's a lot of codes. Try again in an hour, or call us.");

        $eligible = $purpose === 'register' || ($ch === 'sms' ? (bool) $member : $this->emailHasOrders($to));
        $rec['sent'][] = $now;
        $code = null;
        unset($rec['hash'], $rec['expires']);
        if ($eligible) {
            $code = sprintf('%06d', random_int(0, 999999));
            $rec += ['hash' => hash('sha256', "$key|$code"), 'expires' => $now + self::OTP_TTL];
            $rec['tries'] = 0;
            $this->db['outbox'][] = ['id' => $this->nextId('outbox'), 'channel' => $ch, 'recipient' => $to, 'template' => "otp_$purpose",
                                     'status' => 'sent', 'attempts' => 1, 'last_error' => null, 'created_at' => $this->now(), 'sent_at' => $this->now()];
        }
        $this->db['otps'][$key] = $rec;
        $this->dirty = true;
        $out = ['sent' => true, 'channel' => $ch, 'expires_in' => self::OTP_TTL, 'resend_in' => self::OTP_RESEND];
        if ($this->config['demo_otp'] ?? true) $out['demo_code'] = $code; // demo only: there's no real SMS/email
        return $out;
    }

    private function checkOtp(string $purpose, string $to, string $code): bool
    {
        $key = "$purpose|$to";
        $rec = &$this->db['otps'][$key];
        $this->dirty = true;
        if (!isset($rec['hash']) || $rec['expires'] <= time()) return false;
        if (++$rec['tries'] > self::OTP_TRIES) { unset($rec['hash'], $rec['expires']); return false; }
        if (!hash_equals($rec['hash'], hash('sha256', "$key|" . preg_replace('/\D/', '', $code)))) return false;
        unset($rec['hash'], $rec['expires']); // single use
        return true;
    }

    /** Code sign-in. Phone: signs in as that customer (guest or member). Email: shows only orders placed with that email. */
    private function otpVerify(): array
    {
        [$ch, $to] = self::otpTarget((string) ($this->in('to') ?? $this->in('phone') ?? ''));
        if (!$this->checkOtp('login', $to, (string) $this->in('code', ''))) $this->fail(401, 'That code is wrong or has expired.');
        if ($ch === 'email') {
            return self::status(200, ['token' => $this->startSession('customer', null, ['via' => 'email_code', 'email' => $to]), 'customer' => $this->emailMeView($to)]);
        }
        $c = array_values(array_filter($this->db['customers'], fn($c) => $c['phone'] === $to))[0] ?? $this->fail(401, 'That code is wrong or has expired.');
        if ($c['status'] !== 'active') $this->fail(403, 'This account is blocked. Contact us for help.');
        foreach ($this->db['customers'] as &$row) if ($row['id'] === $c['id']) $row['last_login_at'] = $this->now();
        return self::status(200, ['token' => $this->startSession('customer', $c['id'], ['via' => 'phone_code']), 'customer' => $this->meView($c)]);
    }

    private function register(): array
    {
        $errors = [];
        $this->validateContact($errors);
        if (mb_strlen((string) $this->in('password')) < 8) $errors['password'] = 'Use at least 8 characters';
        $phone = self::normPhone((string) $this->in('phone'));
        $email = mb_strtolower(trim((string) $this->in('email'))) ?: null;
        $existing = array_values(array_filter($this->db['customers'], fn($c) => $c['phone'] === $phone))[0] ?? null;
        if ($existing && $existing['has_password']) $errors['phone'] = 'An account with this phone already exists. Sign in instead.';
        if ($email && array_filter($this->db['customers'], fn($c) => $c['has_password'] && strcasecmp((string) $c['email'], $email) === 0)) $errors['email'] = 'An account with this email already exists. Sign in instead.';
        if ($errors) $this->fail(422, 'Check the highlighted fields', $errors);
        // The phone must be proven: otherwise anyone could register a guest's number and see their orders.
        if (!$this->checkOtp('register', $phone, (string) $this->in('code', ''))) $this->fail(422, 'Check the highlighted fields', ['code' => 'That code is wrong or has expired. Ask for a new one.']);

        $fields = ['first_name' => trim($this->in('first_name')), 'last_name' => trim((string) $this->in('last_name')) ?: null, 'email' => $email, 'has_password' => true, 'last_login_at' => $this->now()];
        if ($existing) { // guest becomes a member; earlier orders link up
            foreach ($this->db['customers'] as &$c) if ($c['id'] === $existing['id']) { $c = $fields + $c; $new = $c; }
            unset($c);
        } else {
            $new = ['id' => $this->nextId('customers'), 'phone' => $phone, 'status' => 'active', 'addresses' => [], 'created_at' => $this->now()] + $fields;
            $this->db['customers'][] = $new;
        }
        foreach ($this->db['orders'] as &$o) if ($o['contact']['phone'] === $phone) $o['customer_id'] = $new['id'];
        return self::status(201, ['token' => $this->startSession('customer', $new['id'], ['via' => 'password']), 'customer' => $this->meView($new)]);
    }

    /** Signed in with a phone code as a guest: add a password to become a member. Also "change password". */
    private function setMyPassword(): array
    {
        $me = $this->customer() ?? $this->fail(403, 'Sign in with your phone number to set a password.');
        if (mb_strlen((string) $this->in('password')) < 8) $this->fail(422, 'Check the highlighted fields', ['password' => 'Use at least 8 characters']);
        foreach ($this->db['customers'] as &$c) if ($c['id'] === $me['id']) { $c['has_password'] = true; $me = $c; }
        unset($c);
        foreach ($this->db['orders'] as &$o) if ($o['contact']['phone'] === $me['phone']) $o['customer_id'] = $me['id'];
        return ['ok' => true, 'customer' => $this->meView($me)];
    }

    private function logout(): array
    {
        $t = $this->bearer();
        $this->db['sessions'] = array_values(array_filter($this->db['sessions'], fn($s) => $s['token'] !== $t));
        return self::status(200, ['ok' => true]);
    }

    private function meView(array $c): array
    {
        return ['id' => $c['id'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'], 'phone' => $c['phone'], 'email' => $c['email'],
                'has_password' => $c['has_password'], 'signed_in_with' => $this->session('customer')['via'] ?? 'password', 'created_at' => $c['created_at']];
    }

    private function emailMeView(string $email): array
    {
        $last = null;
        foreach ($this->db['orders'] as $o) if (strcasecmp((string) $o['contact']['email'], $email) === 0 && (!$last || $o['created_at'] > $last['created_at'])) $last = $o;
        return ['id' => null, 'first_name' => $last['contact']['first_name'] ?? null, 'last_name' => null, 'phone' => null, 'email' => $email,
                'has_password' => false, 'signed_in_with' => 'email_code', 'created_at' => null];
    }

    private function getMe(): array
    {
        $me = $this->customer();
        return $me ? $this->meView($me) : $this->emailMeView($this->session('customer')['email']);
    }

    private function putMe(): array
    {
        $me = $this->customer() ?? $this->fail(403, 'Sign in with your phone number to edit your details.');
        foreach ($this->db['customers'] as &$c) {
            if ($c['id'] === $me['id']) {
                foreach (['first_name', 'last_name', 'email'] as $f) if (isset($this->body[$f])) $c[$f] = trim((string) $this->body[$f]) ?: null;
                return $this->meView($c);
            }
        }
        $this->fail(404, 'Not found');
    }

    private function myOrders(): array
    {
        // Account (phone proven at sign-up or by code): its orders and any order with its phone.
        // Email-code session: only orders placed with that email. Emails aren't verified at checkout,
        // so an email never opens an account and an account never claims orders by email.
        $me = $this->customer();
        $email = $this->session('customer')['email'] ?? null;
        $rows = array_filter($this->db['orders'], fn($o) => ($me && ($o['customer_id'] === $me['id'] || $o['contact']['phone'] === $me['phone']))
            || ($email && strcasecmp((string) $o['contact']['email'], $email) === 0));
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $rows;
    }

    private function getMyOrders(): array
    {
        return array_map(function ($o) {
            $v = $this->orderView($o);
            return ['number' => $v['number'], 'created_at' => $v['created_at'], 'status' => $v['status'], 'payment_status' => $v['payment_status'],
                    'total' => $v['total'], 'item_count' => array_sum(array_column($v['items'], 'qty')), 'access_token' => $o['access_token']];
        }, $this->myOrders());
    }

    private function getMyOrder(string $number): array
    {
        foreach ($this->myOrders() as $o) if ($o['number'] === $number) return $this->orderView($o);
        $this->fail(404, 'Order not found');
    }

    private function getMyAddresses(): array
    {
        return array_map(fn($a) => $a + ['zone_name' => $this->zone($a['zone_id'])['name'] ?? null], $this->customer()['addresses'] ?? []);
    }

    // ------------------------------------------------------------------ admin

    private function adminLogin(): array
    {
        $email = strtolower(trim((string) $this->in('email')));
        $s = $this->find('staff', $email, 'email');
        $this->loginGuard('staff', $email, $s && $this->in('password') === $this->config['demo_password'], 'Wrong email or password');
        if ($s['status'] !== 'active') $this->fail(403, 'This staff account is disabled');
        foreach ($this->db['staff'] as &$row) if ($row['id'] === $s['id']) $row['last_login_at'] = $this->now();
        return self::status(200, ['token' => $this->startSession('staff', $s['id']), 'staff' => $this->adminMeView($s)]);
    }

    private function adminMeView(array $s): array
    {
        $role = $this->find('roles', $s['role'], 'name');
        return ['id' => $s['id'], 'name' => $s['first_name'] . ' ' . $s['last_name'], 'email' => $s['email'], 'role' => $s['role'],
                'permissions' => $role['permissions'] ?? [], 'stores' => [['id' => 1, 'name' => $this->db['store']['name']]], 'demo' => true];
    }

    private function adminMe(): array { return $this->adminMeView($this->staff()); }

    private function adminDashboard(): array
    {
        $today = date('Y-m-d');
        $views = array_map(fn($o) => $this->orderView($o), $this->db['orders']);
        $todays = array_filter($views, fn($o) => str_starts_with($o['created_at'], $today) && $o['status'] !== 'cancelled');
        $open = array_filter($views, fn($o) => !in_array($o['status'], ['delivered', 'collected', 'cancelled'], true));
        $low = [];
        foreach ($this->listed() as $p) foreach ($p['variants'] as $v) if ($v['stock'] <= 2) $low[] = ['product' => $p['name'], 'size_label' => $v['size_label'], 'sku' => $v['sku'], 'stock' => $v['stock'], 'product_id' => $p['id']];
        usort($low, fn($a, $b) => $a['stock'] <=> $b['stock']);
        $series = [];
        for ($d = 13; $d >= 0; $d--) {
            $day = date('Y-m-d', strtotime("-$d days"));
            $rows = array_filter($views, fn($o) => str_starts_with($o['created_at'], $day) && $o['status'] !== 'cancelled');
            $series[] = ['date' => $day, 'orders' => count($rows), 'revenue' => array_sum(array_column($rows, 'total'))];
        }
        $byProduct = [];
        foreach ($views as $o) if ($o['status'] !== 'cancelled') foreach ($o['items'] as $i) {
            $byProduct[$i['product_name']] = ($byProduct[$i['product_name']] ?? 0) + $i['line_total'];
        }
        arsort($byProduct);
        usort($views, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return [
            'today' => ['orders' => count($todays), 'revenue' => array_sum(array_column($todays, 'total'))],
            'awaiting_confirmation' => count(array_filter($open, fn($o) => $o['status'] === 'placed')),
            'same_day_due' => count(array_filter($open, fn($o) => $o['preferred_date'] <= $today && $o['fulfilment'] === 'delivery')),
            // Strictly 'unpaid', the same rule as the list the KPI links to. Pending transfers have
            // their own "payments to check" count, and part-paid orders their own filter.
            'unpaid_open' => ['count' => count($u = array_filter($open, fn($o) => $o['payment_status'] === 'unpaid')), 'amount' => array_sum(array_column($u, 'total'))],
            'pending_transfers' => count(array_filter($views, fn($o) => $o['payment_status'] === 'pending')),
            'new_messages' => count(array_filter($this->db['contact_messages'], fn($m) => $m['status'] === 'new')),
            'pending_reviews' => count(array_filter($this->db['reviews'], fn($r) => $r['status'] === 'pending')),
            'low_stock' => array_slice($low, 0, 8), 'low_stock_count' => count($low),
            'series' => $series,
            'top_products' => array_map(fn($n, $v) => ['name' => $n, 'revenue' => $v], array_slice(array_keys($byProduct), 0, 5), array_slice(array_values($byProduct), 0, 5)),
            'recent_orders' => array_map(fn($o) => array_intersect_key($o, array_flip(['id', 'number', 'created_at', 'status', 'payment_status', 'total', 'fulfilment', 'channel'])) + ['customer' => trim($o['contact']['first_name'] . ' ' . $o['contact']['last_name'])], array_slice($views, 0, 8)),
        ];
    }

    private function adminStores(): array
    {
        return [['id' => 1, 'name' => $this->db['store']['name'], 'slug' => $this->db['store']['slug'], 'status' => $this->db['store']['status'],
                 'primary_domain' => array_values(array_filter($this->db['store']['domains'], fn($d) => $d['is_primary']))[0]['domain'] ?? null]];
    }

    private function adminStore(string $id): array
    {
        return $this->db['store'];
    }

    private function adminUpdateStore(string $id): array
    {
        $allowed = ['name', 'tagline', 'logo', 'favicon', 'theme', 'currency', 'timezone', 'same_day_cutoff', 'order_prefix', 'announcement',
                    'support_hours', 'seo', 'facebook_pixel_id', 'ga4_id', 'dispatch_location_id', 'status'];
        $errors = [];
        if (isset($this->body['name']) && trim($this->body['name']) === '') $errors['name'] = 'Store name is required';
        if (isset($this->body['same_day_cutoff']) && !preg_match('/^\d{2}:\d{2}$/', $this->body['same_day_cutoff'])) $errors['same_day_cutoff'] = 'Use HH:MM';
        if (isset($this->body['order_prefix']) && !preg_match('/^[A-Z]{1,5}$/', $this->body['order_prefix'])) $errors['order_prefix'] = '1–5 capital letters';
        if ($errors) $this->fail(422, 'Check the highlighted fields', $errors);
        foreach ($allowed as $k) if (array_key_exists($k, $this->body)) $this->db['store'][$k] = $this->body[$k];
        $this->audit('store', 1, 'update', array_intersect_key($this->body, array_flip($allowed)));
        return $this->db['store'];
    }

    private function audit(string $entity, int $id, string $action, array $changes): void
    {
        $this->db['audit'][] = ['id' => $this->nextId('audit'), 'staff_id' => $this->staff()['id'] ?? null, 'entity' => $entity, 'entity_id' => $id,
                                'action' => $action, 'changes' => $changes ?: (object) [], 'created_at' => $this->now()];
    }

    private function completeness(array $p): array
    {
        $issues = [];
        if ($p['category_id'] === 1) {
            if (!$p['type']) $issues[] = 'Mattress type is missing';
            if (!$p['firmness']) $issues[] = 'Firmness is missing';
            $miss = count(array_filter($p['variants'], fn($v) => $v['status'] === 'active' && !$v['max_load_kg']));
            if ($miss) $issues[] = "Max load missing on $miss size" . ($miss > 1 ? 's' : '');
        }
        if (!array_filter($p['variants'], fn($v) => $v['status'] === 'active')) $issues[] = 'No active sizes';
        if (mb_strlen($p['description'] ?? '') < 40) $issues[] = 'Description is too short';
        $warn = [];
        if (!$p['media']) $warn[] = 'No photos';
        elseif ($noAlt = count(array_filter($p['media'], fn($m) => empty($m['alt'])))) $warn[] = "$noAlt photo" . ($noAlt > 1 ? 's have' : ' has') . ' no alt text';
        return ['blocking' => $issues, 'warnings' => $warn];
    }

    private function adminProductRow(array $p): array
    {
        $vs = array_filter($p['variants'], fn($v) => $v['status'] === 'active');
        $prices = array_column($vs, 'price') ?: [0];
        return ['id' => $p['id'], 'name' => $p['name'], 'slug' => $p['slug'], 'brand' => $this->brand($p['brand_id'])['name'],
                'category' => $this->category($p['category_id'])['name'], 'type' => $p['type'], 'firmness' => $p['firmness'],
                'status' => $p['status'], 'listed' => $p['listing']['listed'], 'featured' => $p['listing']['featured'],
                'sizes' => count($vs), 'price_from' => min($prices), 'price_to' => max($prices), 'stock' => array_sum(array_column($vs, 'stock')),
                'out_of_stock_sizes' => count(array_filter($vs, fn($v) => $v['stock'] <= 0)), 'views' => $p['listing']['view_count'],
                'completeness' => $this->completeness($p)];
    }

    private function adminProducts(): array
    {
        $q = $this->query;
        $rows = $this->db['products'];
        if (!empty($q['q'])) { $n = mb_strtolower($q['q']); $rows = array_filter($rows, fn($p) => str_contains(mb_strtolower($p['name'] . ' ' . implode(' ', array_column($p['variants'], 'sku'))), $n)); }
        if (!empty($q['brand'])) $rows = array_filter($rows, fn($p) => (string) $p['brand_id'] === (string) $q['brand']);
        if (!empty($q['category'])) $rows = array_filter($rows, fn($p) => (string) $p['category_id'] === (string) $q['category']);
        if (!empty($q['status'])) $rows = array_filter($rows, fn($p) => $p['status'] === $q['status']);
        $rows = array_map(fn($p) => $this->adminProductRow($p), $rows);
        if (!empty($q['issues'])) $rows = array_filter($rows, fn($r) => $r['completeness']['blocking']);
        usort($rows, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $this->paginate(array_values($rows), 50);
    }

    private function &productRef(int $id): array
    {
        foreach ($this->db['products'] as &$p) if ($p['id'] === $id) return $p;
        unset($p);
        $this->fail(404, 'Product not found');
    }

    private function adminProduct(string $id): array
    {
        $p = $this->productRef((int) $id);
        return $p + ['completeness' => $this->completeness($p), 'brand' => $this->brand($p['brand_id'])['name'], 'category' => $this->category($p['category_id'])['name']];
    }

    private function applyProductFields(array &$p): void
    {
        foreach (['name', 'slug', 'brand_id', 'category_id', 'type', 'firmness', 'density', 'materials', 'cover', 'warranty_years', 'description', 'good_for', 'specs'] as $f) {
            if (!array_key_exists($f, $this->body)) continue;
            $v = $this->body[$f];
            if (in_array($f, ['brand_id', 'category_id', 'firmness', 'warranty_years'], true)) $v = $v === '' || $v === null ? null : (int) $v;
            elseif (is_string($v)) $v = trim($v) === '' ? null : trim($v);
            $p[$f] = $v;
        }
        if (isset($this->body['media']) && is_array($this->body['media'])) {
            $in = $this->body['media'];
            $p['media'] = array_values(array_filter(array_map(function ($m) use ($in) {
                $x = $in[$m['id']] ?? $in[(string) $m['id']] ?? null;
                if ($x === null) return $m;
                if (!empty($x['remove'])) return null;
                if (array_key_exists('alt', $x)) $m['alt'] = trim((string) $x['alt']) ?: null;
                if (isset($x['sort'])) $m['sort'] = (int) $x['sort'];
                return $m;
            }, $p['media'])));
            usort($p['media'], fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0));
        }
        if (isset($this->body['faqs']) && is_array($this->body['faqs'])) {
            $p['faqs'] = array_values(array_filter(array_map(fn($f) => ['question' => trim((string) ($f['question'] ?? '')), 'answer' => trim((string) ($f['answer'] ?? ''))], $this->body['faqs']), fn($f) => $f['question'] !== '' && $f['answer'] !== ''));
        }
        if (isset($this->body['listing']) && is_array($this->body['listing'])) {
            foreach (['listed', 'featured', 'new_arrival'] as $f) if (isset($this->body['listing'][$f])) $p['listing'][$f] = (bool) $this->body['listing'][$f];
            foreach (['name_override', 'description_override'] as $f) if (array_key_exists($f, $this->body['listing'])) $p['listing'][$f] = trim((string) $this->body['listing'][$f]) ?: null;
            if (isset($this->body['listing']['seo'])) $p['listing']['seo'] = $this->body['listing']['seo'];
        }
        if (isset($this->body['variants']) && is_array($this->body['variants'])) {
            $nextVid = max(array_keys($this->variantIndex())) + 1;
            $byId = [];
            foreach ($p['variants'] as $v) $byId[$v['id']] = $v;
            $out = [];
            foreach (array_values($this->body['variants']) as $sort => $in) {
                if (empty($in['size_label']) && empty($in['id'])) continue;
                $v = isset($in['id']) && isset($byId[(int) $in['id']]) ? $byId[(int) $in['id']] : ['id' => $nextVid++, 'barcode' => null, 'weight_kg' => null, 'stock' => 0];
                foreach (['sku', 'size_label'] as $f) if (isset($in[$f])) $v[$f] = trim($in[$f]);
                foreach (['width_ft', 'length_ft', 'thickness_in'] as $f) if (array_key_exists($f, $in)) $v[$f] = $in[$f] === '' ? null : (float) $in[$f];
                foreach (['max_load_kg', 'price', 'compare_at_price'] as $f) if (array_key_exists($f, $in)) $v[$f] = $in[$f] === '' ? null : (int) $in[$f];
                $v['status'] = !empty($in['remove']) ? 'inactive' : ($in['status'] ?? $v['status'] ?? 'active');
                $v['sku'] = ($v['sku'] ?? '') ?: strtoupper(substr($this->brand($p['brand_id'])['slug'], 0, 3)) . '-' . $v['id'];
                $v['sort'] = $sort;
                $v += ['width_ft' => null, 'length_ft' => null, 'thickness_in' => null, 'max_load_kg' => null, 'compare_at_price' => null, 'price' => 0];
                $out[] = $v;
            }
            $p['variants'] = $out;
        }
    }

    /**
     * Adds a photo. Body: {data: base64, alt, filename?}. The file is saved under a descriptive name
     * (from `filename`, else {product-slug}-nigeria), never the camera's IMG_1234.jpg.
     */
    private function adminUploadMedia(string $id): array
    {
        $p = &$this->productRef((int) $id);
        $bin = base64_decode((string) $this->in('data'), true);
        $info = $bin ? @getimagesizefromstring($bin) : false;
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
        if (!$ext) $this->fail(422, 'Upload a JPG, PNG or WebP photo', ['data' => 'Upload a JPG, PNG or WebP photo']);
        if (strlen($bin) > 8 * 1024 * 1024) $this->fail(422, 'Photos must be under 8 MB', ['data' => 'Photos must be under 8 MB']);
        $alt = trim((string) $this->in('alt')) ?: null;
        // e.g. vitafoam-galaxy-orthopedic-mattress-nigeria.jpg, then -2, -3 for further photos
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(preg_replace('/\.(jpe?g|png|webp)$/i', '', (string) $this->in('filename')))), '-') ?: $p['slug'] . '-nigeria';
        $base = strlen($base) > 80 ? substr($base, 0, strrpos(substr($base, 0, 80), '-') ?: 80) : $base;
        $dir = $this->config['uploads_dir'] ?? dirname(__DIR__) . '/assets/uploads';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = "$base.$ext";
        for ($n = 2; is_file("$dir/$name"); $n++) $name = "$base-$n.$ext";
        file_put_contents("$dir/$name", $bin);
        $m = ['id' => max([0, ...array_column($p['media'], 'id')]) + 1, 'url' => rtrim($this->config['uploads_url'] ?? '/assets/uploads', '/') . "/$name",
              'filename' => $name, 'alt' => $alt, 'width' => $info[0], 'height' => $info[1], 'sort' => count($p['media'])];
        $p['media'][] = $m;
        $this->audit('product', $p['id'], 'media.add', ['file' => $name]);
        return self::status(201, $m);
    }

    private function adminCreateProduct(): array
    {
        if (trim((string) $this->in('name')) === '') $this->fail(422, 'Name is required', ['name' => 'Name is required']);
        $p = ['id' => $this->nextId('products'), 'slug' => '', 'name' => '', 'brand_id' => 1, 'category_id' => 1, 'type' => null, 'firmness' => null,
              'density' => null, 'materials' => null, 'cover' => null, 'warranty_years' => null, 'good_for' => [], 'description' => '', 'specs' => [],
              'status' => 'draft', 'listing' => ['listed' => false, 'featured' => false, 'new_arrival' => true, 'sort' => 999, 'view_count' => 0,
                                                 'name_override' => null, 'description_override' => null, 'seo' => null],
              'media' => [], 'variants' => [], 'created_at' => $this->now()];
        $this->applyProductFields($p);
        $p['slug'] = $p['slug'] ?: trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($p['name'])), '-');
        if ($this->find('products', $p['slug'], 'slug')) $this->fail(422, 'That URL slug is taken', ['slug' => 'That URL slug is taken']);
        $this->db['products'][] = $p;
        $this->audit('product', $p['id'], 'create', ['name' => $p['name']]);
        return $this->adminProduct((string) $p['id']);
    }

    private function adminUpdateProduct(string $id): array
    {
        $p = &$this->productRef((int) $id);
        $before = array_column($p['variants'], 'price', 'id');
        $this->applyProductFields($p);
        $changes = [];
        foreach ($p['variants'] as $v) if (isset($before[$v['id']]) && $before[$v['id']] !== $v['price']) $changes["price.{$v['sku']}"] = [$before[$v['id']], $v['price']];
        if ($p['status'] === 'active' && ($c = $this->completeness($p)['blocking'])) {
            $p['status'] = 'draft';
            $p['listing']['listed'] = false;
            $changes['status'] = ['active', 'draft'];
        }
        $this->audit('product', $p['id'], 'update', $changes);
        unset($p);
        return $this->adminProduct($id);
    }

    private function adminArchiveProduct(string $id): array
    {
        $p = &$this->productRef((int) $id);
        $p['status'] = 'archived';
        $p['listing']['listed'] = false;
        return ['id' => $p['id'], 'status' => 'archived'];
    }

    private function adminPublishProduct(string $id): array
    {
        $p = &$this->productRef((int) $id);
        $c = $this->completeness($p);
        if ($c['blocking']) $this->fail(422, "Can't publish yet", ['completeness' => $c['blocking']]);
        $p['status'] = 'active';
        $p['listing']['listed'] = true;
        $this->audit('product', $p['id'], 'publish', []);
        return ['id' => $p['id'], 'status' => 'active', 'listed' => true];
    }

    private function moveStock(int $variantId, int $change, string $reason, ?int $orderId = null, ?int $staffId = null, ?string $note = null): void
    {
        foreach ($this->db['products'] as &$p) foreach ($p['variants'] as &$v) {
            if ($v['id'] === $variantId) {
                $v['stock'] += $change;
                $this->db['inventory_movements'][] = ['id' => $this->nextId('inventory_movements'), 'location_id' => $this->db['store']['dispatch_location_id'],
                    'variant_id' => $variantId, 'change' => $change, 'reason' => $reason, 'order_id' => $orderId, 'staff_id' => $staffId, 'note' => $note, 'created_at' => $this->now()];
                $this->dirty = true;
                return;
            }
        }
    }

    private function adminInventory(): array
    {
        $rows = [];
        foreach ($this->db['products'] as $p) {
            if ($p['status'] === 'archived') continue;
            foreach ($p['variants'] as $v) {
                if ($v['status'] !== 'active') continue;
                $rows[] = ['variant_id' => $v['id'], 'product_id' => $p['id'], 'product' => $p['name'], 'brand' => $this->brand($p['brand_id'])['name'],
                           'size_label' => $v['size_label'], 'sku' => $v['sku'], 'price' => $v['price'], 'on_hand' => $v['stock'], 'reorder_level' => 2,
                           'fulfilment' => 'stocked', 'value' => $v['stock'] * $v['price'], 'location' => $this->db['locations'][0]['name']];
            }
        }
        if (!empty($this->query['q'])) { $n = mb_strtolower($this->query['q']); $rows = array_filter($rows, fn($r) => str_contains(mb_strtolower($r['product'] . ' ' . $r['sku'] . ' ' . $r['size_label']), $n)); }
        if (!empty($this->query['low'])) $rows = array_filter($rows, fn($r) => $r['on_hand'] <= $r['reorder_level']);
        $res = $this->paginate(array_values($rows), 100);
        $res['stock_value'] = array_sum(array_column($rows, 'value'));
        $res['units'] = array_sum(array_column($rows, 'on_hand'));
        return $res;
    }

    private function adminAdjustStock(): array
    {
        $vid = (int) $this->in('variant_id');
        $change = (int) $this->in('change');
        $reason = (string) $this->in('reason', 'adjustment');
        if (!isset($this->variantIndex()[$vid])) $this->fail(422, 'Unknown variant', ['variant_id' => 'Unknown variant']);
        if ($change === 0) $this->fail(422, 'Enter a change other than 0', ['change' => 'Enter a change other than 0']);
        if (!in_array($reason, ['restock', 'adjustment', 'return'], true)) $this->fail(422, 'Choose a reason', ['reason' => 'Choose a reason']);
        $this->moveStock($vid, $change, $reason, null, $this->staff()['id'], trim((string) $this->in('note')) ?: null);
        return ['variant_id' => $vid, 'on_hand' => $this->variantIndex()[$vid][1]['stock']];
    }

    private function adminMovements(): array
    {
        $idx = $this->variantIndex();
        $rows = $this->db['inventory_movements'];
        if (!empty($this->query['variant_id'])) $rows = array_filter($rows, fn($m) => $m['variant_id'] === (int) $this->query['variant_id']);
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']) ?: $b['id'] <=> $a['id']);
        $rows = array_map(fn($m) => $m + ['product' => $idx[$m['variant_id']][0]['name'] ?? '?', 'size_label' => $idx[$m['variant_id']][1]['size_label'] ?? '?',
                                          'sku' => $idx[$m['variant_id']][1]['sku'] ?? '?', 'staff_name' => $this->staffName($m['staff_id']),
                                          'order_number' => $m['order_id'] ? ($this->find('orders', $m['order_id'])['number'] ?? null) : null], $rows);
        return $this->paginate(array_values($rows), 50);
    }

    // imports ----------------------------------------------------------

    private function adminImportTemplate(): array
    {
        $rows = $this->adminExport();
        return ['columns' => self::IMPORT_COLUMNS, 'rows' => array_slice($rows['rows'], 0, 3),
                'notes' => ['One row per size (variant). Rows with the same product_slug make one product.', 'sku is the key: an existing sku is updated, a new sku is created.',
                            'brand and category are matched by name and created if new.', 'Mattresses need type, firmness and max_load_kg to be published.']];
    }

    private function adminExport(): array
    {
        $rows = [];
        foreach ($this->db['products'] as $p) {
            if ($p['status'] === 'archived') continue;
            foreach ($p['variants'] as $v) {
                if ($v['status'] !== 'active') continue;
                $rows[] = ['product_name' => $p['name'], 'product_slug' => $p['slug'], 'brand' => $this->brand($p['brand_id'])['name'],
                           'category' => $this->category($p['category_id'])['name'], 'type' => $p['type'], 'firmness' => $p['firmness'],
                           'warranty_years' => $p['warranty_years'], 'description' => $p['description'], 'sku' => $v['sku'], 'size_label' => $v['size_label'],
                           'width_ft' => $v['width_ft'], 'length_ft' => $v['length_ft'], 'thickness_in' => $v['thickness_in'], 'max_load_kg' => $v['max_load_kg'],
                           'price' => $v['price'], 'compare_at_price' => $v['compare_at_price'], 'stock' => $v['stock'], 'image_urls' => implode('|', array_column($p['media'], 'url'))];
            }
        }
        return ['columns' => self::IMPORT_COLUMNS, 'rows' => $rows];
    }

    private function adminImportPreview(): array
    {
        $csv = (string) $this->in('csv', '');
        if (trim($csv) === '') $this->fail(422, 'Upload a CSV file', ['file' => 'Upload a CSV file']);
        // quoted fields may contain line breaks (product descriptions do), so read records, not lines
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $csv); rewind($fh);
        $records = [];
        while (($rec = fgetcsv($fh, 0, ',', '"', '')) !== false) if ($rec !== [null] && implode('', $rec) !== '') $records[] = $rec;
        fclose($fh);
        $head = array_map(fn($h) => strtolower(trim((string) $h, " \t\"\xEF\xBB\xBF")), array_shift($records) ?? []);
        $missing = array_diff(['product_name', 'sku', 'size_label', 'price'], $head);
        if ($missing) $this->fail(422, 'Missing columns: ' . implode(', ', $missing), ['file' => 'Missing columns: ' . implode(', ', $missing)]);
        $skus = [];
        foreach ($this->db['products'] as $p) foreach ($p['variants'] as $v) $skus[$v['sku']] = $p['slug'];
        $rows = [];
        $summary = ['create' => 0, 'update' => 0, 'reject' => [], 'new_brands' => [], 'new_categories' => [], 'new_products' => 0];
        $brandNames = array_map('strtolower', array_column($this->db['brands'], 'name'));
        $catNames = array_map('strtolower', array_column($this->db['categories'], 'name'));
        $newProducts = [];
        foreach ($records as $n => $vals) {
            $r = [];
            foreach ($head as $i => $h) $r[$h] = isset($vals[$i]) ? trim($vals[$i]) : '';
            $rowNo = $n + 2;
            $reasons = [];
            if ($r['product_name'] === '') $reasons[] = 'product_name is empty';
            if ($r['sku'] === '') $reasons[] = 'sku is empty';
            if (!is_numeric($r['price'] ?? '') || (float) $r['price'] <= 0) $reasons[] = 'price must be a number above 0';
            if (($r['firmness'] ?? '') !== '' && (!ctype_digit($r['firmness']) || $r['firmness'] < 1 || $r['firmness'] > 5)) $reasons[] = 'firmness must be 1–5';
            if (($r['stock'] ?? '') !== '' && !preg_match('/^-?\d+$/', $r['stock'])) $reasons[] = 'stock must be a whole number';
            $slug = $r['product_slug'] ?? '' ?: trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($r['product_name'])), '-');
            $action = isset($skus[$r['sku']]) ? 'update' : 'create';
            if ($reasons) { $summary['reject'][] = ['row' => $rowNo, 'sku' => $r['sku'], 'reasons' => $reasons]; $action = 'reject'; }
            else {
                $summary[$action]++;
                if (!empty($r['brand']) && !in_array(strtolower($r['brand']), $brandNames, true)) $summary['new_brands'][$r['brand']] = true;
                if (!empty($r['category']) && !in_array(strtolower($r['category']), $catNames, true)) $summary['new_categories'][$r['category']] = true;
                if ($action === 'create' && !$this->find('products', $slug, 'slug')) $newProducts[$slug] = true;
            }
            $warn = [];
            if ($action !== 'reject' && strtolower($r['category'] ?? '') === 'mattresses' && (($r['type'] ?? '') === '' || ($r['firmness'] ?? '') === '' || ($r['max_load_kg'] ?? '') === '')) $warn[] = 'Will stay draft: type, firmness and max_load_kg are needed to publish a mattress';
            $rows[] = ['row' => $rowNo, 'action' => $action, 'data' => $r + ['product_slug' => $slug], 'reasons' => $reasons, 'warnings' => $warn];
        }
        $summary['new_brands'] = array_keys($summary['new_brands']);
        $summary['new_categories'] = array_keys($summary['new_categories']);
        $summary['new_products'] = count($newProducts);
        $imp = ['id' => $this->nextId('imports'), 'filename' => (string) $this->in('filename', 'upload.csv'), 'status' => 'previewed',
                'staff_id' => $this->staff()['id'], 'summary' => $summary, 'rows' => $rows, 'created_at' => $this->now(), 'applied_at' => null];
        $this->db['imports'][] = $imp;
        return $imp;
    }

    private function adminImport(string $id): array
    {
        return $this->find('imports', (int) $id) ?? $this->fail(404, 'Import not found');
    }

    private function adminImportApply(string $id): array
    {
        $imp = null;
        foreach ($this->db['imports'] as $k => $i) if ($i['id'] === (int) $id) $imp = $k;
        if ($imp === null) $this->fail(404, 'Import not found');
        if ($this->db['imports'][$imp]['status'] !== 'previewed') $this->fail(409, 'This import was already applied');
        $applied = ['created' => 0, 'updated' => 0];
        foreach ($this->db['imports'][$imp]['rows'] as $row) {
            if ($row['action'] === 'reject') continue;
            $r = $row['data'];
            $brandId = $this->ensureNamed('brands', $r['brand'] ?: 'Unbranded');
            $catId = $this->ensureNamed('categories', $r['category'] ?: 'Mattresses');
            $pk = null;
            foreach ($this->db['products'] as $k => $p) if ($p['slug'] === $r['product_slug']) $pk = $k;
            if ($pk === null) {
                $this->db['products'][] = ['id' => $this->nextId('products'), 'slug' => $r['product_slug'], 'name' => $r['product_name'], 'brand_id' => $brandId,
                    'category_id' => $catId, 'type' => null, 'firmness' => null, 'density' => null, 'materials' => null, 'cover' => null, 'warranty_years' => null,
                    'good_for' => [], 'description' => '', 'specs' => [], 'status' => 'draft',
                    'listing' => ['listed' => false, 'featured' => false, 'new_arrival' => true, 'sort' => 999, 'view_count' => 0, 'name_override' => null, 'description_override' => null, 'seo' => null],
                    'media' => [], 'variants' => [], 'created_at' => $this->now()];
                $pk = array_key_last($this->db['products']);
            }
            $p = &$this->db['products'][$pk];
            $p['name'] = $r['product_name']; $p['brand_id'] = $brandId; $p['category_id'] = $catId;
            foreach (['type' => 'type', 'description' => 'description'] as $col => $f) if (($r[$col] ?? '') !== '') $p[$f] = $r[$col];
            foreach (['firmness', 'warranty_years'] as $f) if (($r[$f] ?? '') !== '') $p[$f] = (int) $r[$f];
            $vk = null;
            foreach ($p['variants'] as $k => $v) if ($v['sku'] === $r['sku']) $vk = $k;
            $vals = ['sku' => $r['sku'], 'size_label' => $r['size_label'], 'price' => (int) $r['price'],
                     'compare_at_price' => ($r['compare_at_price'] ?? '') !== '' ? (int) $r['compare_at_price'] : null,
                     'width_ft' => ($r['width_ft'] ?? '') !== '' ? (float) $r['width_ft'] : null, 'length_ft' => ($r['length_ft'] ?? '') !== '' ? (float) $r['length_ft'] : null,
                     'thickness_in' => ($r['thickness_in'] ?? '') !== '' ? (float) $r['thickness_in'] : null, 'max_load_kg' => ($r['max_load_kg'] ?? '') !== '' ? (int) $r['max_load_kg'] : null, 'status' => 'active'];
            if ($vk === null) {
                $p['variants'][] = $vals + ['id' => max(array_keys($this->variantIndex()) ?: [0]) + 1 + $applied['created'], 'barcode' => null, 'weight_kg' => null, 'stock' => (int) ($r['stock'] ?? 0), 'sort' => count($p['variants'])];
                $applied['created']++;
            } else {
                $p['variants'][$vk] = $vals + $p['variants'][$vk];
                if (($r['stock'] ?? '') !== '') $p['variants'][$vk]['stock'] = (int) $r['stock'];
                $applied['updated']++;
            }
            if ($p['status'] === 'active' && $this->completeness($p)['blocking']) { $p['status'] = 'draft'; $p['listing']['listed'] = false; }
            unset($p);
        }
        $this->db['imports'][$imp]['status'] = 'applied';
        $this->db['imports'][$imp]['applied_at'] = $this->now();
        $this->audit('import', (int) $id, 'apply', $applied);
        return ['id' => (int) $id, 'status' => 'applied'] + $applied;
    }

    private function ensureNamed(string $key, string $name): int
    {
        foreach ($this->db[$key] as $row) if (strtolower($row['name']) === strtolower($name)) return $row['id'];
        $id = $this->nextId($key);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        $this->db[$key][] = $key === 'brands'
            ? ['id' => $id, 'name' => $name, 'slug' => $slug, 'logo' => null, 'color' => '#555555', 'description' => null, 'warranty_note' => null]
            : ['id' => $id, 'parent_id' => null, 'name' => $name, 'slug' => $slug, 'sort' => $id, 'image' => null, 'description' => null];
        return $id;
    }

    // orders -----------------------------------------------------------

    private function adminOrders(): array
    {
        $q = $this->query;
        $rows = array_map(fn($o) => $this->orderView($o), $this->db['orders']);
        if (!empty($q['status'])) {
            $rows = $q['status'] === 'open'
                ? array_filter($rows, fn($o) => !in_array($o['status'], ['delivered', 'collected', 'cancelled'], true))
                : array_filter($rows, fn($o) => $o['status'] === $q['status']);
        }
        foreach (['payment_status', 'channel', 'fulfilment'] as $f) if (!empty($q[$f])) $rows = array_filter($rows, fn($o) => $o[$f] === $q[$f]);
        if (!empty($q['date'])) $rows = array_filter($rows, fn($o) => str_starts_with($o['created_at'], $q['date'] === 'today' ? date('Y-m-d') : $q['date']));
        if (!empty($q['due'])) $rows = array_filter($rows, fn($o) => $o['preferred_date'] <= date('Y-m-d') && !in_array($o['status'], ['delivered', 'collected', 'cancelled'], true));
        if (!empty($q['zone'])) $rows = array_filter($rows, fn($o) => (string) ($o['location']['zone_id'] ?? '') === (string) $q['zone']);
        if (!empty($q['q'])) {
            $rows = array_filter($rows, fn($o) => self::searchHit($q['q'], $o['number'] . ' ' . $o['contact']['first_name'] . ' ' . $o['contact']['last_name'] . ' ' . $o['contact']['phone'], [$o['contact']['phone']]));
        }
        usort($rows, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        $res = $this->paginate(array_values(array_map(fn($o) => [
            'id' => $o['id'], 'number' => $o['number'], 'created_at' => $o['created_at'], 'customer' => trim($o['contact']['first_name'] . ' ' . $o['contact']['last_name']),
            'phone' => $o['contact']['phone'], 'status' => $o['status'], 'payment_status' => $o['payment_status'], 'payment_method' => $o['payment_method']['type'],
            'fulfilment' => $o['fulfilment'], 'zone' => $o['location']['zone_name'], 'preferred_date' => $o['preferred_date'], 'time_slot' => $o['time_slot'],
            'channel' => $o['channel'], 'items' => array_sum(array_column($o['items'], 'qty')), 'total' => $o['total'],
        ], $rows)), 25);
        $res['totals'] = ['value' => array_sum(array_column($rows, 'total'))];
        return $res;
    }

    private function adminOrder(string $id): array
    {
        $o = $this->find('orders', (int) $id) ?? $this->fail(404, 'Order not found');
        $v = $this->orderView($o, true);
        $v['whatsapp_url'] = $this->whatsappLink($v);
        $v['other_orders'] = count(array_filter($this->db['orders'], fn($x) => $x['contact']['phone'] === $o['contact']['phone'] && $x['id'] !== $o['id']));
        return $v;
    }

    private function adminCreateOrder(): array
    {
        $items = array_values(array_filter(array_map(fn($i) => ['variant_id' => (int) ($i['variant_id'] ?? 0), 'qty' => (int) ($i['qty'] ?? 0)], (array) $this->in('items', [])), fn($i) => $i['variant_id'] && $i['qty'] > 0));
        $channel = in_array($this->in('channel'), ['phone', 'walk_in', 'whatsapp'], true) ? $this->in('channel') : 'phone';
        $order = $this->createOrder($items, $channel, $this->staff()['id']);
        return $this->adminOrder((string) $order['id']);
    }

    private function adminOrderStatus(string $id): array
    {
        $o = &$this->orderRef('id', $id);
        $view = $this->orderView($o, true);
        $to = (string) $this->in('status');
        if (!in_array($to, $view['next_statuses'], true)) $this->fail(422, "Can't move from {$view['status']} to $to", ['status' => 'Not an allowed next step']);
        $o['events'][] = ['status' => $to, 'note' => trim((string) $this->in('note')) ?: null, 'is_public' => (bool) $this->in('is_public', true),
                          'staff_id' => $this->staff()['id'], 'created_at' => $this->now()];
        if ($to === 'cancelled') foreach ($o['items'] as $i) $this->moveStock($i['variant_id'], $i['qty'], 'cancel', $o['id'], $this->staff()['id']);
        $tpl = ['confirmed' => 'order_confirmed', 'out_for_delivery' => 'out_for_delivery', 'ready_for_pickup' => 'ready_for_pickup', 'delivered' => 'delivered', 'cancelled' => 'order_cancelled'][$to] ?? null;
        if ($tpl && $this->in('notify', true)) {
            $this->db['outbox'][] = ['id' => $this->nextId('outbox'), 'channel' => 'sms', 'recipient' => $o['contact']['phone'], 'template' => $tpl,
                                     'status' => 'sent', 'attempts' => 1, 'last_error' => null, 'created_at' => $this->now(), 'sent_at' => $this->now()];
        }
        $this->audit('order', $o['id'], 'status', ['status' => [$view['status'], $to]]);
        unset($o);
        return $this->adminOrder($id);
    }

    private function adminOrderPayment(string $id): array
    {
        $o = &$this->orderRef('id', $id);
        $action = (string) $this->in('action', 'record');
        if ($action === 'confirm' || $action === 'reject') {
            foreach ($o['payments'] as &$p) {
                if ($p['id'] === (int) $this->in('payment_id')) { $p['status'] = $action === 'confirm' ? 'success' : 'failed'; $p['paid_at'] = $action === 'confirm' ? $this->now() : null; }
            }
            unset($p);
        } else {
            $amount = (float) $this->in('amount');
            if ($amount <= 0) $this->fail(422, 'Enter the amount received', ['amount' => 'Enter the amount received']);
            $o['payments'][] = ['id' => count($o['payments']) + 1, 'method' => (string) $this->in('method', 'pod'), 'reference' => trim((string) $this->in('reference')) ?: 'MAN_' . substr(md5((string) microtime(true)), 0, 8),
                                'amount' => $amount, 'status' => 'success', 'receipt' => null, 'paid_at' => $this->now()];
        }
        unset($o);
        return $this->adminOrder($id);
    }

    private function adminOrderNote(string $id): array
    {
        $o = &$this->orderRef('id', $id);
        $note = trim((string) $this->in('note'));
        if ($note === '') $this->fail(422, 'Write a note', ['note' => 'Write a note']);
        $o['internal_notes'][] = ['note' => $note, 'staff_id' => $this->staff()['id'], 'created_at' => $this->now()];
        unset($o);
        return $this->adminOrder($id);
    }

    // customers --------------------------------------------------------

    private function customerRow(array $c): array
    {
        $orders = array_filter($this->db['orders'], fn($o) => $o['contact']['phone'] === $c['phone']);
        $spent = 0;
        foreach ($orders as $o) if ($this->orderStatus($o) !== 'cancelled') $spent += $this->orderTotals($o)[1];
        $last = $orders ? max(array_column($orders, 'created_at')) : null;
        return ['id' => $c['id'], 'name' => trim($c['first_name'] . ' ' . $c['last_name']), 'first_name' => $c['first_name'], 'last_name' => $c['last_name'],
                'phone' => $c['phone'], 'email' => $c['email'], 'account' => $c['has_password'] ? 'member' : 'guest', 'status' => $c['status'],
                'orders' => count($orders), 'spent' => $spent, 'last_order_at' => $last, 'created_at' => $c['created_at']];
    }

    private function adminCustomers(): array
    {
        $rows = array_map(fn($c) => $this->customerRow($c), $this->db['customers']);
        if (!empty($this->query['q'])) $rows = array_filter($rows, fn($r) => self::searchHit($this->query['q'], $r['name'] . ' ' . $r['phone'] . ' ' . $r['email'], [$r['phone']]));
        if (!empty($this->query['account'])) $rows = array_filter($rows, fn($r) => $r['account'] === $this->query['account']);
        usort($rows, fn($a, $b) => $b['spent'] <=> $a['spent']);
        return $this->paginate(array_values($rows), 50);
    }

    private function adminCustomer(string $id): array
    {
        $c = $this->find('customers', (int) $id) ?? $this->fail(404, 'Customer not found');
        $orders = array_filter($this->db['orders'], fn($o) => $o['contact']['phone'] === $c['phone']);
        usort($orders, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $this->customerRow($c) + [
            'addresses' => array_map(fn($a) => $a + ['zone_name' => $this->zone($a['zone_id'])['name'] ?? null], $c['addresses']),
            'order_list' => array_map(function ($o) { $v = $this->orderView($o); return ['id' => $v['id'], 'number' => $v['number'], 'created_at' => $v['created_at'], 'status' => $v['status'], 'payment_status' => $v['payment_status'], 'total' => $v['total']]; }, $orders),
        ];
    }

    private function adminUpdateCustomer(string $id): array
    {
        foreach ($this->db['customers'] as &$c) {
            if ($c['id'] === (int) $id) {
                foreach (['first_name', 'last_name', 'email', 'status'] as $f) if (array_key_exists($f, $this->body)) $c[$f] = $this->body[$f] === '' ? null : $this->body[$f];
                unset($c);
                return $this->adminCustomer($id);
            }
        }
        $this->fail(404, 'Customer not found');
    }

    private function adminSalesReport(): array
    {
        $days = max(7, min(90, (int) ($this->query['days'] ?? 30)));
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $views = array_filter(array_map(fn($o) => $this->orderView($o), $this->db['orders']), fn($o) => $o['status'] !== 'cancelled' && substr($o['created_at'], 0, 10) >= $from);
        $group = function (callable $key) use ($views) {
            $g = [];
            foreach ($views as $o) foreach ((array) $key($o) as $k => $amt) { $g[$k]['revenue'] = ($g[$k]['revenue'] ?? 0) + $amt; $g[$k]['orders'] = ($g[$k]['orders'] ?? 0) + 1; }
            uasort($g, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
            return array_map(fn($k, $v) => ['key' => (string) $k] + $v, array_keys($g), $g);
        };
        $byDay = [];
        for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $byDay[$d] = ['date' => $d, 'orders' => 0, 'revenue' => 0]; }
        foreach ($views as $o) { $d = substr($o['created_at'], 0, 10); $byDay[$d]['orders']++; $byDay[$d]['revenue'] += $o['total']; }
        return [
            'from' => $from, 'to' => date('Y-m-d'), 'orders' => count($views), 'revenue' => array_sum(array_column($views, 'total')),
            'average_order' => count($views) ? round(array_sum(array_column($views, 'total')) / count($views)) : 0,
            'by_day' => array_values($byDay),
            'by_brand' => $group(function ($o) { $r = []; foreach ($o['items'] as $i) $r[$i['brand_name']] = ($r[$i['brand_name']] ?? 0) + $i['line_total']; return $r; }),
            'by_zone' => $group(fn($o) => [($o['location']['zone_name'] ?? 'Pickup') => $o['total']]),
            'by_channel' => $group(fn($o) => [$o['channel'] => $o['total']]),
            'by_payment' => $group(fn($o) => [$o['payment_method']['label'] => $o['total']]),
        ];
    }

    private function adminRetryOutbox(string $id): array
    {
        foreach ($this->db['outbox'] as &$m) if ($m['id'] === (int) $id) { $m['status'] = 'sent'; $m['attempts']++; $m['last_error'] = null; $m['sent_at'] = $this->now(); return $m; }
        $this->fail(404, 'Message not found');
    }

    private function adminClearCache(): array { return self::status(200, ['cleared' => true]); }

    private function adminReset(): array
    {
        $sessions = $this->db['sessions'];
        $this->reseed();
        $this->db['sessions'] = $sessions;
        return self::status(200, ['reset' => true]);
    }

    // generic collections ------------------------------------------------

    private function collection(string $method, string $name, ?int $id): array
    {
        [$key, $search] = self::COLLECTIONS[$name];
        $rows = &$this->ref($key);
        if ($method === 'GET' && $id === null) {
            $list = $rows;
            if (!empty($this->query['q'])) {
                $phoneFields = array_intersect($search, ['phone', 'recipient']);
                $list = array_filter($list, fn($r) => self::searchHit($this->query['q'], implode(' ', array_map(fn($f) => (string) ($r[$f] ?? ''), $search)), array_map(fn($f) => $r[$f] ?? null, $phoneFields)));
            }
            foreach (['status', 'placement', 'group_name', 'channel', 'entity', 'kind', 'city'] as $f) if (!empty($this->query[$f])) $list = array_filter($list, fn($r) => ($r[$f] ?? null) === $this->query[$f]);
            $list = array_values($list);
            if (isset($list[0]['created_at'])) usort($list, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
            elseif (isset($list[0]['sort'])) usort($list, fn($a, $b) => $a['sort'] <=> $b['sort']);
            $list = array_map(fn($r) => $this->decorate($name, $r), $list);
            return $this->paginate($list, 100);
        }
        $k = null;
        if ($id !== null) {
            foreach ($rows as $i => $r) if ($r['id'] === $id) $k = $i;
            if ($k === null) $this->fail(404, 'Not found');
        }
        switch ($method) {
            case 'GET':
                return $this->decorate($name, $rows[$k]);
            case 'POST':
                $row = ['id' => $this->nextId($key)] + $this->clean($this->body);
                if (in_array($name, ['messages', 'subscribers', 'comments', 'outbox', 'audit', 'reviews', 'posts'], true)) $row['created_at'] = $this->now();
                if ($name === 'posts') $row += ['tags' => [], 'view_count' => 0, 'author_id' => $this->staff()['id'], 'published_at' => ($row['status'] ?? '') === 'published' ? $this->now() : null];
                $rows[] = $row;
                $this->audit(rtrim($name, 's'), $row['id'], 'create', []);
                return $this->decorate($name, $row);
            case 'PUT':
                $before = $rows[$k];
                $rows[$k] = $this->clean($this->body) + $rows[$k];
                if ($name === 'posts' && ($rows[$k]['status'] ?? '') === 'published' && empty($rows[$k]['published_at'])) $rows[$k]['published_at'] = $this->now();
                $diff = [];
                foreach ($this->clean($this->body) as $f => $v) if (($before[$f] ?? null) !== $v && !is_array($v)) $diff[$f] = [$before[$f] ?? null, $v];
                $this->audit(rtrim($name, 's'), $id, 'update', $diff);
                return $this->decorate($name, $rows[$k]);
            case 'DELETE':
                if ($name === 'brands' && array_filter($this->db['products'], fn($p) => $p['brand_id'] === $id)) $this->fail(409, 'This brand has products. Move or archive them first.');
                if ($name === 'categories' && array_filter($this->db['products'], fn($p) => $p['category_id'] === $id)) $this->fail(409, 'This category has products. Move or archive them first.');
                array_splice($rows, $k, 1);
                $this->audit(rtrim($name, 's'), $id, 'delete', []);
                return ['deleted' => true];
        }
        $this->fail(405, 'Method not allowed');
    }

    private function clean(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if ($k === 'id') continue;
            if (is_string($v)) {
                $v = trim($v);
                if ($v === '') $v = null;
                elseif (preg_match('/^-?\d+$/', $v) && !in_array($k, ['phone', 'value', 'code', 'sku', 'recipient'], true)) $v = (int) $v;
                elseif ($v === 'true' || $v === 'false') $v = $v === 'true';
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function decorate(string $name, array $r): array
    {
        return match ($name) {
            'reviews'  => $r + ['product' => $this->find('products', $r['product_id'])['name'] ?? '?'],
            'comments' => $r + ['post' => $this->find('posts', $r['post_id'])['title'] ?? '?'],
            'brands'   => $r + ['products' => count(array_filter($this->db['products'], fn($p) => $p['brand_id'] === $r['id']))],
            'categories' => $r + ['products' => count(array_filter($this->db['products'], fn($p) => $p['category_id'] === $r['id']))],
            'audit'    => $r + ['staff_name' => $this->staffName($r['staff_id'])],
            'posts'    => $r + ['category' => $r['category_id'] ? ($this->find('post_categories', $r['category_id'])['name'] ?? null) : null],
            'coupons'  => $r + ['used' => count(array_filter($this->db['orders'], fn($o) => $o['coupon_code'] === $r['code'] && $this->orderStatus($o) !== 'cancelled'))],
            default    => $r,
        };
    }
}

final class MockHttpError extends \RuntimeException
{
    public function __construct(public int $status, public array $payload)
    {
        parent::__construct($payload['error'] ?? 'Error', $status);
    }
}
