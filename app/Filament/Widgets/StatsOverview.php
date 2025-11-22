<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

class StatsOverview extends BaseWidget
{

    protected static ?string $pollingInterval = '300s';
    protected int|string|array $columnSpan = 12;

    protected function getStats(): array
    {


        $totalRevenue = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->with('plan')
            ->get()
            ->sum(function($order) {

                return $order->plan?->price ?? 0;
            });


        $currentMonthRevenue = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->with('plan')
            ->get()
            ->sum(function($order) {
                return $order->plan?->price ?? 0;
            });

        $totalPaidOrders = Order::where('status', 'paid')->count();


        $totalUsers = User::count();


        $latestOrder = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->with(['user', 'plan'])
            ->latest()
            ->first();

        $latestOrderDescription = 'مجموع سفارشات موفق';

        // --- نمایش در کارت‌ها ---

        return [
            Stat::make('درآمد کل', number_format($totalRevenue) . ' تومان')
                ->description('مجموع فروش ')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),

            Stat::make('درآمد ماه جاری', number_format($currentMonthRevenue) . ' تومان')
                ->description('فروش ماه جاری')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('danger'),

            Stat::make('تعداد کل کاربران', $totalUsers)
                ->description('تعداد کل کاربران')
                ->descriptionIcon('heroicon-m-users')
                ->color('warning'),

            Stat::make('تعداد سفارشات', $totalPaidOrders)
                ->description($latestOrderDescription)
                ->descriptionIcon('heroicon-m-shopping-cart')
                ->color('info'),
        ];
    }
}
