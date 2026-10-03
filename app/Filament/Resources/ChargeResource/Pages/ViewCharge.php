<?php

namespace App\Filament\Resources\ChargeResource\Pages;

use App\Filament\Resources\ChargeResource;
use App\Models\Order;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Storage;
use Morilog\Jalali\Jalalian;

class ViewCharge extends ViewRecord
{
    protected static string $resource = ChargeResource::class;

    public function getTitle(): string
    {
        return 'بررسی درخواست شارژ #' . $this->getRecord()->getKey();
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('مشخصات درخواست')
                ->columns(3)
                ->schema([
                    TextEntry::make('user.name')->label('کاربر'),

                    TextEntry::make('user.email')->label('ایمیل کاربر')->copyable(),

                    TextEntry::make('user.balance')
                        ->label('موجودی فعلی کیف پول')
                        ->formatStateUsing(fn ($state): string => number_format((float) $state) . ' تومان'),

                    TextEntry::make('amount')
                        ->label('مبلغ درخواستی')
                        ->formatStateUsing(fn ($state): string => number_format((float) $state) . ' تومان'),

                    TextEntry::make('created_at')
                        ->label('تاریخ درخواست')
                        ->formatStateUsing(fn ($state): string => Jalalian::fromDateTime($state)->format('Y/m/d H:i')),

                    TextEntry::make('status')
                        ->label('وضعیت')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => ChargeResource::statusLabel($state))
                        ->color(fn (?string $state): string => ChargeResource::statusColor($state)),
                ]),

            Section::make('فیش واریزی')
                ->schema([
                    ImageEntry::make('card_payment_receipt')
                        ->label('')
                        ->getStateUsing(fn (Order $record): ?string => $record->receipt_url)
                        ->size(480)
                        ->visible(fn (Order $record): bool => filled($record->card_payment_receipt))
                        ->url(fn (Order $record): ?string => $record->receipt_url)
                        ->openUrlInNewTab(),

                    TextEntry::make('receipt_notice')
                        ->label('')
                        ->getStateUsing(fn (): string => 'کاربر هنوز فیشی ارسال نکرده است.')
                        ->visible(fn (Order $record): bool => blank($record->card_payment_receipt)),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ChargeResource::approveAction(),
            ChargeResource::rejectAction(),
        ];
    }
}
