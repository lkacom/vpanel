<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MarzbanService
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected string $nodeHostname;
    protected ?string $accessToken = null;

    public function __construct(string $baseUrl, string $username, string $password, string $nodeHostname)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->username = $username;
        $this->password = $password;
        $this->nodeHostname = $nodeHostname;
    }



    public function login(): bool
    {
        try {
            $response = Http::asForm()->post($this->baseUrl . '/api/admin/token', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

            if ($response->successful() && isset($response->json()['access_token'])) {
                $this->accessToken = $response->json()['access_token'];
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error('Marzban Login Exception:', ['message' => $e->getMessage()]);
            return false;
        }
    }

    public function createUser(array $userData): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) {
                return ['detail' => 'Authentication failed'];
            }
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->post($this->baseUrl . '/api/user', [
                    'username' => $userData['username'],
                    'proxies' => ['vless' => new \stdClass()],
                    'inbounds' => new \stdClass(),

                    'expire' => $userData['expire'],
                    'data_limit' => $userData['data_limit'],
                    // ======================================================
                    'data_limit_reset_strategy' => 'no_reset',
                ]);

            Log::info('Marzban Create User Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();

        } catch (\Exception $e) {
            Log::error('Marzban Create User Exception:', ['message' => $e->getMessage()]);
            return null;
        }
    }

    public function updateUser(string $username, array $userData): ?array
    {
        if (!$this->accessToken) {
            if (!$this->login()) return null;
        }

        try {
            $response = Http::withToken($this->accessToken)
                ->withHeaders(['Accept' => 'application/json'])
                ->put($this->baseUrl . "/api/user/{$username}", [
                    'expire' => $userData['expire'],
                    'data_limit' => $userData['data_limit'],
                ]);

            Log::info('Marzban Update User Response:', $response->json() ?? ['raw' => $response->body()]);
            return $response->json();
        } catch (\Exception $e) {
            Log::error('Marzban Update User Exception:', ['message' => $e->getMessage()]);
            return null;
        }
    }


    public function generateSubscriptionLink(array $userApiResponse): string
    {
        $subscriptionUrl = $userApiResponse['subscription_url'];

        return "لینک سابسکریپشن شما (در تمام برنامه‌ها import کنید):\n" . $this->nodeHostname . $subscriptionUrl;
    }
}
