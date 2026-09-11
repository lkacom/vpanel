<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SanaeiXUIService extends AbstractXUIService
{
    /**
     * prefix ثابت API پنل ثنایی.
     * ساختار: host:port / {webPath} / panel/api / endpoint
     * مثال webPath=/panel: host:port/panel/panel/api/inbounds/list
     */
    private const API_PREFIX = '/panel/api';

    private ?bool $isModernPanel = null;

    public function login(): bool
    {
        if ($this->isLoggedIn) {
            return true;
        }

        try {
            /*
             * جریان صحیح برای پنل ثنایی v3+:
             * 1. GET /csrf-token  → پنل یک session cookie + csrf token برمی‌گرداند
             * 2. POST /login با همان cookie jar + X-CSRF-Token header
             *
             * هر دو step باید از همان $this->cookieJar استفاده کنند
             * تا session cookie از مرحله ۱ در مرحله ۲ ارسال شود.
             */
            $csrfToken = $this->fetchCsrfToken();

            $credentials = [
                'username' => $this->username,
                'password' => $this->password,
            ];

            if ($csrfToken !== null) {
                // ارسال JSON + X-CSRF-Token (پنل v3+)
                $response = $this->client()
                    ->withHeader('X-CSRF-Token', $csrfToken)
                    ->asJson()
                    ->post($this->url('/login'), $credentials);
            } else {
                // بدون CSRF — تلاش با JSON (مستندات رسمی: Content-Type: application/json)
                $response = $this->client()
                    ->asJson()
                    ->post($this->url('/login'), $credentials);
            }

            if ($this->isSuccessfulResponse($response)) {
                $this->isLoggedIn = true;
                $this->detectPanelVersion();
                return true;
            }

            // Fallback: form-encoded (پنل‌های قدیمی‌تر)
            $response = $this->client()
                ->asForm()
                ->post($this->url('/login'), $credentials);

            if ($this->isSuccessfulResponse($response)) {
                $this->isLoggedIn = true;
                $this->detectPanelVersion();
                return true;
            }

            $this->logHttpFailure('login', $response, ['login_url' => $this->url('/login')]);
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' login exception.', [
                'message' => $exception->getMessage(),
            ]);
        }

        return false;
    }

    /**
     * دریافت CSRF token از پنل.
     *
     * مهم: این متد از همان $this->client() (و در نتیجه همان cookieJar) استفاده می‌کند
     * تا session cookie که پنل در پاسخ Set-Cookie می‌فرستد در jar ذخیره شود
     * و در request بعدی (POST /login) به صورت خودکار ارسال گردد.
     */
    private function fetchCsrfToken(): ?string
    {
        try {
            // GET /csrf-token — پنل در پاسخ هم token و هم session cookie می‌فرستد
            $response = $this->client()->get($this->url('/csrf-token'));

            if (! $response->successful()) {
                return null;
            }

            // فرمت پاسخ مستندات رسمی: {"success": true, "obj": "csrf-token-string"}
            $token = $response->json('obj');
            if (is_string($token) && $token !== '') {
                return $token;
            }

            // فرمت‌های جایگزین
            $token = $response->json('token');
            if (is_string($token) && $token !== '') {
                return $token;
            }

            // برخی نسخه‌ها رشته خالص برمی‌گردانند
            $body = trim($response->body());
            if ($body !== ''
                && strlen($body) < 256
                && ! str_starts_with($body, '{')
                && ! str_starts_with($body, '<')) {
                return $body;
            }
        } catch (\Throwable $e) {
            Log::debug(static::class . ' fetchCsrfToken failed.', ['message' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * تشخیص نسخه پنل بعد از login موفق.
     * پنل v3+ endpoint /panel/api/clients دارد.
     */
    private function detectPanelVersion(): void
    {
        if ($this->isModernPanel !== null) {
            return;
        }

        try {
            $response = $this->client()->get($this->apiUrl('/clients'));
            // اگر پاسخ 200, 400, 401 یا 403 بود، endpoint وجود دارد → v3+
            if (in_array($response->status(), [200, 400, 401, 403], true)) {
                $this->isModernPanel = true;
                return;
            }
        } catch (\Throwable) {
            // Prefer the documented v3+ endpoint when a probe is blocked or
            // unavailable. addClientModern() still falls back to the legacy
            // endpoint when the panel rejects the modern request.
            $this->isModernPanel = true;
            return;
        }

        $this->isModernPanel = false;
    }

    /**
     * URL کامل برای endpoint‌های API پنل ثنایی.
     * ترکیب: baseUrl + basePath(webPath) + /panel/api + path
     *
     * مثال webPath=/panel:
     *   apiUrl('/inbounds/list') → https://host:port/panel/panel/api/inbounds/list  ✓
     */
    private function apiUrl(string $path): string
    {
        return $this->url(self::API_PREFIX . '/' . ltrim($path, '/'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInbounds(): array
    {
        if (! $this->login()) {
            return [];
        }

        try {
            $response = $this->client()->get($this->apiUrl('/inbounds/list'));

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('get inbounds', $response, [
                    'url' => $this->apiUrl('/inbounds/list'),
                ]);
                return [];
            }

            $inbounds = $response->json('obj', []);

            return is_array($inbounds)
                ? array_values(array_filter($inbounds, 'is_array'))
                : [];
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' could not retrieve inbounds.', [
                'message' => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getClients(int $inboundId): array
    {
        if (! $this->login()) {
            return [];
        }

        try {
            $response = $this->client()->get($this->apiUrl("/inbounds/get/{$inboundId}"));
            return $this->clientsFromInboundResponse($response, $inboundId);
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' could not retrieve inbound clients.', [
                'inbound_id' => $inboundId,
                'message'    => $exception->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * افزودن کلاینت.
     *
     * پنل v3+: POST /panel/api/clients/add با ساختار جدید
     * پنل قدیمی: POST /panel/api/inbounds/addClient با settings JSON
     *
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    public function addClient(int $inboundId, array $clientData): array
    {
        if (! $this->login()) {
            return ['success' => false, 'msg' => 'Authentication to the Sanaei panel failed.'];
        }

        $payload = $this->newClientPayload($clientData);

        return $this->isModernPanel === true
            ? $this->addClientModern($inboundId, $payload)
            : $this->addClientLegacy($inboundId, $payload);
    }

    /**
     * پنل v3+: POST /panel/api/clients/add
     * ساختار جدید: {client: {...}, inboundIds: [N]}
     *
     * @param  array{client: array<string, mixed>, generated_uuid: string, generated_subId: string}  $payload
     * @return array<string, mixed>
     */
    private function addClientModern(int $inboundId, array $payload): array
    {
        try {
            $response = $this->client()
                ->asJson()
                ->post($this->apiUrl('/clients/add'), [
                    'client'     => $payload['client'],
                    'inboundIds' => [$inboundId],
                ]);

            if (! $this->isSuccessfulResponse($response)) {
                Log::info(static::class . ' modern addClient failed, trying legacy.', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                return $this->addClientLegacy($inboundId, $payload);
            }

            return array_merge($this->responsePayload($response, ''), [
                'generated_uuid'  => $payload['generated_uuid'],
                'generated_subId' => $payload['generated_subId'],
                'inbound_id'      => $inboundId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' modern addClient exception.', [
                'message' => $exception->getMessage(),
            ]);
            return $this->addClientLegacy($inboundId, $payload);
        }
    }

    /**
     * پنل قدیمی: POST /panel/api/inbounds/addClient
     * ساختار قدیمی: {id: N, settings: "{\"clients\":[{...}]}"}
     *
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
                    'settings' => json_encode(
                        ['clients' => [$payload['client']]],
                        JSON_THROW_ON_ERROR
                    ),
                ]);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('add client', $response, ['inbound_id' => $inboundId]);
                return $this->responsePayload($response, 'Sanaei panel rejected the client creation request.');
            }

            return array_merge($this->responsePayload($response, ''), [
                'generated_uuid'  => $payload['generated_uuid'],
                'generated_subId' => $payload['generated_subId'],
                'inbound_id'      => $inboundId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' could not add a client.', [
                'inbound_id' => $inboundId,
                'message'    => $exception->getMessage(),
            ]);
            return ['success' => false, 'msg' => 'Error creating a client in the Sanaei panel.'];
        }
    }

    /**
     * ویرایش کلاینت: POST /panel/api/clients/update/{email}
     *
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
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' could not update a client.', [
                'inbound_id' => $inboundId,
                'client_id'  => $clientId,
                'message'    => $exception->getMessage(),
            ]);
            return ['success' => false, 'msg' => 'Error updating a client in the Sanaei panel.'];
        }
    }
}
