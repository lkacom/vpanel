<?php

namespace App\Services;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

abstract class AbstractXUIService implements XUIServiceContract
{
    protected string $baseUrl;

    protected string $basePath;

    protected string $username;

    protected string $password;

    protected CookieJar $cookieJar;

    protected bool $isLoggedIn = false;

    public function __construct(string $host, string $username, string $password)
    {
        $normalizedHost = trim($host);
        if (! preg_match('/^https?:\/\//i', $normalizedHost)) {
            $normalizedHost = 'http://'.$normalizedHost;
        }

        $parsedUrl = parse_url(rtrim($normalizedHost, '/'));
        $scheme   = strtolower($parsedUrl['scheme'] ?? 'http');
        $hostname = $parsedUrl['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($hostname) || $hostname === '') {
            throw new \InvalidArgumentException('آدرس پنل X-UI معتبر نیست.');
        }

        $this->baseUrl  = $scheme . '://' . $hostname . (isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '');
        $this->basePath = $this->normalizePath($parsedUrl['path'] ?? '');
        $this->username = trim($username);
        $this->password = $password;
        $this->cookieJar = new CookieJar;

        Log::debug(static::class . ' constructed.', [
            'raw_host' => $host,
            'baseUrl'  => $this->baseUrl,
            'basePath' => $this->basePath,
        ]);
    }

    /**
     * مسیر پایه را نرمال‌سازی می‌کند.
     *
     * پنل‌های X-UI ممکن است با web-path امنیتی کار کنند مثلاً /panel یا /abc123
     * کاربر ممکن است آدرس کامل صفحه لاگین را وارد کند: host/panel/login
     * یا آدرس کامل API را: host/panel/api/...
     * در همه این حالت‌ها باید فقط web-path را نگه داریم.
     *
     * قوانین:
     * - /login و هر چیز بعد از آن حذف می‌شود
     * - /api/ و هر چیز بعد از آن حذف می‌شود
     * - /xui/ و هر چیز بعد از آن حذف می‌شود (پنل علیرضا)
     */
    private function normalizePath(string $rawPath): string
    {
        $path = rtrim($rawPath, '/');

        // اگر path شامل /login است، هر چیز از /login به بعد را حذف کن
        if (($pos = strpos($path, '/login')) !== false) {
            $path = substr($path, 0, $pos);
        }

        // اگر path شامل /api/ است، هر چیز از /api به بعد را حذف کن
        if (($pos = strpos($path, '/api/')) !== false) {
            $path = substr($path, 0, $pos);
        }

        // اگر path شامل /xui/ است (پنل علیرضا)
        if (($pos = strpos($path, '/xui/')) !== false) {
            $path = substr($path, 0, $pos);
        }

        return rtrim($path, '/');
    }

    protected function client(): PendingRequest
    {
        return Http::withOptions([
            'cookies'         => $this->cookieJar,
            'verify'          => false,
            'timeout'         => 30,
            'connect_timeout' => 10,
        ])->acceptJson();
    }

    /**
     * URL کامل برای یک path نسبی می‌سازد.
     * basePath + path
     *
     * مثال: basePath=/panel, path=/login → https://host:port/panel/login
     * مثال: basePath='',    path=/login → https://host:port/login
     */
    protected function url(string $path): string
    {
        return $this->baseUrl . $this->basePath . '/' . ltrim($path, '/');
    }

    protected function isSuccessfulResponse(Response $response): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $success = $response->json('success');

