<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class JibitService
{
    private string $apiKey;
    private string $secretKey;
    private bool   $active;
    private string $currency;
    private string $baseUrl;

    private const TOKEN_CACHE_KEY = 'jibit_access_token';
    private const REFRESH_TOKEN_CACHE_KEY = 'jibit_refresh_token';

    public function __construct()
    {
        // مقدار تنظیمات در مدل Setting به‌صورت array cast شده است، اما کلیدهای
        // جیبیت رشته‌ای هستند. خواندن raw مانع تبدیل کلیدهای قدیمی/غیر JSON به null می‌شود.
        $settings = DB::table('settings')
            ->whereIn('key', [
                'jibit_active', 'jibit_api_key', 'jibit_secret_key',
                'jibit_currency', 'jibit_gateway_name',
            ])
            ->pluck('value', 'key');

        $this->active     = filter_var($this->settingValue($settings->get('jibit_active')), FILTER_VALIDATE_BOOLEAN);
        $this->apiKey     = $this->settingValue($settings->get('jibit_api_key'));
        $this->secretKey  = $this->settingValue($settings->get('jibit_secret_key'));
        $this->currency   = $this->settingValue($settings->get('jibit_currency')) ?: 'IRR';

        // جیبیت PPG v3 در مستندات رسمی فقط همین base URL را اعلام می‌کند.
        // حالت آزمایشی با credential/environment سمت جیبیت کنترل می‌شود و
        // افزودن /sandbox به URL باعث پاسخ 404 می‌شود.
        $this->baseUrl        = 'https://napi.jibit.ir/ppg/v3';
    }

    public function isEnabled(): bool
    {
        return $this->active && ! empty($this->apiKey) && ! empty($this->secretKey);
    }

    private function settingValue(mixed $value): string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_scalar($decoded)) {
                $value = $decoded;
            }
        }

        return trim((string) ($value ?? ''));
    }

    /**
     * دریافت Access Token (با کش ۲۳ ساعته برای اطمینان از انقضا قبل از نیاز).
     */
    private function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        // اگر توکن در کش نیست، سعی در رفرش با refresh token کنیم
        $refreshToken = Cache::get(self::REFRESH_TOKEN_CACHE_KEY);
        if ($refreshToken) {
            try {
                return $this->refreshAccessToken($refreshToken);
            } catch (\Throwable $e) {
                Log::warning('Jibit refresh token failed, generating new token', [
                    'error' => $e->getMessage(),
                ]);
                // refresh token هم منقضی شده، توکن جدید بگیر
            }
        }

        // دریافت توکن جدید با apiKey/secretKey
        return $this->generateNewToken();
    }

    /**
     * تولید توکن جدید با apiKey/secretKey.
     */
    private function generateNewToken(): string
    {
        if ($this->apiKey === '' || $this->secretKey === '') {
            throw new RuntimeException('جیبیت: کلید API یا Secret Key در تنظیمات پروژه خالی است.');
        }

        Log::debug('Jibit token request prepared', [
            'api_key_length'    => strlen($this->apiKey),
            'secret_key_length' => strlen($this->secretKey),
        ]);

        $url = "{$this->baseUrl}/tokens";

        $response = $this->httpClient()->post($url, [
            'apiKey'    => $this->apiKey,
            'secretKey' => $this->secretKey,
        ]);

        $this->checkHttpResponse($response, 'generate token');

        $data = $response->json();
        $accessToken  = $data['accessToken'] ?? null;
        $refreshToken = $data['refreshToken'] ?? null;
        $expiresIn    = $data['accessTokenExpireDateTime'] ?? null;

        if (! $accessToken) {
            throw new RuntimeException('جیبیت: دریافت توکن دسترسی ناموفق بود');
        }

        // کش کردن توکن‌ها (access token برای ۲۳ ساعت، refresh token برای ۴۷ ساعت)
        if ($expiresIn) {
            $ttl = max(1, (int) ((strtotime($expiresIn) - time()) / 60) - 60); // ۱ ساعت قبل از انقضا
            Cache::put(self::TOKEN_CACHE_KEY, $accessToken, $ttl);
        } else {
            Cache::put(self::TOKEN_CACHE_KEY, $accessToken, 1380); // ۲۳ ساعت پیش‌فرض
        }

        if ($refreshToken) {
            Cache::put(self::REFRESH_TOKEN_CACHE_KEY, $refreshToken, 2820); // ۴۷ ساعت
        }

        return $accessToken;
    }

    /**
     * رفرش توکن دسترسی با refresh token.
     */
    private function refreshAccessToken(string $refreshToken): string
    {
        $url = "{$this->baseUrl}/tokens/refresh";

        $response = $this->httpClient()->post($url, [
            'accessToken'  => (string) Cache::get(self::TOKEN_CACHE_KEY, ''),
            'refreshToken' => $refreshToken,
        ]);

        $this->checkHttpResponse($response, 'refresh token');

        $data = $response->json();
        $accessToken  = $data['accessToken'] ?? null;
        $newRefreshToken = $data['refreshToken'] ?? null;
        $expiresIn    = $data['accessTokenExpireDateTime'] ?? null;

        if (! $accessToken) {
            throw new RuntimeException('جیبیت: رفرش توکن ناموفق بود');
        }

        if ($expiresIn) {
            $ttl = max(1, (int) ((strtotime($expiresIn) - time()) / 60) - 60);
            Cache::put(self::TOKEN_CACHE_KEY, $accessToken, $ttl);
        } else {
            Cache::put(self::TOKEN_CACHE_KEY, $accessToken, 1380);
        }

        if ($newRefreshToken) {
            Cache::put(self::REFRESH_TOKEN_CACHE_KEY, $newRefreshToken, 2820);
        }

        return $accessToken;
    }

    /**
     * ایجاد درخواست پرداخت (Create Purchase).
     *
     * @param int    $amount        مبلغ به ریال (IRR)
     * @param string $description   توصیف پرداخت
     * @param string|null $nationalCode کد ملی پرداخت‌کننده
     * @param string|null $mobile       موبایل پرداخت‌کننده
     * @param string|null $email        ایمیل پرداخت‌کننده
     * @return array{purchaseId: string, authority: string, redirect_url: string}
     */
    public function request(int $amount, string $description = '', ?string $nationalCode = null, ?string $mobile = null, ?string $email = null, ?string $callbackUrl = null): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('درگاه جیبیت فعال نیست یا پیکربندی اتصال نشده است.');
        }

        if ($amount < 5000) {
            throw new RuntimeException('مبلغ پرداخت باید حداقل ۵۰۰۰ ریال باشد.');
        }

        // از host واقعی همان درخواست استفاده می‌کنیم تا localhost و
        // 127.0.0.1 باعث از دست رفتن cookie/session کاربر نشوند.
        $callbackUrl = $callbackUrl ?: route('payment.jibit.callback');

        $settings    = Setting::all()->pluck('value', 'key');
        $description = $description ?: (string) ($settings->get('jibit_gateway_name') ?? 'پرداخت آنلاین — جیبیت');
        $clientRef   = 'order_' . uniqid(); // مرجع منحصر به فرد برای هر تراکنش

        $body = [
            'amount'              => $amount,
            'currency'            => 'IRR',
            'callbackUrl'         => $callbackUrl,
            'clientReferenceNumber' => $clientRef,
            'userIdentifier'      => $this->apiKey, // یا شناسه کاربر
            'description'         => $description,
            'wage'                => 0, // کارمزد درگاه (اختیاری)
        ];

        if ($nationalCode) {
            $body['payerNationalCode'] = $nationalCode;
        }
        if ($mobile) {
            $body['payerMobileNumber'] = $mobile;
        }
        if ($email) {
            $body['additionalData'] = ['email' => $email];
        }

        $accessToken = $this->getAccessToken();

        $response = $this->httpClient()
            ->withToken($accessToken)
            ->post("{$this->baseUrl}/purchases", $body);

        $this->checkHttpResponse($response, 'create purchase');

        $decoded = $response->json();
        $data = is_array($decoded) ? $decoded : [];

        Log::debug('Jibit create purchase response', [
            'body'   => $body,
            'status' => $response->getStatusCode(),
            'data'   => $data,
        ]);

        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $purchaseId = $payload['purchaseId'] ?? $payload['purchaseIdStr'] ?? null;
        $pspSwitchingUrl = $payload['pspSwitchingUrl'] ?? null;

        if (! $purchaseId || ! $pspSwitchingUrl) {
            $code = $this->extractErrorCode($data);
            $msg  = $this->extractErrorMessage($data);
            throw new RuntimeException("جیبیت: {$msg} (کد: {$code})");
        }

        return [
            'purchaseId'   => (string) $purchaseId,
            'authority'    => (string) $purchaseId, // برای سازگاری با کنترلر موجود
            'redirect_url' => $pspSwitchingUrl,
        ];
    }

    /**
     * تأیید پرداخت (Verify Purchase).
     *
     * @return array{ref_id: string, purchaseId: string, code: int, status: string}
     */
    public function verify(string $purchaseId, int $amount): array
    {
        $accessToken = $this->getAccessToken();

        $response = $this->httpClient()
            ->withToken($accessToken)
            ->post("{$this->baseUrl}/purchases/{$purchaseId}/verify", [
                'amount' => $amount,
            ]);

        $this->checkHttpResponse($response, 'verify purchase');

        $decoded = $response->json();
        $data = is_array($decoded) ? $decoded : [];

        Log::debug('Jibit verify purchase response', [
            'purchaseId' => $purchaseId,
            'amount'     => $amount,
            'status'     => $response->getStatusCode(),
            'data'       => $data,
        ]);

        $payload = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $code = $payload['code'] ?? $payload['status'] ?? -1;
        $status = strtoupper((string) ($payload['status'] ?? 'UNKNOWN'));

        // کدهای موفقیت: 100, 101 (مشابه زرین‌پال) یا status = SUCCESS
        $successCodes = [100, 101];
        $successStatuses = ['SUCCESS', 'SUCCESSFUL'];

        $isSuccess = in_array((string) $code, array_map('strval', $successCodes), true)
            || in_array($status, $successStatuses, true);

        if (! $isSuccess) {
            $msg = $this->extractErrorMessage($data);
            throw new RuntimeException("جیبیت: {$msg} (کد: {$code}, وضعیت: {$status})");
        }

        $refId = $payload['refId'] ?? $payload['pspReferenceNumber'] ?? $payload['referenceId'] ?? '';

        return [
            'ref_id'     => (string) $refId,
            'purchaseId' => $purchaseId,
            'code'       => $code,
            'status'     => $status,
        ];
    }

    /**
     * استعلام وضعیت خرید (Filter Purchase) - برای بررسی وضعیت در صورت UNKNOWN.
     */
    public function inquiry(string $purchaseId): array
    {
        $accessToken = $this->getAccessToken();

        $response = $this->httpClient()
            ->withToken($accessToken)
            ->get("{$this->baseUrl}/purchases", [
                'purchaseIds' => [$purchaseId],
            ]);

        $this->checkHttpResponse($response, 'inquiry purchase');

        $data = $response->json();
        return is_array($data) ? $data : [];
    }

    /**
     * کلاینت HTTP با تنظیمات مشترک.
     */
    private function httpClient()
    {
        $client = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->timeout(30);

        if (app()->environment('local')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client;
    }

    /**
     * بررسی پاسخ HTTP و پرتاب Exception در صورت خطا.
     */
    private function checkHttpResponse(\Illuminate\Http\Client\Response $response, string $action): void
    {
        $statusCode = $response->getStatusCode();
        $rawContent = $this->extractRawContent($response);

        if ($statusCode >= 400) {
            // جیبیت در برخی خطاها بدنه خالی، text/plain یا JSON نامعتبر برمی‌گرداند.
            // Response::json() در این حالت null است و نباید به متدهای array داده شود.
            $decoded = $response->json();
            $data = is_array($decoded) ? $decoded : [];
            $code = $this->extractErrorCode($data);
            $msg  = $this->extractErrorMessage($data, $rawContent);

            Log::error("Jibit {$action} failed (HTTP {$statusCode})", [
                'status'  => $statusCode,
                'content' => $rawContent,
                'data'    => $data,
            ]);

            throw new RuntimeException("جیبیت: {$msg} (کد HTTP: {$statusCode}, کد خطا: {$code})");
        }
    }

    private function extractErrorCode(?array $data): string
    {
        $data ??= [];

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

    private function extractErrorMessage(?array $data, string $rawContent = ''): string
    {
        $data ??= [];

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

        $rawContent = trim($rawContent);
        if ($rawContent !== '' && ! str_starts_with($rawContent, '<')) {
            return mb_substr($rawContent, 0, 300);
        }

        return 'خطای نامشخص';
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
