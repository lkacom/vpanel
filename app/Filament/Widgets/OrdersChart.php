<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget;

class OrdersChart extends ChartWidget
{
    protected static ?string $heading = 'سفارشات فعال شده 30 روز اخیر';
    protected int|string|array $columnSpan = 12;
    protected static ?int $sort = 2;
    protected function getData(): array
    {
        $data = [];
        $labels = [];

        for ($i = 30; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $labels[] = $date;

            $count = Order::whereDate('updated_at', $date)->where('status', 'paid')->count();

            $data[] = $count;
        }

        return [
            'datasets' => [
                [
                    'label' => 'تعداد سفارش',
                    'data' => $data,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
