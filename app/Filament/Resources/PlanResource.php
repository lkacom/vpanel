<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Inbound;
use App\Models\Plan;
use App\Models\Setting;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'تنظیمات';

    protected static ?string $navigationLabel = ' پکیج های فروش';

    protected static ?string $pluralModelLabel = ' پکیج ها';

    protected static ?string $modelLabel = 'پکیج جدید';

    /**
     * لیست Inbound های فعال از دیتابیس
     *
     * @return array<string, string>
     */
    private static function inboundOptions(): array
    {
        return Inbound::query()
            ->whereNotNull('inbound_data')
            ->get()
            ->filter(fn (Inbound $inbound): bool => $inbound->is_active && $inbound->panel_id !== null)
            ->mapWithKeys(fn (Inbound $inbound): array => [
                $inbound->panel_id => $inbound->dropdown_label,
            ])
            ->all();
    }

    /**
     * نوع پنل فعلی
     */
    private static function panelType(): string
    {
        return (string) Setting::where('key', 'panel_type')->value('value');
    }

    public static function form(Schema $schema): Schema
    {
        $panelType = static::panelType();
        $isSanaei  = $panelType === 'sanaei';
        $options   = static::inboundOptions();

        return $schema->schema([
            Forms\Components\TextInput::make('name')
                ->label('نام سرویس')
                ->inlineLabel()
                ->required(),

            Forms\Components\TextInput::make('price')
                ->label('قیمت')
                ->numeric()
                ->inlineLabel()
                ->required(),

            Forms\Components\Textarea::make('features')
                ->label('ویژگی‌ها')
                ->required()
                ->inlineLabel()
                ->helperText('هر ویژگی را در یک خط جدید بنویسید.'),

            Forms\Components\TextInput::make('volume_gb')
                ->label('حجم (GB)')
                ->numeric()
                ->required()
                ->inlineLabel()
                ->default(30)
                ->helperText('حجم سرویس را به گیگابایت وارد کنید.'),

            Forms\Components\Select::make('duration_days')
                ->label('مدت اعتبار')
                ->options([
                    30  => '۳۰ روز (۱ ماهه)',
                    90  => '۹۰ روز (۳ ماهه)',
                    365 => '۳۶۵ روز (۱ ساله)',
                ])
                ->required()
                ->inlineLabel()
                ->default(30)
                ->native(false),

            // ── پنل ثنایی v3+: می‌توان چند Inbound به یک کلاینت Attach کرد ──
            // کلاینت از هر کدام که بخواهد استفاده می‌کند (Attached Inbounds)
            $isSanaei
                ? Forms\Components\Select::make('inbound_ids')
                    ->label('Inbound های پکیج')
                    ->options($options)
                    ->multiple()           // چند Inbound قابل انتخاب
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required()
                    ->helperText('پنل ثنایی v3+: کلاینت به همه Inbound های انتخاب‌شده متصل می‌شود و از هر کدام می‌تواند استفاده کند.')
                : Forms\Components\Select::make('inbound_ids')
                    ->label('Inbound پکیج')
                    ->options($options)
                    ->multiple(false)      // پنل علیرضا/مرزبان: فقط یک Inbound
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required()
                    ->helperText(
                        $panelType === 'txui'
                            ? 'پنل علیرضا: یک Inbound انتخاب کنید.'
                            : 'یک Inbound انتخاب کنید.'
                    )
                    // برای select تکی، مقدار را به آرایه تبدیل کن
                    ->afterStateHydrated(function (Forms\Components\Select $component, $state) {
                        // اگر مقدار ذخیره‌شده آرایه است، اولین عنصر را برگردان
                        if (is_array($state) && count($state) === 1) {
                            $component->state($state[0]);
                        }
                    })
                    ->dehydrateStateUsing(function ($state): array {
                        // ذخیره به عنوان آرایه یک‌عنصری
                        if (is_array($state)) {
                            return $state;
                        }
                        return $state !== null && $state !== '' ? [(string) $state] : [];
                    }),

            Forms\Components\Toggle::make('is_popular')
                ->label('پلن محبوب است؟')
                ->inlineLabel()
                ->helperText('این پلن به صورت ویژه نمایش داده خواهد شد.'),

            Forms\Components\Toggle::make('is_active')
                ->label('فعال')
                ->inlineLabel()
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام پکیج'),

                Tables\Columns\TextColumn::make('price')
                    ->label('قیمت')
                    ->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->suffix(' تومان'),

                Tables\Columns\BooleanColumn::make('is_popular')->label('محبوب'),
                Tables\Columns\BooleanColumn::make('is_active')->label('فعال'),

                Tables\Columns\TextColumn::make('duration_days')
                    ->label('مدت اعتبار')
                    ->formatStateUsing(fn ($state, $record) => $record->duration_label)
                    ->sortable(),

//                Tables\Columns\TextColumn::make('inbound_ids')
//                    ->label('Inbound')
//                    ->formatStateUsing(function ($state, Plan $record): string {
//                        $ids = $record->effective_inbound_ids;
//                        if (empty($ids)) {
//                            return 'انتخاب نشده';
//                        }
//                        $labels = [];
//                        foreach ($ids as $id) {
//                            $title = Inbound::query()
//                                ->where('inbound_data->id', $id)
//                                ->value('title');
//                            $labels[] = $title ?? "ID: {$id}";
//                        }
//                        return implode(' | ', $labels);
//                    }),

                Tables\Columns\TextColumn::make('duration_days')
                    ->label('اعتبار')
                    ->suffix(' روز')
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
                Actions\EditAction::make()->button()->label(''),
                Actions\DeleteAction::make()->button()->label(''),
            ])
            ->bulkActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
            'index'  => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
        ];
    }
}
