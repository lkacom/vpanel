<?php

namespace Modules\TelegramBot\Filament\Resources;

use Modules\TelegramBot\Filament\Resources\TelegramBotSettingResource\Pages;
use App\Models\TelegramBotSetting;
use Filament\Resources\Resource;
use Filament\Forms\Form;

class TelegramBotSettingResource extends Resource
{
    protected static ?string $model = TelegramBotSetting::class;

    // فقط navigation رو اینجا بذار
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-bottom-center-text';
    protected static ?string $navigationLabel = 'محتوای ربات تلگرام';
    protected static ?string $navigationGroup = 'مدیریت افزونه‌ها';

    public static function form(Form $form): Form
    {
        return $form;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTelegramBotSettings::route('/'),
        ];
    }
}

