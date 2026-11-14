<?php
/**
 * API client (storefront copy; the storefront and CMS each carry their own, so each repo stands alone).
 *
 * driver "http" → the backend at config api.base_url (main)
 * driver "mock" → mock/MockApi.php, reading and writing mock data, no backend needed (demo branch only)
 *
 * Both return the same shapes, so switching is one line in config/app.php.
 */
final class Api
{
    private static array $config;
    private array $headers = [];

    public function __construct(array $headers = [])
    {
        self::$config ??= require dirname(__DIR__, 2) . '/config/app.php';
        $this->headers = $headers + ['X-Store-Domain' => self::$config['api']['store_domain']];
    }

    public static function config(): array
    {
        return self::$config ??= require dirname(__DIR__, 2) . '/config/app.php';
    }

    public function withHeader(string $name, ?string $value): self
    {
        $c = clone $this;
        if ($value !== null && $value !== '') $c->headers[$name] = $value;
        return $c;
    }

    /** @return array{status:int,data:mixed} */
    public function request(string $method, string $path, array $query = [], array $body = []): array
    {
        $cfg = self::config();
        if ($cfg['api']['driver'] === 'mock') {
            $mock = dirname(__DIR__) . '/mock/MockApi.php';
            if (!is_file($mock)) throw new RuntimeException('api.driver is "mock" but mock/ is missing: it lives on the demo branch. Use "http" here.');
            require_once $mock;
            [$status, $data] = MockApi::instance($cfg['mock'])->handle($method, $path, $query, $body, $this->headers);
            // Round-trip through JSON so callers get exactly what the HTTP driver would give them.
            return ['status' => $status, 'data' => json_decode(json_encode($data), true)];
        }

        $url = rtrim($cfg['api']['base_url'], '/') . $path . ($query ? '?' . http_build_query($query) : '');
        $ch = curl_init($url);
        $h = ['Accept: application/json', 'Content-Type: application/json'];
        foreach ($this->headers as $k => $v) $h[] = "$k: $v";
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => $cfg['api']['timeout'],
        ]);
        if ($method !== 'GET' && $body) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) return ['status' => 503, 'data' => ['error' => 'The service is unavailable. Please try again.']];
        return ['status' => $status, 'data' => json_decode($raw, true)];
    }

    /** Returns data, or throws ApiError on 4xx/5xx. */
    public function call(string $method, string $path, array $query = [], array $body = []): mixed
    {
        $r = $this->request($method, $path, $query, $body);
        if ($r['status'] >= 400) throw new ApiError($r['status'], is_array($r['data']) ? $r['data'] : ['error' => 'Unexpected response']);
        return $r['data'];
    }

    public function get(string $path, array $query = []): mixed { return $this->call('GET', $path, $query); }
    public function post(string $path, array $body = []): mixed { return $this->call('POST', $path, [], $body); }
    public function put(string $path, array $body = []): mixed { return $this->call('PUT', $path, [], $body); }
    public function delete(string $path): mixed { return $this->call('DELETE', $path); }
}

final class ApiError extends \RuntimeException
{
    public function __construct(public int $status, public array $data)
    {
        parent::__construct($data['error'] ?? 'Request failed', $status);
    }

    public function fields(): array
    {
        return $this->data['fields'] ?? [];
    }
}
