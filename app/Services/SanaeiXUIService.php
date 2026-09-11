<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SanaeiXUIService extends AbstractXUIService
{
    private const API_PREFIX = '/panel/api';

    private ?bool $isModernPanel = null;

    public function login(): bool
    {
        if ($this->isLoggedIn) {
            return true;
        }

        try {
            $csrfToken = $this->fetchCsrfToken();

            $response = $csrfToken !== null
                ? $this->client()
                    ->withHeader('X-CSRF-Token', $csrfToken)
                    ->asJson()
                    ->post($this->url('/login'), $this->loginCredentials())
                : $this->client()
                    ->asForm()
                    ->post($this->url('/login'), $this->loginCredentials());

            if ($this->isSuccessfulResponse($response)) {
                $this->isLoggedIn = true;
                $this->detectPanelVersion();
                return true;
            }

            if ($csrfToken !== null) {
                $response = $this->client()
                    ->asForm()
                    ->post($this->url('/login'), $this->loginCredentials());

                if ($this->isSuccessfulResponse($response)) {
                    $this->isLoggedIn = true;
                    $this->detectPanelVersion();
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

    private function apiUrl(string $path): string
    {
        return $this->url(self::API_PREFIX . '/' . ltrim($path, '/'));
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

    private function detectPanelVersion(): void
    {
        if ($this->isModernPanel !== null) {
            return;
        }
        try {
            $response = $this->client()->get($this->apiUrl('/clients'));
            if ($response->successful() || in_array($response->status(), [400, 401, 403], true)) {
                $this->isModernPanel = true;
                return;
            }
        } catch (\Throwable) {
        }
        $this->isModernPanel = false;
    }

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
            return is_array($inbounds)
                ? array_values(array_filter($inbounds, 'is_array'))
                : [];
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' could not retrieve inbounds.', ['message' => $exception->getMessage()]);
            return [];
        }
    }

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
                return $this->addClientLegacy($inboundId, $payload);
            }
            return array_merge($this->responsePayload($response, ''), [
                'generated_uuid'  => $payload['generated_uuid'],
                'generated_subId' => $payload['generated_subId'],
                'inbound_id'      => $inboundId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning(static::class . ' modern addClient exception.', ['message' => $exception->getMessage()]);
            return $this->addClientLegacy($inboundId, $payload);
        }
    }

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

    private function loginCredentials(): array
    {
        return [
            'username' => $this->username,
            'password' => $this->password,
        ];
    }
}
