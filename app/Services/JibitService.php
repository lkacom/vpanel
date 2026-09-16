<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * کلاس پیاده‌سازی درگاه پرداخت جیبیت.
 *
 * بر اساس مستندات API جیبیت — استفاده از اجرای اکتو استیشن با ساختار
 * مبلغ (invoice) و تایید از طریق callback. تنظیمات کاربر از دیتابیس Setting
 * ذخیره می‌شوند تا قابلیت فعال/غیرفعال‌سازی در پنل admin موجود باشد.
 */
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
        $this->apiKey      = (string) ($settings->get('jibit_api_key') ?? '');
        $this->secretKey   = (string) ($settings->get('jibit_secret_key') ?? '');
        $this->sandbox     = filter_var($settings->get('jibit_sandbox') ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->currency    = (string) ($settings->get('jibit_currency') ?? 'IRT');
        $this->isLive      = ! $this->sandbox;
        $this->endpoints   = self::ENDPOINTS[$this->isLive ? 'live' : 'sandbox'];
    }

    /** آیا درگاه فعال و دارای متغیرهای اتصال است؟ */
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
     * ایجاد درخواست پرداخت جیبیت با ساختار مبلغ (invoice).
     *
     * @param int  $amount         مبلغ پرداخت به تومان
     * @param string $description     توصیف پرداخت (اختیاری)
     * @param string $nationalCode    شناسه ملی کاربر — کنترل هویت (اختیاری)
     * @param string $mobile         شماره موبایل کاربر — کنترل هویت (اختیاری)
     * @param string $email          ایمیل کاربر — کنترل هویت (اختیاری)
     * @return array{authority: string, redirect_url: string}
     */
    public function request(int $amount, string $description = '', ?string $nationalCode = null, ?string $mobile = null, ?string $email = null): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('درگاه جیبیت فعال نیست یا پیکربندی اتصال نشده است.');
        }

        // callback_url همیشه از route پروژه تولید می‌شود — نیازی به ذخیره در DB نیست
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
            // به توضیحات API فعلی درگاه جیبیت PPG مبتنی:
            // - هویت پرداخت از گیردها مستقیم استخراج می‌شود (payerNationalCode, payerMobileNumber)
            // - داده‌های اضافی تحت additionalData قرار می‌گیرند
            'payerNationalCode'   => $nationalCode,
            'payerMobileNumber'   => $mobile,
            'additionalData'      => [
                'email'           => $email,
            ],
        ];

        $response = $this->post($this->endpoints['request'], $body);

        // ثبت کامل پاسخ برای بررسی و درک دلیل خطا
        // محتوای پاسخ را مستقیم از Guzzle response استخراج می‌کنیم تا از خطای
        // «Call to undefined method getContent()» جلوگیری شود
        $rawContent = $this->extractRawContent($response);

        Log::debug('Jibit request response', [
            'endpoint' => $this->endpoints['request'],
            'body'     => $body,
            'status'   => $response->getStatusCode(),
            'headers'  => $this->getResponseHeaders($response),
            'content'  => $rawContent,
        ]);

        $data     = $response->json();

        // استخراج authority از ساختارهای مختلف پاسخ API
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

    /**
     * ساختار لینک جداول پرداخت را بر اساس نوع authority محاسبه می‌کند.
     *
     * اگر authority یک URL کامل (مثل pspSwitchingUrl) است، آن را مستقیم استفاده می‌کنیم.
     * در غیر این حال، authority یک شناسه purchaseId است و با آدرس gateway به آن اضافه می‌کنیم.
     */
    private function constructRedirectUrl(string $authority): string
    {
        $isFullUrl = str_starts_with($authority, 'http://') || str_starts_with($authority, 'https://');

        return $isFullUrl ? $authority : $this->endpoints['gateway'] . $authority;
    }

    /**
     * از پاسخ API authority را از ساختارهای مختلف استخراج می‌کند.
     *
     * بر اساس توضیحات فعلی درگاه جیبیت PPG (version 3):
     * - پاسخ Create Purchase از `pspSwitchingUrl` و `purchaseId` استفاده می‌کند.
     *
     * ساختارهای مختلفی ممکن است در پاسخ API پیش آمده باشد:
     *  1. data.pspSwitchingUrl یا data.purchaseId
     *  2. success.data.pspSwitchingUrl یا success.data.purchaseId
     *  3. status.data.pspSwitchingUrl یا status.data.purchaseId
     *  4. result.data.pspSwitchingUrl یا result.data.purchaseId
     *  5. pspSwitchingUrl یا purchaseId در سطح اولیه پاسخ
     *  6. data array با pspSwitchingUrl یا purchaseId در یک عنصر
     */
    private function extractAuthority(array $data): ?string
    {
        // ساختار اصلی: API فعلی از pspSwitchingUrl و purchaseId استفاده می‌کند
        $pspSwitchingUrl = $data['data']['pspSwitchingUrl'] ?? null;
        $purchaseId      = $data['data']['purchaseId'] ?? null;

        if ($pspSwitchingUrl !== null || $purchaseId !== null) {
            return $pspSwitchingUrl ?? $purchaseId;
        }

        // ساختار با success/status/result و data در داخل
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

        // ساختار با pspSwitchingUrl یا purchaseId در سطح اولیه پاسخ
        if (isset($data['pspSwitchingUrl']) || isset($data['purchaseId'])) {
            return $data['pspSwitchingUrl'] ?? $data['purchaseId'] ?? null;
        }

        // ساختار با array data
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

    /**
     * از پاسخ API کد خطا را استخراج می‌کند.
     *
     * ساختار خطا فعلی:
     *   {"fingerprint": "...", "errors": [{"code": "...", "message": "..."}]}
     */
    private function extractErrorCode(array $data): string
    {
        // ساختار فعلی: errors เป็น array از خطاهای متعدد
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_array($error) && isset($error['code'])) {
                    return (string) $error['code'];
                }
            }
        }

        // ساختارهای دیگر (برای مقاومت در ساختارهای مختلف)
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

    /**
     * از پاسخ API پیام خطا را استخراج می‌کند.
     *
     * ساختار خطا فعلی:
     *   {"fingerprint": "...", "errors": [{"code": "...", "message": "..."}]}
     */
    private function extractErrorMessage(array $data): string
    {
        // ساختار فعلی: errors เป็น array از خطاهای متعدد
        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_array($error) && isset($error['message'])) {
                    return (string) $error['message'];
                }
            }
        }

        // ساختارهای دیگر (برای مقاومت در ساختارهای مختلف)
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

    /**
     * استخراج متغیرات header از پاسخ HTTP با مقاومت در ساختارهای مختلف.
     *
     * برخی محیط‌ها ممکن است `headers()` به array برمی‌گرداند نه `HeaderBag` مقدار.
     * بنابراین چندین ساختار را در نظر می‌گیریم تا خطا جلوگیری شود.
     */
    private function getResponseHeaders($response): array
    {
        $headersResult = $response->headers();

        // اگر headers() یک HeaderBag مقدار برمی‌گرداند، آن را با all() استخراج می‌کنیم
        if (is_object($headersResult) && method_exists($headersResult, 'all')) {
            return $headersResult->all();
        }

        // اگر headers() به array برمی‌گرداند، آن را مستقیم استفاده می‌کنیم
        if (is_array($headersResult)) {
            return $headersResult;
        }

        // فallback: از метод getHeaders() استفاده کنیم که اغلب array استخراج می‌کند.
        // مطمئن شویم هرگز مؤثر (HeaderBag) را به عنوان array برمی‌گردانیم تا خطا
        // «Call to a member function all() on array» رخ ندهد.
        if (method_exists($response, 'getHeaders')) {
            $headersFromGetHeaders = $response->getHeaders();

            if (is_array($headersFromGetHeaders)) {
                return $headersFromGetHeaders;
            }

            // اگر result bukan array (مثلاً HeaderBag) است، empty array استفاده می‌کنیم
            return [];
        }

        return [];
    }

    /**
     * تأیید پرداخت جیبیت از طریق callback.
     *
     * @return array{ref_id: string, authority: string, code: int}
     */
    public function verify(string $authority, int $amount): array
    {
        $response = $this->post($this->endpoints['verify'], [
            // به توضیحات API فعلی مبتنی:
            // - شناسه خرید از گیردها مستقیم استخراج می‌شود (clientReferenceNumber)
            // - لینک callback از callbackUrl استفاده می‌شود
            'clientReferenceNumber' => $authority,
            'amount'       => $amount,
            'callbackUrl' => route('payment.jibit.callback'),
        ]);

        $data = $response->json();
        $code = $data['data']['code'] ?? $data['data']['status'] ?? -1;

        if (! in_array($code, [100, 101, 200, 201])) {
            $msg = $this->extractErrorMessage($data);
            throw new RuntimeException("جیبیت: {$msg} (کد: {$code})");
        }

        $refId = $data['data']['ref_id'] ?? $data['data']['reference_id'] ?? '';

        return [
            'ref_id'   => (string) $refId,
            'authority' => $authority,
            'code'     => $code,
        ];
    }

    /**
     * HTTP POST با verify_peer=false برای محیط‌های local/dev (ویندوز/WAMP)
     * که CA bundle ندارند.
     */
    private function post(string $url, array $body): \Illuminate\Http\Client\Response
    {
        $client = Http::withHeaders([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ])->timeout(30);

        // در محیط local روی ویندوز (WAMP) SSL CA bundle وجود ندارد
        // این تنها راه‌حل عملی برای توسعه محلی است
        if (app()->environment('local')) {
            $client = $client->withOptions(['verify' => false]);
        }

        return $client->post($url, $body);
    }

    /**
     * محتوای پاسخ HTTP را مستقیم از Guzzle response استخراج می‌کند.
     *
     * در برخی محیط‌ها (مثل WAMP روی ویندوز) اشتباه «Call to undefined method
     * GuzzleHttp\Psr7\Response::getContent()» رخ می‌دهد که عامل آن است که محتوای
     * پاسخ از گزمه‌ای `GuzzleHttp\Psr7\Response` گرفته شود. برای اطمینان از
     * درست بودن محتوای پاسخ، از روش‌های جایگزین (`getBody`، `getBodyAsArray`)
     * استفاده می‌کنیم تا از خطای این نوع جلوگیری کنیم.
     */
    private function extractRawContent(\Illuminate\Http\Client\Response $response): string
    {
        // در برخی محیط‌ها (مثل WAMP روی ویندوز) از لاراول Response کلاس،
        // اشتباه «Call to undefined method GuzzleHttp\Psr7\Response::getContent()»
        // رخ می‌دهد. بنابراین برای اطمینان از استخراج صحیح محتوای پاسخ،
        // مستقیم از گزمه `getBody()` استفاده می‌کنیم تا از خطای این نوع جلوگیری کنیم.
        if (method_exists($response, 'getBody')) {
            try {
                $body = $response->getBody();
                if ($body !== null) {
                    return (string) $body;
                }
            } catch (\Throwable $e) {
                // اگر getBody() هم خطا می‌دهد، محتوای خالی برمی‌گردیم
            }
        }

        return '';
    }
}
