<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'مدیریت محصولات';

    protected static ?string $navigationLabel = ' پکیج های فروش';
    protected static ?string $pluralModelLabel = ' پکیج ها';
    protected static ?string $modelLabel = 'پکیج جدید';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
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
                        30 => '۳۰ روز (۱ ماهه)',
                        90 => '۹۰ روز (۳ ماهه)',
                        365 => '۳۶۵ روز (۱ ساله)',
                    ])
                    ->required()
                    ->inlineLabel()
                    ->default(30)
                    ->native(false) ,
        //========================================================

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
                    ->label('قیمت کل')
                    ->formatStateUsing(fn ($record) =>
                        number_format($record->price) . ' تومان' .
                        ($record->duration_days > 30 ? ' (' . number_format($record->monthly_price) . ' تومان/ماه)' : '')
                    ),
                Tables\Columns\BooleanColumn::make('is_popular')->label('محبوب'),
                Tables\Columns\BooleanColumn::make('is_active')->label('فعال'),
                Tables\Columns\TextColumn::make('duration_days')
                    ->label('مدت اعتبار')
                    ->formatStateUsing(fn ($state, $record) => $record->duration_label)
                    ->sortable(),

                Tables\Columns\TextColumn::make('monthly_price')
                    ->label('قیمت ')
                    ->formatStateUsing(fn ($record) => number_format($record->monthly_price) . ' تومان')
                    ->sortable(),



            ])


            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make()->button()->label(''),
                Tables\Actions\DeleteAction::make()->button()->label(''),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
        ];
    }
}
