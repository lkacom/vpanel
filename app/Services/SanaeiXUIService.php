<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * سرویس پنل ثنایی 3x-ui (نسخه‌های v3.0+ مبتنی بر React)
 *
 * مستندات: https://docs.sanaei.dev/docs/reference/api/authentication/
 * OpenAPI: https://host:port{basePath}/panel/api/openapi.json
 *
 * ساختار URL پنل‌های v3.7+:
 *
 *   basePath = /panel (یا هر مسیر دلخواه دیگر)
 *
 *   {baseUrl}{basePath}/csrf-token          → CSRF token (سطح بالا)
 *   {baseUrl}{basePath}/login               → Login (سطح بالا)
 *   {baseUrl}{basePath}/panel/api/...       → API endpoints (زیر SPA)
 *   {baseUrl}{basePath}/ws                  → WebSocket
 *
 * مثال‌ها:
 *   CSRF:      https://host:2083/panel/csrf-token
 *   Login:     https://host:2083/panel/login
 *   API:       https://host:2083/panel/panel/api/clients/add
 *   Inbounds:  https://host:2083/panel/panel/api/inbounds/list/slim
 *
 * AbstractXUIService::url($path) = baseUrl + basePath + '/' + $path
 * apiUrl($path) = url('panel/api/' + $path) = host + /panel + /panel/api/ + $path
 *
 * Legacy (v2.x):
 *   {baseUrl}/login                        → Login
 *   {baseUrl}/csrf-token                   → CSRF
 *   {baseUrl}/xui/inbound/addClient        → AddClient
 */
class SanaeiXUIService extends AbstractXUIService
{
    /**
     * آیا پنل v3+ (React-based با /panel/panel/api/) است یا legacy (Vue-based با /xui/).
     * null = هنوز تشخیص داده نشده
     */
    private ?bool $isModernPanel = null;

    /**
     * CSRF token برای احراز هویت درخواست‌های API
     * از endpoint /csrf-token دریافت شده و در همه درخواست‌ها ارسال می‌شود
     */
    private ?string $csrfToken = null;

    public function login(): bool
    {
        if ($this->isLoggedIn) {
            return true;
        }

        Log::debug(static::class . ' login attempt.', [
            'host'     => $this->baseUrl,
            'basePath' => $this->basePath,
        ]);

        // مرحله ۱: تشخیص نسخه پنل از طریق CSRF endpoint
        // v3+ modern: csrf-token در {basePath}/csrf-token (سطح بالا)
        // legacy:     csrf-token در {baseUrl}/csrf-token
        $csrfResult   = $this->tryModernLogin();
        if ($csrfResult === true) {
            return true;
        }

        // Fallback به login قدیمی
        return $this->tryLegacyLogin();
    }

