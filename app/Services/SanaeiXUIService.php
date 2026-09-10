<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SanaeiXUIService extends AbstractXUIService
{
    public function login(): bool
    {
        if ($this->isLoggedIn) {
            return true;
        }

        Log::debug(static::class . ' attempting login.', [
            'login_url' => $this->url('/login'),
            'csrf_url'  => $this->url('/csrf-token'),
        ]);

        try {
            $csrfToken = $this->fetchCsrfToken();

            if ($csrfToken !== null) {
                $response = $this->client()
                    ->withHeader('X-CSRF-Token', $csrfToken)
                    ->asJson()
                    ->post($this->url('/login'), $this->loginCredentials());
            } else {
                $response = $this->client()
                    ->asForm()
                    ->post($this->url('/login'), $this->loginCredentials());
            }

            if ($this->isSuccessfulResponse($response)) {
                $this->isLoggedIn = true;
                return true;
            }

            // Fallback: form بدون CSRF
            if ($csrfToken !== null) {
                $response = $this->client()
                    ->asForm()
                    ->post($this->url('/login'), $this->loginCredentials());

                if ($this->isSuccessfulResponse($response)) {
                    $this->isLoggedIn = true;
                    return true;
                }
            }

            $this->logHttpFailure('login', $response, ['login_url' => $this->url('/login')]);
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' login exception.', [
                'message' => $exception->getMessage(),
            ]);
        }

        return false;
    }

    private function fetchCsrfToken(): ?string
    {
        try {
            $response = $this->client()->get($this->url('/csrf-token'));

            if (! $response->successful()) {
                return null;
            }

            $token = $response->json('obj');
            if (is_string($token) && $token !== '') {
                return $token;
            }

            $token = $response->json('token');
            if (is_string($token) && $token !== '') {
                return $token;
            }

            $body = trim($response->body());
            if ($body !== '' && strlen($body) < 256
                && ! str_starts_with($body, '{')
                && ! str_starts_with($body, '<')) {
                return $body;
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getInbounds(): array
    {
        if (! $this->login()) {
            return [];
        }

        // endpoint های ممکن به ترتیب اولویت — هر کدام که 200 داد استفاده می‌شود
        $candidates = [
            '/api/inbounds/list',           // پنل ثنایی v2/v3 با basePath
            '/panel/api/inbounds/list',     // fallback اگر basePath خالی است
        ];

        foreach ($candidates as $path) {
            try {
                $url      = $this->url($path);
                $response = $this->client()->get($url);

                Log::debug(static::class . ' getInbounds attempt.', [
                    'url'    => $url,
                    'status' => $response->status(),
                ]);

                if (! $this->isSuccessfulResponse($response)) {
                    continue;
                }

                $inbounds = $response->json('obj', []);

                return is_array($inbounds)
                    ? array_values(array_filter($inbounds, 'is_array'))
                    : [];
            } catch (\Throwable $exception) {
                Log::warning(static::class . ' getInbounds exception.', [
                    'path'    => $path,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        Log::warning(static::class . ' getInbounds: all endpoints failed.');
        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getClients(int $inboundId): array
    {
        if (! $this->login()) {
            return [];
        }

        $candidates = [
            "/api/inbounds/get/{$inboundId}",
            "/panel/api/inbounds/get/{$inboundId}",
        ];

        foreach ($candidates as $path) {
            try {
                $response = $this->client()->get($this->url($path));
                $clients  = $this->clientsFromInboundResponse($response, $inboundId);
                if (! empty($clients)) {
                    return $clients;
                }
            } catch (\Throwable $exception) {
                Log::warning(static::class . ' getClients exception.', [
                    'path'    => $path,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    public function addClient(int $inboundId, array $clientData): array
    {
        if (! $this->login()) {
            return ['success' => false, 'msg' => 'Authentication to the Sanaei panel failed.'];
        }

        $payload = $this->newClientPayload($clientData);

        // اول endpoint جدید v3+ را امتحان کن، بعد قدیمی
        $result = $this->tryPost('/api/clients/add', [
            'client'     => $payload['client'],
            'inboundIds' => [$inboundId],
        ]);

        if ($result === null) {
            // endpoint قدیمی
            $result = $this->tryPost('/api/inbounds/addClient', [
                'id'       => $inboundId,
                'settings' => json_encode(['clients' => [$payload['client']]], JSON_THROW_ON_ERROR),
            ]);
        }

        if ($result === null) {
            return ['success' => false, 'msg' => 'Sanaei panel rejected the client creation request.'];
        }

        return array_merge($result, [
            'generated_uuid'  => $payload['generated_uuid'],
            'generated_subId' => $payload['generated_subId'],
            'inbound_id'      => $inboundId,
        ]);
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

        $result = $this->tryPost('/api/clients/update/' . rawurlencode($email), $client);

        if ($result === null) {
            return ['success' => false, 'msg' => 'Sanaei panel rejected the client update request.'];
        }

        return $result;
    }

    /**
     * یک POST JSON می‌زند و در صورت موفقیت payload برمی‌گرداند، در غیر این صورت null.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function tryPost(string $path, array $body): ?array
    {
        try {
            $url      = $this->url($path);
            $response = $this->client()->asJson()->post($url, $body);

            Log::debug(static::class . ' tryPost.', [
                'url'    => $url,
                'status' => $response->status(),
            ]);

            if ($this->isSuccessfulResponse($response)) {
                return $this->responsePayload($response, '');
            }
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' tryPost exception.', [
                'path'    => $path,
                'message' => $exception->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * @return array{username: string, password: string}
     */
    private function loginCredentials(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
