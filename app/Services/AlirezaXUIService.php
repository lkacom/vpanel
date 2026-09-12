<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AlirezaXUIService extends AbstractXUIService
{
    public function login(): bool
    {
        if ($this->isLoggedIn) {
            return true;
        }

        try {
            $response = $this->client()
                ->asForm()
                ->post($this->url('/login'), [
                    'username' => $this->username,
                    'password' => $this->password,
                ]);

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
            $response = $this->client()->get($this->url('/xui/API/inbounds/'));
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
            $response = $this->client()->get($this->url("/xui/API/inbounds/get/{$inboundId}"));

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
            return ['success' => false, 'msg' => 'Authentication to the Alireza panel failed.'];
        }

        $payload = $this->newClientPayload($clientData);

        try {
            $response = $this->client()
                ->asForm()
                ->post($this->url('/xui/API/inbounds/addClient'), [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$payload['client']]], JSON_THROW_ON_ERROR),
                ]);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('add client', $response, ['inbound_id' => $inboundId]);

                return $this->responsePayload($response, 'Alireza panel rejected the client creation request.');
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

            return ['success' => false, 'msg' => 'Error creating a client in the Alireza panel.'];
        }
    }

    /**
     * @param  array<string, mixed>  $clientData
     * @return array<string, mixed>
     */
    public function updateClient(int $inboundId, string $clientId, array $clientData): array
    {
        if (! $this->login()) {
            return ['success' => false, 'msg' => 'Authentication to the Alireza panel failed.'];
        }

        $existingClient = $this->findClient($this->getClients($inboundId), $clientId, $clientData);
        $client = $this->clientFields($clientData, is_array($existingClient) ? $existingClient : []);

        try {
            $response = $this->client()
                ->asForm()
                ->post($this->url('/xui/API/inbounds/updateClient/'.rawurlencode($clientId)), [
                    'id' => $inboundId,
                    'settings' => json_encode(['clients' => [$client]], JSON_THROW_ON_ERROR),
                ]);

            if (! $this->isSuccessfulResponse($response)) {
                $this->logHttpFailure('update client', $response, [
                    'inbound_id' => $inboundId,
                    'client_id' => $clientId,
                ]);

                return $this->responsePayload($response, 'Alireza panel rejected the client update request.');
            }

            return $this->responsePayload($response, '');
        } catch (\Throwable $exception) {
            Log::warning(static::class.' could not update a client.', [
                'inbound_id' => $inboundId,
                'client_id' => $clientId,
                'message' => $exception->getMessage(),
            ]);

            return ['success' => false, 'msg' => 'Error updating a client in the Alireza panel.'];
        }
    }

    /**
     * دریافت آدرس کامل سابسکریپشن
     *
     * پنل علیرضا سابسکریپشن را در مسیر /sub/{subId} ارائه می‌دهد
     *
     * @param  int|null  $inboundId  شناسه inbound برای دریافت پورت و مسیر
     * @return array{url: string, port: int, path: string}|null آدرس کامل سابسکریپشن یا null اگر در دسترس نباشد
     */
    public function getSubscriptionUrl(?int $inboundId = null): ?array
    {
        if (! $this->login()) {
            return null;
        }

        try {
            // آدرس پایه سابسکریپشن = آدرس کامل پنل + /sub
            // پورت از baseUrl گرفته می‌شود
            $parsedUrl = parse_url($this->baseUrl);
            $port = (int) ($parsedUrl['port'] ?? 443);

            // دریافت مسیر از inbound اگر موجود باشد
            $path = '/sub';
            if ($inboundId) {
                $inbounds = $this->getInbounds();
                $inbound = collect($inbounds)->firstWhere('id', $inboundId);
                if ($inbound) {
                    $streamSettings = $inbound['streamSettings'] ?? [];
                    if (is_string($streamSettings)) {
                        $streamSettings = json_decode($streamSettings, true) ?? [];
                    }
                    $path = '/sub';
                }
            }

            $url = $this->baseUrl . ':' . $port . '/sub';
            Log::debug(static::class . ' subscription URL.', ['url' => $url, 'port' => $port]);
            return ['url' => $url, 'port' => $port, 'path' => '/sub'];
        } catch (\Throwable $e) {
            Log::debug(static::class . ' could not get subscription settings.', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
