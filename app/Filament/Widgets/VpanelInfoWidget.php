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
     * خواندن نسخه با اولویت‌بندی:
     * 1. version.json  — ساخته‌شده توسط install.sh (سرور اختصاصی)
     * 2. composer.json — فیلد version (هاست اشتراکی / محیط dev)
     */
    public function getVersion(): string
    {
        // 1) فایل version.json که install.sh می‌سازد
        $versionFile = base_path('version.json');
        if (file_exists($versionFile)) {
            $data = json_decode(file_get_contents($versionFile), true);
            if (!empty($data['version'])) {
                return $data['version'];
            }
        }

        // 2) فیلد version در composer.json
        $composerFile = base_path('composer.json');
        if (file_exists($composerFile)) {
            $data = json_decode(file_get_contents($composerFile), true);
            if (!empty($data['version'])) {
                return $data['version'];
            }
        }

        return 'نامشخص';
    }

    protected function getViewData(): array
    {
        return [
            'version' => $this->getVersion(),
        ];
    }
}
