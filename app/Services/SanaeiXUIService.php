<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * سرویس پنل ثنایی 3x-ui
 *
 * مستندات: https://github.com/iamhelitha/3xui-api-client/wiki/Modern-API
 * مستندات رسمی: https://docs.sanaei.dev/docs/reference/api/authentication/
 *
 * ساختار URL:
 *   host:port / {webPath} / panel / api / {endpoint}
 *
 * مثال با webPath=/panel:
 *   Login:    https://host:2083/panel/panel/api/login
 *   CSRF:     https://host:2083/panel/panel/api/csrf-token
 *   Inbounds: https://host:2083/panel/panel/api/inbounds/list
 *   Add:      https://host:2083/panel/panel/api/clients/add  (v3+)
 *             https://host:2083/panel/panel/api/inbounds/addClient  (legacy)
 *
 * اما اگر webPath خالی باشد:
 *   Login: https://host:2083/login
 *
 * AbstractXUIService::url($path) → baseUrl + basePath + '/' + $path
 * بنابراین همه path‌ها باید بدون /panel/ شروع کنند.
 */
class SanaeiXUIService extends AbstractXUIService
{
    /**
     * آیا پنل v3+ (React-based با /panel/api/) است یا legacy (Vue-based با /login).
     * null = هنوز تشخیص داده نشده
     */
    private ?bool $isModernPanel = null;

    public function login(): bool
    {
        if ($this->isLoggedIn) {
            return true;
        }

        // مرحله ۱: تشخیص نسخه پنل از طریق CSRF endpoint
        // v3+ modern: csrf-token در /panel/api/csrf-token
        // legacy:     csrf-token در /csrf-token (یا اصلاً وجود ندارد)
        $csrfResult   = $this->tryModernLogin();
        if ($csrfResult === true) {
            return true;
        }

        // Fallback به login قدیمی
        return $this->tryLegacyLogin();
    }

