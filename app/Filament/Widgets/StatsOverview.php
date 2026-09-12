<?php

namespace App\Filament\Widgets;

use Filament\Support\Colors\Color;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Order;

class StatsOverview extends BaseWidget
{
    protected ?string $pollingInterval = '300s';
    protected int|string|array $columnSpan = 12;

    protected function getStats(): array
    {
        $totalRevenue = Order::where('status', 'paid')->sum('amount');

        $currentMonthRevenue = Order::where('status', 'paid')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        // سفارشات موفق (کانفیگ تحویل‌داده‌شده)
        $totalPaidOrders = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->count();

        // فقط درخواست‌های شارژ کیف پول که هنوز تایید نشده‌اند
        $pendingWalletCharges = Order::where('status', 'pending')
            ->whereNull('plan_id')
            ->whereNotNull('payment_method')
            ->count();

        return [
            Stat::make('درآمد کل', number_format($totalRevenue) . ' تومان')
                ->description('مجموع فروش')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('درآمد ماه جاری', number_format($currentMonthRevenue) . ' تومان')
                ->description('فروش ۳۰ روز اخیر')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color(Color::Lime),

            Stat::make('درخواست افزایش موجودی', $pendingWalletCharges)
                ->description('شارژ کیف پول در انتظار تایید')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('warning'),

            Stat::make('سفارشات موفق', $totalPaidOrders)
                ->description('سفارشات تحویل‌داده‌شده')
                ->descriptionIcon('heroicon-m-shield-check')
                ->color(Color::Purple),
        ];
    }
}
