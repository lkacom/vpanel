<?php


namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class VpanelInfoWidget extends Widget
{
    protected static ?int $sort = -2;
    protected int|string|array $columnSpan = 12;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.info-widget';

    /**
     * خواندن نسخه نصب‌شده از git tag
     * اگر git در دسترس نبود یا تگی وجود نداشت، «نامشخص» برمی‌گردد.
     */
    public function getVersion(): string
    {
        $path = base_path();
        $safePath = str_replace('\\', '/', $path);

        $output = [];
        $exitCode = 0;

        // خروجی تمیز بدون pipe در PHP خالص
        exec("git -c safe.directory={$safePath} -C " . escapeshellarg($path) . " tag --sort=-version:refname", $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return 'نامشخص';
        }

        return trim($output[0]);
    }

    protected function getViewData(): array
    {
        return [
            'version' => $this->getVersion(),
        ];
    }
}
