<?php

namespace App\Filament\Widgets;

use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Ticketing\Models\Ticket;

class TicketStatsOverview extends BaseWidget
{

    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 12;

    protected function getStats(): array
    {

        $openTicketsCount = Ticket::whereIn('status', ['open', 'answered'])->count();
        $openTicketsDescription = $openTicketsCount > 0 ? "{$openTicketsCount} تیکت منتظر پاسخ شماست" : "هیچ تیکت بازی وجود ندارد";


        $todayTicketsCount = Ticket::whereDate('created_at', Carbon::today())->count();
        $todayTicketsDescription = $todayTicketsCount > 0 ? "امروز {$todayTicketsCount} تیکت جدید دریافت شد" : "امروز تیکت جدیدی ثبت نشده";


        $totalTicketsCount = Ticket::count();

        return [
            Stat::make('تیکت‌های باز', $openTicketsCount)
                ->description($openTicketsDescription)
                ->descriptionIcon('heroicon-m-inbox-arrow-down')

                ->color($openTicketsCount > 0 ? 'warning' : 'success'),

            Stat::make('تیکت‌های جدید امروز', $todayTicketsCount)
                ->description($todayTicketsDescription)
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('info'),

            Stat::make('مجموع کل تیکت‌ها', $totalTicketsCount)
                ->description('تعداد کل تیکت‌های ثبت شده در سیستم')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('gray'),
        ];
    }
}
