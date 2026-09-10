<?php


namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class VpanelInfoWidget extends Widget
{
    protected static ?int $sort = -2;
    protected int|string|array $columnSpan = 12;

    protected static bool $isLazy = false;


    protected string $view = 'filament.widgets.info-widget';
}
