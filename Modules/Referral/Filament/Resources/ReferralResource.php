<?php

namespace Modules\Referral\Filament\Resources;

use App\Models\User;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Referral\Filament\Resources\ReferralResource\Pages;

class ReferralResource extends Resource
{
    protected static ?string $model = User::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'گزارش دعوت‌ها';
    protected static ?string $modelLabel = 'کاربر';
    protected static ?string $pluralModelLabel = 'گزارش دعوت‌ها';
    protected static ?string $slug = 'referrals';

    protected static string|\UnitEnum|null $navigationGroup = 'مدیریت افزونه‌ها';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نام کاربر')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->label('ایمیل')
                    ->searchable(),
                Tables\Columns\TextColumn::make('referral_code')
                    ->label('کد دعوت')
                    ->searchable(),
                Tables\Columns\TextColumn::make('referrals_count')
                    ->label('تعداد دعوت موفق')
                    ->counts('referrals')
                    ->sortable(),
            ])
            ->defaultSort('referrals_count', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferrals::route('/'),
        ];
    }


    public static function canCreate(): bool
    {
        return false;
    }
}




