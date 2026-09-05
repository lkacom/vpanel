<?php

namespace Modules\TelegramBot\Filament\Resources\TelegramBotSettingResource\Pages;

use Modules\TelegramBot\Filament\Resources\TelegramBotSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;

class CreateTelegramBotSetting extends CreateRecord
{
    protected static string $resource = TelegramBotSettingResource::class;


    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }


    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title(' ثبت شد');

    }
}