        return $success === true || $success === 1 || $success === '1' || $success === 'true';
    }

    /**
     * @return array<string, mixed>
     */
    protected function responsePayload(Response $response, string $fallbackMessage): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            $payload = [];
        }

        if (! array_key_exists('success', $payload)) {
            $payload['success'] = false;
        }

        if (empty($payload['msg'])) {
            $payload['msg'] = $fallbackMessage;
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function clientsFromInboundResponse(Response $response, int $inboundId): array
    {
        if (! $this->isSuccessfulResponse($response)) {
            Log::warning(static::class . ' could not retrieve inbound clients.', [
                'inbound_id' => $inboundId,
                'status'     => $response->status(),
                'message'    => $response->json('msg'),
            ]);

            return [];
        }

        $settings = $response->json('obj.settings', []);
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }

        if (! is_array($settings) || ! isset($settings['clients']) || ! is_array($settings['clients'])) {
            return [];
        }

        return array_values(array_filter($settings['clients'], 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array{client: array<string, mixed>, generated_uuid: string, generated_subId: string}
     */
    protected function newClientPayload(array $clientData): array
    {
        $uuid  = isset($clientData['id']) && is_string($clientData['id']) && $clientData['id'] !== ''
            ? $clientData['id']
            : Str::uuid()->toString();
        $subId = isset($clientData['subId']) && is_string($clientData['subId']) && $clientData['subId'] !== ''
            ? $clientData['subId']
            : Str::random(16);

        return [
            'client'          => $this->clientFields($clientData, [
                'id'    => $uuid,
                'subId' => $subId,
            ]),
            'generated_uuid'  => $uuid,
            'generated_subId' => $subId,
        ];
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @param  array<string, mixed>  $preserved
     * @return array<string, mixed>
     */
    protected function clientFields(array $clientData, array $preserved = []): array
    {
        $has    = static fn (string $key): bool => array_key_exists($key, $clientData) && $clientData[$key] !== null;
        $string = static fn (mixed $value): string => is_string($value) ? $value : (string) $value;

        $client                = $preserved;
        $client['id']          = $has('id') ? $string($clientData['id']) : ($client['id'] ?? '');
        $client['email']       = $has('email') ? $string($clientData['email']) : ($client['email'] ?? '');
        $client['totalGB']     = $has('total')
            ? max(0, (int) $clientData['total'])
            : ($has('totalGB') ? max(0, (int) $clientData['totalGB']) : (int) ($client['totalGB'] ?? 0));
        $client['expiryTime']  = $has('expiryTime') ? max(0, (int) $clientData['expiryTime']) : (int) ($client['expiryTime'] ?? 0);
        $client['enable']      = $has('enable') ? (bool) $clientData['enable'] : (bool) ($client['enable'] ?? true);
        $client['tgId']        = $has('tgId') ? (int) $clientData['tgId'] : (int) ($client['tgId'] ?? 0);
        $client['subId']       = $has('subId') ? $string($clientData['subId']) : ($client['subId'] ?? '');
        $client['limitIp']     = $has('limitIp') ? max(0, (int) $clientData['limitIp']) : (int) ($client['limitIp'] ?? 0);
        $client['limitHwid']   = $has('limitHwid') ? max(0, (int) $clientData['limitHwid']) : (int) ($client['limitHwid'] ?? 0);
        $client['flow']        = $has('flow') ? $string($clientData['flow']) : ($client['flow'] ?? '');
        $client['comment']     = $has('comment') ? $string($clientData['comment']) : ($client['comment'] ?? '');
        $client['reset']       = $has('reset') ? max(0, (int) $clientData['reset']) : (int) ($client['reset'] ?? 0);
        $client['resetDay']    = $has('resetDay') ? max(0, (int) $clientData['resetDay']) : (int) ($client['resetDay'] ?? 0);
        $client['resetMax']    = $has('resetMax') ? max(0, (int) $clientData['resetMax']) : (int) ($client['resetMax'] ?? 0);
        $client['security']    = $has('security') ? $string($clientData['security']) : ($client['security'] ?? '');

        return $client;
    }

    /**
     * @param  array<int, array<string, mixed>>  $clients
     * @param  array<string, mixed>              $clientData
     * @return array<string, mixed>|null
     */
    protected function findClient(array $clients, string $clientId, array $clientData): ?array
    {
        $email = $clientData['email'] ?? null;

        foreach ($clients as $client) {
            if (($client['id'] ?? null) === $clientId
                || ($client['uuid'] ?? null) === $clientId
                || ($client['password'] ?? null) === $clientId
                || ($client['auth'] ?? null) === $clientId
                || ($client['email'] ?? null) === $clientId
                || (is_string($email) && ($client['email'] ?? null) === $email)) {
                return $client;
            }
        }

        return null;
    }

    protected function logHttpFailure(string $operation, Response $response, array $context = []): void
    {
        Log::warning(static::class . " {$operation} request failed.", array_merge($context, [
            'status'  => $response->status(),
            'message' => $response->json('msg'),
        ]));
    }
}
