<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SetTelegramWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:set-webhook';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the Telegram bot webhook URL based on your configuration.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Attempting to set Telegram webhook...');
        Log::info('Running telegram:set-webhook command...');


        $appUrl = config('app.url');


        $botToken = Setting::where('key', 'telegram_bot_token')->value('value');


        if (!$appUrl || $appUrl === 'http://localhost') {
            $errorMessage = 'Error: APP_URL is not set correctly in your .env file. It should be your public domain (e.g., https://yourdomain.com).';
            $this->error($errorMessage);
            Log::error($errorMessage);
            return 1;
        }

        if (!$botToken) {
            $errorMessage = 'Error: TELEGRAM_BOT_TOKEN is not set in your site settings.';
            $this->error($errorMessage);
            $this->warn('Please configure the bot token in your Filament admin panel first.');
            Log::error($errorMessage);
            return 1;
        }

        // ۴. ساخت URL وب‌هوک
        $webhookUrl = rtrim($appUrl, '/') . '/webhooks/telegram';
        $telegramApiUrl = "https://api.telegram.org/bot{$botToken}/setWebhook?url={$webhookUrl}";

        $this->line("Setting webhook to: " . $webhookUrl);
        Log::info("Attempting to set webhook to: " . $webhookUrl);

        // ۵. ارسال درخواست به تلگرام
        try {
            $response = Http::get($telegramApiUrl);

            if ($response->successful() && $response->json('ok') === true) {
                $successMessage = '✅ Webhook set successfully! Description: ' . $response->json('description');
                $this->info($successMessage);
                Log::info($successMessage);
            } else {
                $errorMessage = '❌ Failed to set webhook. Reason: ' . ($response->json('description') ?? 'Unknown error');
                $this->error($errorMessage);
                Log::error($errorMessage, $response->json() ?? []);
            }
        } catch (\Exception $e) {
            $errorMessage = 'An exception occurred while trying to connect to the Telegram API: ' . $e->getMessage();
            $this->error($errorMessage);
            Log::critical($errorMessage);
        }

        return 0;
    }
}

