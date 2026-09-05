<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InboundResource\Pages;
use App\Models\Inbound;
use App\Services\XUIService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InboundResource extends Resource
{
    protected static ?string $model = Inbound::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'لیست ورودی ها(Inbounds)';
    protected static ?string $modelLabel = 'اینباند';

    protected static ?string $pluralModelLabel = 'ورودی ها (Inbounds)';
    protected static ?string $navigationGroup = 'تنظیمات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->label('عنوان دلخواه برای اینباند')
                    ->required()
                    ->helperText('یک نام مشخص برای این اینباند انتخاب کنید (مثلاً: VLESS WS آلمان).'),

                Forms\Components\Textarea::make('inbound_data')
                    ->label('اطلاعات JSON اینباند')
                    ->required()
                    ->json() // ولیدیشن برای اطمینان از صحت ساختار JSON
                    ->rows(20)
                    ->helperText('این اطلاعات به صورت خودکار از سرور X-ui دریافت میشود.')


                    ->afterStateHydrated(function (Forms\Components\Textarea $component, $state) {
                        if (is_array($state)) {
                            $component->state(json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        }
                    })

                    ->dehydrateStateUsing(function ($state) {
                        if (is_string($state)) {
                            return json_decode($state, true);
                        }
                        return $state;
                    }),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->description('اطلاعات این صفحه به صورت خودکار از سرور X-UI دریافت خواهد شد.')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان'),


                Tables\Columns\TextColumn::make('panel_id')
                    ->label('ID در پنل')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('remark')
                    ->label('Remark'),


                Tables\Columns\TextColumn::make('inbound_data.protocol')
                    ->label('پروتکل')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('inbound_data.port')
                    ->label('پورت'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('وضعیت')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),

            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->button()->label(''),
                Tables\Actions\DeleteAction::make()->button()->label(''),
            ])
            ->heading(function () {
                $value = \App\Models\Setting::where('key', 'xui_default_inbound_id')->value('value');

                if (is_null($value) || $value === '') {
                    return 'ابتدا به منوی تنظیمات به بخش "راه اندازی اولیه پنل" مراجعه کنید';
                }

                // در غیر این صورت مقدار واقعی را نمایش بده
                return 'ID ورودی پیش فرض پنل: ' . $value;
            })
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);

    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInbounds::route('/'),
//          'create' => Pages\CreateInbound::route('/create'),
        ];
    }
}
