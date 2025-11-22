<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Nwidart\Modules\Facades\Module;
use Illuminate\Support\Facades\Artisan;
use Filament\Notifications\Notification;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\FileUpload;
use ZipArchive;

class ModuleManager extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-puzzle-piece';
    protected static string $view = 'filament.pages.module-manager';
    protected static ?string $navigationLabel = 'نصب افزونه';
    protected static ?string $title = 'مدیریت افزونه‌ها';
    protected static ?string $navigationGroup = 'تنظیمات';
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

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                FileUpload::make('plugin_zip')
                    ->label('فایل Zip افزونه')
                    ->acceptedFileTypes(['application/zip'])
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
        if ($zip->open($zipPath) === TRUE) {
            $zip->extractTo(base_path('Modules/'));
            $zip->close();

            Artisan::call('module:scan');
            $this->loadModules();
            $this->form->fill();

            Notification::make()->title("افزونه با موفقیت نصب شد.")->body("لطفاً افزونه جدید را از لیست زیر فعال کنید.")->success()->send();
        } else {
            Notification::make()->title("خطا در باز کردن فایل Zip.")->danger()->send();
        }
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
