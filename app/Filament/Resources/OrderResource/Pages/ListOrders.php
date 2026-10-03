<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

        ];
    }

    public function getTabs(): array
    {
        return [
            'paid' => Tab::make('سفارشات موفق')
                ->icon('heroicon-o-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'paid')),

            // پرداخت‌های کارت به کارت پکیج که هنوز توسط مدیر تایید نشده‌اند
            'pending' => Tab::make('در انتظار تایید')
                ->icon('heroicon-o-clock')
                ->badge(fn (): ?int => Order::query()
                    ->packageOrders()
                    ->where('status', 'pending')
                    ->where('payment_method', 'card')
                    ->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', 'pending')
                    ->where('payment_method', 'card')),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'paid';
    }
}
