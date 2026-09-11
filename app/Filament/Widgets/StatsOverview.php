<?php

namespace App\Filament\Widgets;

use BaconQrCode\Renderer\Color\Rgb;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\IconPosition;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Order;
use App\Models\User;
use Carbon\Carbon;

class StatsOverview extends BaseWidget
{

    protected ?string $pollingInterval = '300s';
    protected int|string|array $columnSpan = 12;

    protected function getStats(): array
    {


        $totalRevenue = Order::where('status', 'paid')
            ->sum('amount');

        $currentMonthRevenue = Order::where('status', 'paid')
            ->where('created_at', '>=', now()->subDays(30))
            ->sum('amount');

        $totalPaidOrders = Order::where('status', 'paid')->whereNull('payment_method')->count();

        $totalUsers = Order::where('status', 'pending')->count();


        $latestOrder = Order::where('status', 'paid')
            ->whereNotNull('plan_id')
            ->with(['user', 'plan'])
            ->latest()
            ->first();


        // --- نمایش در کارت‌ها ---

        return [
            Stat::make('درآمد کل', number_format($totalRevenue) . ' تومان')
                ->description('مجموع فروش ')
                ->descriptionIcon('heroicon-m-arrow-trending-up',IconPosition::Before)
                ->color('success'),

            Stat::make('درآمد ماه جاری', number_format($currentMonthRevenue) . ' تومان')
                ->description('فروش ماه جاری')
                ->descriptionIcon('heroicon-m-calendar-days',IconPosition::Before)
                ->extraAttributes([
                    'class' => 'bg-white text-black', // پس‌زمینه مشکی و متن سفید
                ])
                ->color(Color::Lime),

            Stat::make('درخواست  شارژ', $totalUsers)
                ->description('سفارشات در انتظار تایید')
                ->descriptionIcon('heroicon-m-x-circle',IconPosition::Before)
                ->color('warning'),

            Stat::make('سفارشات موفق', $totalPaidOrders)
                ->description(' کانفیگ های تحویلی')
                ->descriptionIcon('heroicon-m-shield-check',IconPosition::Before)
                ->color(Color::Purple),
        ];
    }
}