    /**
     * login پنل v3+ مدرن:
     * 1. GET /panel/api/csrf-token → session cookie + csrf token
     * 2. POST /panel/api/login با JSON + X-CSRF-Token
     */
    private function tryModernLogin(): bool
    {
        try {
            // CSRF endpoint در v3+ زیر /panel/api/ است
            $csrfResponse = $this->client()->get($this->apiUrl('/csrf-token'));

            if (! $csrfResponse->successful()) {
                return false;
            }

            $csrfToken = $csrfResponse->json('obj') ?? $csrfResponse->json('token');
            if (! is_string($csrfToken) || $csrfToken === '') {
                // body ممکن است رشته خالص باشد
                $body = trim($csrfResponse->body());
                if ($body !== '' && strlen($body) < 256 && ! str_starts_with($body, '{') && ! str_starts_with($body, '<')) {
                    $csrfToken = $body;
                } else {
                    return false;
                }
            }

            // POST /panel/api/login با همان cookieJar (session cookie از CSRF request)
            $loginResponse = $this->client()
                ->withHeader('X-CSRF-Token', $csrfToken)
                ->asJson()
                ->post($this->apiUrl('/login'), [
                    'username' => $this->username,
                    'password' => $this->password,
                ]);

            if ($this->isSuccessfulResponse($loginResponse)) {
                $this->isLoggedIn    = true;
                $this->isModernPanel = true;
                Log::debug(static::class . ' logged in via modern API (v3+).');
                return true;
            }

            $this->logHttpFailure('modern login', $loginResponse, ['url' => $this->apiUrl('/login')]);
        } catch (\Throwable $e) {
            Log::debug(static::class . ' modern login failed.', ['message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * login پنل legacy (v2.x و قدیمی‌تر):
     * POST /login با JSON یا form
     */
    private function tryLegacyLogin(): bool
    {
        $credentials = ['username' => $this->username, 'password' => $this->password];

        try {
            // ابتدا CSRF از مسیر قدیمی
            $csrfToken = null;
            try {
                $csrfResp = $this->client()->get($this->url('/csrf-token'));
                if ($csrfResp->successful()) {
                    $t = $csrfResp->json('obj') ?? $csrfResp->json('token');
                    if (is_string($t) && $t !== '') {
                        $csrfToken = $t;
                    }
                }
            } catch (\Throwable) {
            }

            // POST /login با JSON
            $response = $csrfToken
                ? $this->client()->withHeader('X-CSRF-Token', $csrfToken)->asJson()->post($this->url('/login'), $credentials)
                : $this->client()->asJson()->post($this->url('/login'), $credentials);

            if ($this->isSuccessfulResponse($response)) {
                $this->isLoggedIn    = true;
                $this->isModernPanel = false;
                Log::debug(static::class . ' logged in via legacy API.');
                return true;
            }

            // Fallback: form-encoded
            $response = $this->client()->asForm()->post($this->url('/login'), $credentials);
            if ($this->isSuccessfulResponse($response)) {
                $this->isLoggedIn    = true;
                $this->isModernPanel = false;
                Log::debug(static::class . ' logged in via legacy form.');
                return true;
            }

            $this->logHttpFailure('legacy login', $response, ['url' => $this->url('/login')]);
        } catch (\Throwable $e) {
            Log::warning(static::class . ' legacy login exception.', ['message' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * URL برای endpoint‌های زیر /panel/api/ (v3+ و legacy هر دو)
     * AbstractXUIService::url($path) = baseUrl + basePath + '/' + ltrim($path, '/')
     *
     * مثال basePath=/panel:
     *   apiUrl('/login') → host/panel/panel/api/login  ✓
     *
     * مثال basePath='' (webPath خالی):
     *   apiUrl('/login') → host/panel/api/login        ✓
     */
    private function apiUrl(string $path): string
    {
        return $this->url('panel/api/' . ltrim($path, '/'));
    }

    /** @return array<int, array<string, mixed>> */
    public function getInbounds(): array
    {
        if (! $this->login()) {
            return [];
        }

        try {
            $response = $this->client()->get($this->apiUrl('/inbounds/list'));

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('get inbounds', $response, ['url' => $this->apiUrl('/inbounds/list')]);
                return [];
            }

            $inbounds = $response->json('obj', []);
            return is_array($inbounds) ? array_values(array_filter($inbounds, 'is_array')) : [];
        } catch (\Throwable $e) {
            Log::warning(static::class . ' could not retrieve inbounds.', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function getClients(int $inboundId): array
    {
        if (! $this->login()) {
            return [];
        }

        try {
            $response = $this->client()->get($this->apiUrl("/inbounds/get/{$inboundId}"));
            return $this->clientsFromInboundResponse($response, $inboundId);
        } catch (\Throwable $e) {
            Log::warning(static::class . ' could not retrieve inbound clients.', [
                'inbound_id' => $inboundId,
                'message'    => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * افزودن کلاینت
     *
     * v3+:    POST /panel/api/clients/add  با {client: {...}, inboundIds: [...]}
     * legacy: POST /panel/api/inbounds/addClient  با {id: N, settings: JSON}
     *
     * _all_inbound_ids اگر در clientData بود، همه را به v3+ می‌فرستیم (Attached Inbounds)
     *
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    public function addClient(int $inboundId, array $clientData): array
    {
        if (! $this->login()) {
            return ['success' => false, 'msg' => 'Authentication to the Sanaei panel failed.'];
        }

        $allInboundIds = null;
        if (isset($clientData['_all_inbound_ids'])) {
            $allInboundIds = array_values(array_map('intval', (array) $clientData['_all_inbound_ids']));
            unset($clientData['_all_inbound_ids']);
        }

        $payload = $this->newClientPayload($clientData);

        if ($this->isModernPanel === true) {
            return $this->addClientModern($inboundId, $payload, $allInboundIds);
        }

        return $this->addClientLegacy($inboundId, $payload);
    }

    /**
     * @param  array{client: array<string, mixed>, generated_uuid: string, generated_subId: string}  $payload
     * @param  int[]|null  $allInboundIds
     * @return array<string, mixed>
     */
    private function addClientModern(int $inboundId, array $payload, ?array $allInboundIds): array
    {
        $inboundIds = $allInboundIds ?? [$inboundId];

        try {
            $response = $this->client()
                ->asJson()
                ->post($this->apiUrl('/clients/add'), [
                    'client'     => $payload['client'],
                    'inboundIds' => $inboundIds,
                ]);

            if (! $this->isSuccessfulResponse($response)) {
                Log::info(static::class . ' modern addClient failed, trying legacy.', [
                    'status' => $response->status(),
                    'url'    => $this->apiUrl('/clients/add'),
                    'body'   => substr($response->body(), 0, 300),
                ]);
                return $this->addClientLegacy($inboundId, $payload);
            }

            return array_merge($this->responsePayload($response, ''), [
                'generated_uuid'  => $payload['generated_uuid'],
                'generated_subId' => $payload['generated_subId'],
                'inbound_id'      => $inboundId,
            ]);
        } catch (\Throwable $e) {
            Log::warning(static::class . ' modern addClient exception.', ['message' => $e->getMessage()]);
            return $this->addClientLegacy($inboundId, $payload);
        }
    }

    /**
     * @param  array{client: array<string, mixed>, generated_uuid: string, generated_subId: string}  $payload
     * @return array<string, mixed>
     */
    private function addClientLegacy(int $inboundId, array $payload): array
    {
        try {
            $response = $this->client()
                ->asJson()
                ->post($this->apiUrl('/inbounds/addClient'), [
                    'id'       => $inboundId,
                    'settings' => json_encode(['clients' => [$payload['client']]], JSON_THROW_ON_ERROR),
                ]);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('add client legacy', $response, [
                    'inbound_id' => $inboundId,
                    'url'        => $this->apiUrl('/inbounds/addClient'),
                ]);
                return $this->responsePayload($response, 'Sanaei panel rejected the client creation request.');
            }

            return array_merge($this->responsePayload($response, ''), [
                'generated_uuid'  => $payload['generated_uuid'],
                'generated_subId' => $payload['generated_subId'],
                'inbound_id'      => $inboundId,
            ]);
        } catch (\Throwable $e) {
            Log::warning(static::class . ' could not add a client.', [
                'inbound_id' => $inboundId,
                'message'    => $e->getMessage(),
            ]);
            return ['success' => false, 'msg' => 'Error creating a client in the Sanaei panel.'];
        }
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    public function updateClient(int $inboundId, string $clientId, array $clientData): array
    {
        if (! $this->login()) {
            return ['success' => false, 'msg' => 'Authentication to the Sanaei panel failed.'];
        }

        $existingClient = $this->findClient($this->getClients($inboundId), $clientId, $clientData);
        $email          = $clientData['email'] ?? $existingClient['email'] ?? null;

        if (! is_string($email) || $email === '') {
            return ['success' => false, 'msg' => 'Client email is required to update a Sanaei client.'];
        }

        $clientData['email'] = $email;
        $clientData['id']    = $existingClient['id'] ?? $clientData['id'] ?? $clientId;
        $client              = $this->clientFields($clientData, is_array($existingClient) ? $existingClient : []);

        try {
            $response = $this->client()
                ->asJson()
                ->post($this->apiUrl('/clients/update/' . rawurlencode($email)), $client);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('update client', $response, [
                    'inbound_id' => $inboundId,
                    'client_id'  => $clientId,
                ]);
                return $this->responsePayload($response, 'Sanaei panel rejected the client update request.');
            }

            return $this->responsePayload($response, '');
        } catch (\Throwable $e) {
            Log::warning(static::class . ' could not update a client.', [
                'inbound_id' => $inboundId,
                'client_id'  => $clientId,
                'message'    => $e->getMessage(),
            ]);
            return ['success' => false, 'msg' => 'Error updating a client in the Sanaei panel.'];
        }
    }
}
