<?php

namespace App\Filament\Resources\ChargeResource\Pages;

use App\Filament\Resources\ChargeResource;
use App\Models\Order;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListCharges extends ListRecords
{
    protected static string $resource = ChargeResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('در انتظار بررسی')
                ->icon('heroicon-o-clock')
                ->badge(fn (): ?int => $this->countByStatus('pending') ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'pending')),

            'paid' => Tab::make('تایید شده')
                ->icon('heroicon-o-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'paid')),

            'failed' => Tab::make('رد شده')
                ->icon('heroicon-o-x-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'failed')),

            'all' => Tab::make('همه'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'pending';
    }

    private function countByStatus(string $status): int
    {
        return Order::query()
            ->chargeRequests()
            ->where('payment_method', 'card')
            ->where('status', $status)
            ->count();
    }
}
