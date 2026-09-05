<?php

namespace Modules\TelegramBot\Filament\Resources\TelegramBotSettingResource\Pages;

use Modules\TelegramBot\Filament\Resources\TelegramBotSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTelegramBotSettings extends ListRecords
{
    protected static string $resource = TelegramBotSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
