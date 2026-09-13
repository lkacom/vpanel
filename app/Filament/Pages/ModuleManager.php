<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Forms\Components\FileUpload;
use ZipArchive;

class ModuleManager extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';
    protected string $view = 'filament.pages.module-manager';
    protected static ?string $navigationLabel = 'نصب افزونه';
    protected static ?string $title = 'مدیریت افزونه‌ها';
    protected static string|\UnitEnum|null $navigationGroup = 'مدیریت افزونه‌ها';
    protected static ?int $navigationSort = 4;

    public static function getNavigationBadge(): ?string
    {
        $activeModulesCount = collect(Module::all())
            ->where('isEnabled', true)
            ->count();

        return $activeModulesCount > 0 ? (string) $activeModulesCount : null;
    }



    public array $modules = [];
    public ?array $uploadData = [];

    public function mount(): void
    {
        $this->loadModules();
        $this->form->fill();
    }


    protected function loadModules(): void
    {

        $allModules = Module::all();
        $this->modules = collect($allModules)->map(function ($module) {
            return [
                'name' => $module->getName(),
                'description' => $module->get('description', 'بدون توضیحات'),
                'version' => $module->get('version', '1.0.0'),
                'isEnabled' => $module->isEnabled(),
            ];
        })->toArray();

    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                FileUpload::make('plugin_zip')
                    ->label('فایل Zip افزونه')
                    ->acceptedFileTypes([
                        'application/zip',
                        'application/x-zip',
                        'application/x-zip-compressed',
                        'application/octet-stream',
                        'multipart/x-zip',
                    ])
                    ->required()
                    ->storeFiles(false),
            ])
            ->statePath('uploadData');
    }

    public function installModule()
    {
        $data = $this->form->getState();
        $file = $data['plugin_zip'];
        $zipPath = $file->getRealPath();

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== TRUE) {
            Notification::make()->title('خطا در باز کردن فایل Zip.')->danger()->send();
            return;
        }

        // پیدا کردن نام پوشه ماژول از داخل ZIP
        $moduleName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            $parts = explode('/', trim($entry, '/'));
            if (count($parts) >= 1 && ! empty($parts[0])) {
                $moduleName = $parts[0];
                break;
            }
        }

        if (! $moduleName) {
            Notification::make()->title('ساختار ZIP نامعتبر است.')->danger()->send();
            $zip->close();
            return;
        }

        // بررسی وجود module.json در ZIP
        $hasModuleJson = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_ends_with($zip->getNameIndex($i), 'module.json')) {
                $hasModuleJson = true;
                break;
            }
        }

        if (! $hasModuleJson) {
            Notification::make()->title('فایل module.json یافت نشد — فایل ZIP معتبر نیست.')->danger()->send();
            $zip->close();
            return;
        }

        $zip->extractTo(base_path('Modules/'));
        $zip->close();

        // ثبت در modules_statuses.json به عنوان غیرفعال
        $statusFile = base_path('modules_statuses.json');
        $statuses = json_decode(file_get_contents($statusFile), true) ?? [];
        if (! isset($statuses[$moduleName])) {
            $statuses[$moduleName] = false;
            file_put_contents($statusFile, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // scan و migration
        Artisan::call('module:scan');
        Artisan::call('migrate', ['--force' => true]);

        $this->loadModules();
        $this->form->fill();

        Notification::make()
            ->title("افزونه {$moduleName} با موفقیت نصب شد.")
            ->body('افزونه در لیست زیر اضافه شد. برای فعال‌سازی دکمه «فعال» را بزنید.')
            ->success()
            ->send();
    }

    public function enableModule(string $moduleName)
    {
        Artisan::call('module:enable', ['module' => $moduleName]);
        $this->loadModules();
        Notification::make()->title("افزونه {$moduleName} با موفقیت فعال شد.")->success()->send();
    }

    public function disableModule(string $moduleName)
    {
        Artisan::call('module:disable', ['module' => $moduleName]);
        $this->loadModules();
        Notification::make()->title("افزونه {$moduleName} غیرفعال شد.")->warning()->send();
    }

    public function deleteModule(string $moduleName)
    {
        Artisan::call('module:delete', ['module' => $moduleName]);
        $this->loadModules();
        Notification::make()->title("افزونه {$moduleName} به طور کامل حذف شد.")->danger()->send();
    }
}
