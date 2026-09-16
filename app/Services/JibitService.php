<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class JibitService
{
    private string $apiKey;
    private string $secretKey;
    private bool   $active;
    private bool   $sandbox;
    private bool   $isLive;
    private string $currency;
    private array  $endpoints;

    private const ENDPOINTS = [
        'live' => [
            'request' => 'https://napi.jibit.ir/pg/v4/payment/request.json',
            'verify'  => 'https://napi.jibit.ir/pg/v4/payment/verify.json',
            'gateway' => 'https://www.jibit.com/pg/StartPay/',
        ],
        'sandbox' => [
            'request' => 'https://napi.jibit.ir/sandbox/pg/v4/payment/request.json',
            'verify'  => 'https://napi.jibit.ir/sandbox/pg/v4/payment/verify.json',
            'gateway' => 'https://www.jibit.com/sandbox/pg/StartPay/',
        ],
    ];

    public function __construct()
    {
        $settings = Setting::all()->pluck('value', 'key');

        $this->active     = filter_var($settings->get('jibit_active') ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->apiKey     = (string) ($settings->get('jibit_api_key') ?? '');
        $this->secretKey  = (string) ($settings->get('jibit_secret_key') ?? '');
        $this->sandbox    = filter_var($settings->get('jibit_sandbox') ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->currency   = (string) ($settings->get('jibit_currency') ?? 'IRT');
        $this->isLive     = ! $this->sandbox;
        $this->endpoints  = self::ENDPOINTS[$this->isLive ? 'live' : 'sandbox'];
    }

    public function isEnabled(): bool
    {
        return $this->active && ! empty($this->apiKey) && ! empty($this->secretKey);
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public function isLive(): bool
    {
        return $this->isLive;
    }

    /**
     * ایجاد درخواست پرداخت جیبیت.
     *
     * @param int    $amount        مبلغ پرداخت به تومان
     * @param string $description   توصیف پرداخت
     * @param string|null $nationalCode کد ملی پرداخت‌کننده
     * @param string|null $mobile       موبایل پرداخت‌کننده
     * @param string|null $email        ایمیل پرداخت‌کننده
     * @return array{authority: string, redirect_url: string}
     */
    public function request(int $amount, string $description = '', ?string $nationalCode = null, ?string $mobile = null, ?string $email = null): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('درگاه جیبیت فعال نیست یا پیکربندی اتصال نشده است.');
        }

        $callbackUrl = route('payment.jibit.callback');

        $settings    = Setting::all()->pluck('value', 'key');
        $description = $description ?: (string) ($settings->get('jibit_gateway_name') ?? 'پرداخت آنلاین — جیبیت');

        $body = [
            'amount'              => $amount,
            'currency'            => $this->isLive() ? $this->currency : 'IRT',
            'callbackUrl'         => $callbackUrl,
            'clientReferenceNumber' => $this->apiKey,
            'userIdentifier'      => $this->apiKey,
            'description'         => $description,
            'payerNationalCode'   => $nationalCode,
            'payerMobileNumber'   => $mobile,
            'additionalData'      => [
                'email' => $email,
            ],
        ];

        $response = $this->post($this->endpoints['request'], $body);
        $statusCode = $response->getStatusCode();
        $rawContent = $this->extractRawContent($response);

        Log::debug('Jibit request response', [
            'endpoint' => $this->endpoints['request'],
            'body'     => $body,
            'status'   => $statusCode,
            'headers'  => $this->getResponseHeaders($response),
            'content'  => $rawContent,
        ]);

        // بررسی کد وضعیت HTTP
        if ($statusCode >= 400) {
            $code = 'http_' . $statusCode;
            $msg  = 'خطای HTTP از سرور جیبیت';
            Log::error('Jibit request failed (HTTP error)', [
                'endpoint' => $this->endpoints['request'],
                'body'     => $body,
                'status'   => $statusCode,
                'content'  => $rawContent,
            ]);
            throw new RuntimeException("جیبیت: {$msg} (کد: {$code})");
        }

        $data = $response->json();

        // اطمینان از اینکه پاسخ JSON معتبر آرایه است
        if (! is_array($data)) {
            Log::error('Jibit request failed (invalid JSON response)', [
                'endpoint' => $this->endpoints['request'],
                'body'     => $body,
                'status'   => $statusCode,
                'content'  => $rawContent,
            ]);
            throw new RuntimeException('جیبیت: پاسخ نامعتبر از سرور (JSON معتبر نیست)');
        }

        $authority = $this->extractAuthority($data);

        if ($authority === null) {
            $code = $this->extractErrorCode($data);
            $msg  = $this->extractErrorMessage($data);
            Log::error('Jibit request failed', [
                'endpoint' => $this->endpoints['request'],
                'body'     => $body,
                'response' => $data,
                'code'     => $code,
                'msg'      => $msg,
            ]);
            throw new RuntimeException("جیبیت: {$msg} (کد: {$code})");
        }

        return [
            'authority'    => $authority,
            'redirect_url' => $this->constructRedirectUrl($authority),
        ];
    }

    private function constructRedirectUrl(string $authority): string
    {
        $isFullUrl = str_starts_with($authority, 'http://') || str_starts_with($authority, 'https://');
        return $isFullUrl ? $authority : $this->endpoints['gateway'] . $authority;
    }

    private function extractAuthority(array $data): ?string
    {
        // ساختار اصلی: data.pspSwitchingUrl یا data.purchaseId
        $pspSwitchingUrl = $data['data']['pspSwitchingUrl'] ?? null;
        $purchaseId      = $data['data']['purchaseId'] ?? null;

        if ($pspSwitchingUrl !== null || $purchaseId !== null) {
            return $pspSwitchingUrl ?? $purchaseId;
        }

        // ساختار با success/status/result
        $topKeys = ['success', 'status', 'result'];
        foreach ($topKeys as $topKey) {
            if (isset($data[$topKey]) && is_array($data[$topKey]) && isset($data[$topKey]['data'])) {
                $dataFields = $data[$topKey]['data'];
                $pspSwitchingUrl = $dataFields['pspSwitchingUrl'] ?? null;
                $purchaseId      = $dataFields['purchaseId'] ?? null;
                if ($pspSwitchingUrl !== null || $purchaseId !== null) {
                    return $pspSwitchingUrl ?? $purchaseId;
                }
            }
        }

        // ساختار سطح اول
        if (isset($data['pspSwitchingUrl']) || isset($data['purchaseId'])) {
            return $data['pspSwitchingUrl'] ?? $data['purchaseId'] ?? null;
        }

        // ساختار array data
        if (isset($data['data']) && is_array($data['data']) && ! empty($data['data'])) {
            $firstData = $data['data'][0] ?? [];
            $pspSwitchingUrl = $firstData['pspSwitchingUrl'] ?? null;
            $purchaseId      = $firstData['purchaseId'] ?? null;
            if ($pspSwitchingUrl !== null || $purchaseId !== null) {
                return $pspSwitchingUrl ?? $purchaseId;
            }
        }

        return null;
    }

    private function extractErrorCode(array $data): string
    {
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_array($error) && isset($error['code'])) {
                    return (string) $error['code'];
                }
            }
        }

        if (isset($data['errors']['code'])) {
            return $data['errors']['code'];
        }
        if (isset($data['error']['code'])) {
            return $data['error']['code'];
        }
        if (isset($data['code'])) {
            return (string) $data['code'];
        }
        return 'unknown';
    }

    private function extractErrorMessage(array $data): string
    {
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_array($error) && isset($error['message'])) {
                    return (string) $error['message'];
                }
            }
        }

        if (isset($data['errors']['message'])) {
            return $data['errors']['message'];
        }
        if (isset($data['error']['message'])) {
            return $data['error']['message'];
        }
        if (isset($data['message'])) {
            return $data['message'];
        }
        return 'خطای نامشخص';
    }

    private function getResponseHeaders($response): array
    {
        $headersResult = $response->headers();

        if (is_object($headersResult) && method_exists($headersResult, 'all')) {
            return $headersResult->all();
        }

        if (is_array($headersResult)) {
            return $headersResult;
        }

        if (method_exists($response, 'getHeaders')) {
            $headersFromGetHeaders = $response->getHeaders();
            if (is_array($headersFromGetHeaders)) {
                return $headersFromGetHeaders;
            }
            return [];
        }

        return [];
    }

    /**
     * تأیید پرداخت جیبیت.
     *
     * @return array{ref_id: string, authority: string, code: int}
     */
    public function verify(string $authority, int $amount): array
    {
        $response = $this->post($this->endpoints['verify'], [
            'clientReferenceNumber' => $authority,
            'amount'                => $amount,
            'callbackUrl'           => route('payment.jibit.callback'),
        ]);

        $statusCode = $response->getStatusCode();
        $rawContent = $this->extractRawContent($response);

        Log::debug('Jibit verify response', [
            'endpoint' => $this->endpoints['verify'],
            'authority' => $authority,
            'amount'   => $amount,
            'status'   => $statusCode,
            'headers'  => $this->getResponseHeaders($response),
            'content'  => $rawContent,
        ]);

        if ($statusCode >= 400) {
            $code = 'http_' . $statusCode;
            $msg  = 'خطای HTTP از سرور جیبیت در تایید پرداخت';
            Log::error('Jibit verify failed (HTTP error)', [
                'endpoint' => $this->endpoints['verify'],
                'authority' => $authority,
                'amount'   => $amount,
                'status'   => $statusCode,
                'content'  => $rawContent,
            ]);
            throw new RuntimeException("جیبیت: {$msg} (کد: {$code})");
        }

        $data = $response->json();

        if (! is_array($data)) {
            Log::error('Jibit verify failed (invalid JSON response)', [
                'endpoint' => $this->endpoints['verify'],
                'authority' => $authority,
                'amount'   => $amount,
                'status'   => $statusCode,
                'content'  => $rawContent,
            ]);
            throw new RuntimeException('جیبیت: پاسخ نامعتبر از سرور در تایید پرداخت (JSON معتبر نیست)');
        }

        $code = $data['data']['code'] ?? $data['data']['status'] ?? -1;

        if (! in_array($code, [100, 101, 200, 201])) {
            $msg = $this->extractErrorMessage($data);
            throw new RuntimeException("جیبیت: {$msg} (کد: {$code})");
        }

        $refId = $data['data']['ref_id'] ?? $data['data']['reference_id'] ?? '';

        return [
            'ref_id'    => (string) $refId,
            'authority' => $authority,
            'code'      => $code,
        ];
    }

    private function post(string $url, array $body): \Illuminate\Http\Client\Response
    {
        $client = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->timeout(30);

        if (app()->environment('local')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client->post($url, $body);
    }

    private function extractRawContent(\Illuminate\Http\Client\Response $response): string
    {
        if (method_exists($response, 'getBody')) {
            try {
                $body = $response->getBody();
                if ($body !== null) {
                    return (string) $body;
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }
        return '';
    }
}