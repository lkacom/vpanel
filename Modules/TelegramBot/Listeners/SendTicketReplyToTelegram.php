<?php

namespace Modules\TelegramBot\Listeners;

use Modules\Ticketing\Events\TicketReplied;
use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\FileUpload\InputFile;

class SendTicketReplyToTelegram
{
    public function handle(TicketReplied $event): void
    {
        $reply = $event->reply;
        $ticket = $reply->ticket;
        $chatId = $ticket->user->telegram_chat_id ?? null;

        if (!$chatId) {
            return;
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✍️ پاسخ به تیکت', 'callback_data' => "reply_ticket_{$ticket->id}"],
                    ['text' => '❌ بستن تیکت', 'callback_data' => "close_ticket_{$ticket->id}"],
                ],
            ],
        ];


        $message = "📩 پاسخ جدید به تیکت شما:\n\n"
            . "📝 موضوع: {$ticket->subject}\n"
            . "💬 پاسخ: {$reply->message}";

        // اگر ضمیمه داشت
        if ($reply->attachment_path) {
            $filePath = storage_path('app/public/' . $reply->attachment_path);

            if (file_exists($filePath)) {
                Telegram::sendDocument([
                    'chat_id' => $chatId,
                    'document' => InputFile::create($filePath, basename($filePath)), // ✅ این خط مهمه
                    'caption' => $message,
                    'reply_markup' => json_encode($keyboard),
                    'parse_mode' => 'Markdown',
                ]);
                return;
            }
        }

        // اگر فایل نبود فقط متن ارسال کن
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $message,
            'reply_markup' => json_encode($keyboard),
            'parse_mode' => 'Markdown',
        ]);
    }
}
