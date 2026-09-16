<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class JibitService
{
    protected $endpoints = [
        'request' => 'https://napi.jibit.ir/ppg/v3/static/request',
        'gateway' => 'https://api.jibit.com'
    ];

    public function request($body)
    {
        try {
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
            
            $data = $response->json();
            
            // Initialize authority to avoid undefined variable error
            $authority = null;
            
            if ($data !== null && is_array($data)) {
                try {
                    $authority = $this->extractAuthority($data);
                } catch (Exception $e) {
                    Log::error('Jibit request failed', [
                        'endpoint' => $this->endpoints['request'],
                        'body'     => $body,
                        'response' => $data,
                        'code'     => 'unknown',
                        'msg'      => 'Extraction error: ' . $e->getMessage(),
                    ]);
                    throw new RuntimeException("جیبیت: خطا در استخراج Authority (کد: unknown)");
                }
            } else {
                Log::error('Jibit request failed', [
                    'endpoint' => $this->endpoints['request'],
                    'body'     => $body,
                    'response' => $data,
                    'code'     => 'unknown',
                    'msg'      => 'Invalid response data structure',
                ]);
            }

            if ($authority === null) {
                // This block executes when authority is null
                
                $code = $this->extractErrorCode($data);
                $msg  = $this->extractErrorMessage($data);
                Log::error('Jibit request failed', [
                    'endpoint' => $this->endpoints['request'],
                    'body'     => $body,
                    'response' => $rawContent, // Use raw content instead of JSON data
                    'code'     => $code,
                    'msg'      => $msg,
                ]);
                throw new RuntimeException("جیبیت: {$msg} (کد: {$code})");
            }

            // Critical: Ensure authority is always defined for return value
            if ($authority === null) {
                throw new RuntimeException("جیبیت: Authority cannot be determined");
            }
            
            // Critical: Ensure authority is always defined for return value
            if ($authority === null) {
                throw new RuntimeException("جیبیت: Authority cannot be determined");
            }

        } catch (GuzzleException $e) {
            Log::error('Jibit request failed', [
                'endpoint' => $this->endpoints['request'],
                'body'     => $body,
                'response' => $data,
                'code'     => 'unknown',
                'msg'      => 'Guzzle error: ' . $e->getMessage(),
            ]);
            throw new RuntimeException("جیبیت: خطا در ارسال درخواست (کد: unknown)");
        } catch (RuntimeException $e) {
            Log::error('Jibit request failed', [
                'endpoint' => $this->endpoints['request'],
                'body'     => $body,
                'response' => $data,
                'code'     => 'unknown',
                'msg'      => 'Runtime error: ' . $e->getMessage(),
            ]);
            throw new RuntimeException("جیبیت: {$e->getMessage()}");
        }
    }

    private function extractAuthority($data)
    {
        // Extract authority from the response data
        return $data['authority'] ?? null;
    }

    private function getResponseHeaders($response)
    {
        return $response->headers->all();
    }

    private function extractRawContent($response)
    {
        return $response->getBody()->getContents();
    }
}