    /**
     * login پنل v3+ مدرن:
     * 1. GET /panel/csrf-token → session cookie + csrf token
     * 2. POST /panel/login با form-encoded + X-CSRF-Token
     *
     * توجه: CSRF و Login در سطح بالای /panel/ هستند (نه /panel/api/)
     */
    private function tryModernLogin(): bool
    {
        try {
            // CSRF endpoint در سطح بالای basePath: /panel/csrf-token
            $csrfUrl = $this->url('/csrf-token');
            Log::debug(static::class . ' trying modern login.', ['url' => $csrfUrl]);

            $csrfResponse = $this->client()->get($csrfUrl);

            if (! $csrfResponse->successful()) {
                Log::debug(static::class . ' modern CSRF failed.', ['status' => $csrfResponse->status()]);
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

            // ذخیره CSRF token برای استفاده در درخواست‌های بعدی
            $this->csrfToken = $csrfToken;
            Log::debug(static::class . ' CSRF token saved.', ['token_length' => strlen($csrfToken)]);

            // POST /panel/login با form-encoded (پنل v3.7+ form را می‌پذیرد)
            $loginResponse = $this->client()
                ->withHeader('X-CSRF-Token', $csrfToken)
                ->asForm()
                ->post($this->url('/login'), [
                    'username' => $this->username,
                    'password' => $this->password,
                ]);

            if ($this->isSuccessfulResponse($loginResponse)) {
                $this->isLoggedIn    = true;
                $this->isModernPanel = true;
                Log::debug(static::class . ' logged in via modern API (v3+).');
                return true;
            }

            // Fallback: try JSON body
            $loginResponse = $this->client()
                ->withHeader('X-CSRF-Token', $csrfToken)
                ->asJson()
                ->post($this->url('/login'), [
                    'username' => $this->username,
                    'password' => $this->password,
                ]);

            if ($this->isSuccessfulResponse($loginResponse)) {
                $this->isLoggedIn    = true;
                $this->isModernPanel = true;
                Log::debug(static::class . ' logged in via modern API (v3+) JSON.');
                return true;
            }

            $this->logHttpFailure('modern login', $loginResponse, ['url' => $this->url('/login')]);
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
        $loginUrl = $this->url('/login');

        Log::debug(static::class . ' trying legacy login.', ['url' => $loginUrl]);

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
     * URL برای endpoint‌های API پنل v3+ (React-based)
     *
     * ساختار URL پنل‌های v3.7+:
     *   /panel/csrf-token          → CSRF token (سطح بالا)
     *   /panel/login               → Login (سطح بالا)
     *   /panel/panel/api/...       → API endpoints (زیر SPA)
     *   /panel/ws                  → WebSocket
     *
     * بنابراین apiUrl باید basePath را دوبار اضافه کند:
     *   apiUrl('/clients/add') → host/panel/panel/api/clients/add
     *
     * AbstractXUIService::url($path) = baseUrl + basePath + '/' + ltrim($path, '/')
     * url('panel/api/clients/add') = host + /panel + /panel/api/clients/add
     */
    private function apiUrl(string $path): string
    {
        return $this->url('panel/api/' . ltrim($path, '/'));
    }

    /**
     * ایجاد درخواست HTTP با CSRF token
     * این متد CSRF token ذخیره شده را به header اضافه می‌کند
     */
    private function apiRequest(): \Illuminate\Http\Client\PendingRequest
    {
        $request = $this->client()->acceptJson();
        if ($this->csrfToken) {
            $request = $request->withHeader('X-CSRF-Token', $this->csrfToken);
            Log::debug(static::class . ' API request with CSRF token.', ['token_length' => strlen($this->csrfToken)]);
        } else {
            Log::debug(static::class . ' API request WITHOUT CSRF token!');
        }
        return $request;
    }

    /** @return array<int, array<string, mixed>> */
    public function getInbounds(): array
    {
        if (! $this->login()) {
            return [];
        }

        try {
            $response = $this->apiRequest()->get($this->apiUrl('/inbounds/list'));

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
            $response = $this->apiRequest()->get($this->apiUrl("/inbounds/get/{$inboundId}"));
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
     * v3+:    POST /panel/panel/api/clients/add  با {client: {...}, inboundIds: [...]}
     * legacy: POST /panel/panel/api/inbounds/addClient  با {id: N, settings: JSON}
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

        // ابتدا endpoint مدرن v3+ را امتحان کن
        $result = $this->addClientModern($inboundId, $payload, $allInboundIds);
        if ($result['success'] ?? false) {
            return $result;
        }

        // اگر modern fail شد، legacy endpoint را امتحان کن
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
            $url = $this->apiUrl('/clients/add');
            Log::debug(static::class . ' modern addClient request.', [
                'url'        => $url,
                'inbound_id' => $inboundId,
                'inbound_ids'=> $inboundIds,
                'has_csrf'   => $this->csrfToken !== null,
            ]);

            $response = $this->apiRequest()->asJson()->post($url, [
                'client'     => $payload['client'],
                'inboundIds' => $inboundIds,
            ]);

            Log::debug(static::class . ' modern addClient response.', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);

            if (! $this->isSuccessfulResponse($response)) {
                Log::info(static::class . ' modern addClient failed, trying legacy.', [
                    'status' => $response->status(),
                    'url'    => $url,
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
            $url = $this->apiUrl('/inbounds/addClient');
            Log::debug(static::class . ' legacy addClient request.', [
                'url'        => $url,
                'inbound_id' => $inboundId,
                'has_csrf'   => $this->csrfToken !== null,
            ]);

            $response = $this->apiRequest()->asJson()->post($url, [
                'id'       => $inboundId,
                'settings' => json_encode(['clients' => [$payload['client']]], JSON_THROW_ON_ERROR),
            ]);

            Log::debug(static::class . ' legacy addClient response.', [
                'status' => $response->status(),
                'body'   => substr($response->body(), 0, 500),
            ]);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('add client legacy', $response, [
                    'inbound_id' => $inboundId,
                    'url'        => $url,
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
            $response = $this->apiRequest()
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
