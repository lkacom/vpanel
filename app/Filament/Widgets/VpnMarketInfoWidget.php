<?php


namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class VpnMarketInfoWidget extends Widget
{
    protected static ?int $sort = -2;
    protected int|string|array $columnSpan = 12;

    protected static bool $isLazy = false;


    protected static string $view = 'filament.widgets.info-widget';
}
