<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ZarinpalService
{
    private string $merchantId;
    private bool   $sandbox;
    private bool   $active;
    private string $currency;
    private array  $endpoints;

    private const ENDPOINTS = [
        'live' => [
            'request' => 'https://api.zarinpal.com/pg/v4/payment/request.json',
            'verify'  => 'https://api.zarinpal.com/pg/v4/payment/verify.json',
            'gateway' => 'https://www.zarinpal.com/pg/StartPay/',
        ],
        'sandbox' => [
            'request' => 'https://sandbox.zarinpal.com/pg/v4/payment/request.json',
            'verify'  => 'https://sandbox.zarinpal.com/pg/v4/payment/verify.json',
            'gateway' => 'https://sandbox.zarinpal.com/pg/StartPay/',
        ],
    ];

    public function __construct()
    {
        $settings = Setting::all()->pluck('value', 'key');

        $this->active     = filter_var($settings->get('zarinpal_active') ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->merchantId = (string) ($settings->get('zarinpal_merchant_id') ?? '');
        $this->sandbox    = filter_var($settings->get('zarinpal_sandbox') ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->currency   = (string) ($settings->get('zarinpal_currency') ?? 'IRT');
        $this->endpoints  = self::ENDPOINTS[$this->sandbox ? 'sandbox' : 'live'];
    }

    /** آیا درگاه فعال و دارای merchant_id است؟ */
    public function isEnabled(): bool
    {
        return $this->active && ! empty($this->merchantId);
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * ایجاد درخواست پرداخت
     *
     * @return array{authority: string, redirect_url: string}
     */
    public function request(int $amount, string $description = '', ?string $mobile = null, ?string $email = null): array
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('درگاه زرین‌پال فعال نیست یا پیکربندی نشده است.');
        }

        // callback_url همیشه از route پروژه تولید می‌شود — نیازی به ذخیره در DB نیست
        $callbackUrl = route('payment.zarinpal.callback');

        $settings    = Setting::all()->pluck('value', 'key');
        $description = $description ?: (string) ($settings->get('zarinpal_gateway_name') ?? 'پرداخت آنلاین');

        $body = [
            'merchant_id'  => $this->merchantId,
            'amount'       => $amount,
            'currency'     => $this->currency,
            'description'  => $description,
            'callback_url' => $callbackUrl,
        ];

        if ($mobile) $body['metadata']['mobile'] = $mobile;
        if ($email)  $body['metadata']['email']  = $email;

        $response = $this->post($this->endpoints['request'], $body);
        $data     = $response->json();

        if (! isset($data['data']['authority'])) {
            $code = $data['errors']['code']    ?? 'unknown';
            $msg  = $data['errors']['message'] ?? 'خطای نامشخص';
            Log::error('ZarinPal request failed', compact('code', 'msg'));
            throw new RuntimeException("زرین‌پال: {$msg} (کد: {$code})");
        }

        $authority = $data['data']['authority'];

        return [
            'authority'    => $authority,
            'redirect_url' => $this->endpoints['gateway'] . $authority,
        ];
    }

    /**
     * تأیید پرداخت
     *
     * @return array{ref_id: string, card_pan: string|null, fee: int|null, code: int}
     */
    public function verify(string $authority, int $amount): array
    {
        $response = $this->post($this->endpoints['verify'], [
            'merchant_id' => $this->merchantId,
            'amount'      => $amount,
            'authority'   => $authority,
        ]);

        $data = $response->json();
        $code = $data['data']['code'] ?? -1;

        if (! in_array($code, [100, 101])) {
            $msg = $data['errors']['message'] ?? 'پرداخت تأیید نشد.';
            throw new RuntimeException("زرین‌پال: {$msg} (کد: {$code})");
        }

        return [
            'ref_id'   => (string) ($data['data']['ref_id'] ?? ''),
            'card_pan' => $data['data']['card_pan'] ?? null,
            'fee'      => $data['data']['fee'] ?? null,
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
}
