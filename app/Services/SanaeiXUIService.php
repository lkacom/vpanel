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

        try {
            $csrfResponse = $this->client()->get($this->url('/csrf-token'));
            $csrfToken = $csrfResponse->json('obj');

            if ($csrfResponse->successful() && is_string($csrfToken) && $csrfToken !== '') {
                $response = $this->client()
                    ->withHeader('X-CSRF-Token', $csrfToken)
                    ->asJson()
                    ->post($this->url('/login'), $this->loginCredentials());
            } else {
                // Compatibility for legacy 3X-UI releases that did not protect login with CSRF.
                $response = $this->client()
                    ->asForm()
                    ->post($this->url('/login'), $this->loginCredentials());
            }

            if ($this->isSuccessfulResponse($response)) {
                $this->isLoggedIn = true;

                return true;
            }

            $this->logHttpFailure('login', $response, ['url' => $this->url('/login')]);
        } catch (\Throwable $exception) {
            Log::warning(static::class.' login request raised an exception.', [
                'message' => $exception->getMessage(),
            ]);
        }

        return false;
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
            $response = $this->client()->get($this->url('/panel/api/inbounds/list'));
            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('get inbounds', $response);

                return [];
            }

            $inbounds = $response->json('obj', []);

            return is_array($inbounds)
                ? array_values(array_filter($inbounds, 'is_array'))
                : [];
        } catch (\Throwable $exception) {
            Log::warning(static::class.' could not retrieve inbounds.', [
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
            $response = $this->client()->get($this->url("/panel/api/inbounds/get/{$inboundId}"));

            return $this->clientsFromInboundResponse($response, $inboundId);
        } catch (\Throwable $exception) {
            Log::warning(static::class.' could not retrieve inbound clients.', [
                'inbound_id' => $inboundId,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
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

        try {
            $response = $this->client()
                ->asJson()
                ->post($this->url('/panel/api/clients/add'), [
                    'client' => $payload['client'],
                    'inboundIds' => [$inboundId],
                ]);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('add client', $response, ['inbound_id' => $inboundId]);

                return $this->responsePayload($response, 'Sanaei panel rejected the client creation request.');
            }

            return array_merge($this->responsePayload($response, ''), [
                'generated_uuid' => $payload['generated_uuid'],
                'generated_subId' => $payload['generated_subId'],
                'inbound_id' => $inboundId,
            ]);
        } catch (\Throwable $exception) {
            Log::warning(static::class.' could not add a client.', [
                'inbound_id' => $inboundId,
                'message' => $exception->getMessage(),
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
        $email = $clientData['email'] ?? $existingClient['email'] ?? null;
        if (! is_string($email) || $email === '') {
            return ['success' => false, 'msg' => 'Client email is required to update a Sanaei client.'];
        }

        $clientData['email'] = $email;
        $clientData['id'] = $existingClient['id'] ?? $clientData['id'] ?? $clientId;
        $client = $this->clientFields($clientData, is_array($existingClient) ? $existingClient : []);

        try {
            $response = $this->client()
                ->asJson()
                ->post($this->url('/panel/api/clients/update/'.rawurlencode($email)), $client);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('update client', $response, [
                    'inbound_id' => $inboundId,
                    'client_id' => $clientId,
                ]);

                return $this->responsePayload($response, 'Sanaei panel rejected the client update request.');
            }

            return $this->responsePayload($response, '');
        } catch (\Throwable $exception) {
            Log::warning(static::class.' could not update a client.', [
                'inbound_id' => $inboundId,
                'client_id' => $clientId,
                'message' => $exception->getMessage(),
            ]);

            return ['success' => false, 'msg' => 'Error updating a client in the Sanaei panel.'];
        }
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
