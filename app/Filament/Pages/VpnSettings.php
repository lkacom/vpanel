<?php

namespace App\Filament\Pages;

use App\Models\Inbound;
use App\Models\Setting;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class VpnSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';
    protected static string $view = 'filament.pages.vpn-settings';
    protected static ?string $navigationLabel = 'تنظیمات پنل V2Ray';
    protected static ?string $title = 'تنظیمات اولیه پنل V2Ray';
    protected static ?string $navigationGroup = 'تنظیمات';


    public ?array $data = [];

    public function mount(): void
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();


        foreach ($settings as $key => $value) {
            if ($value === '') {
                $settings[$key] = null;
            }
            if ($key === 'xui_default_inbound_id' && $value !== null) {
                $settings[$key] = (string) $value;
            }
        }

        $this->form->fill(array_merge([
            'panel_type' => 'marzban',
            'xui_host' => null,
            'xui_user' => null,
            'xui_pass' => null,
            'xui_default_inbound_id' => null,
            'xui_link_type' => 'single',
            'marzban_host' => null,
            'marzban_sudo_username' => null,
            'marzban_sudo_password' => null,
        ], $settings));
    }

    public function form(Form $form): Form
    {
        return $form->schema([

                        Radio::make('panel_type')->label('نوع پنل')->options(['marzban' => 'مرزبان', 'xui' => 'سنایی / TX-UI'])->live()->required(),
                        Section::make('تنظیمات پنل مرزبان')->visible(fn (Get $get) => $get('panel_type') === 'marzban')->schema([
                            TextInput::make('marzban_host')->label('آدرس پنل مرزبان')->required(),
                            TextInput::make('marzban_sudo_username')->label('نام کاربری ادمین')->required(),
                            TextInput::make('marzban_sudo_password')->label('رمز عبور ادمین')->password()->required(),
                            TextInput::make('marzban_node_hostname')->label('آدرس دامنه/سرور برای کانفیگ')
                        ]),
                        Section::make('تنظیمات پنل  X-UI')
                            ->visible(fn(Get $get) => $get('panel_type') === 'xui')
                            ->schema([
                                TextInput::make('xui_host')->label('آدرس کامل پنل ')
                                    ->required(fn(Get $get): bool => $get('panel_type') === 'xui'),
                                TextInput::make('xui_user')->label('نام کاربری')
                                    ->required(fn(Get $get): bool => $get('panel_type') === 'xui'),
                                TextInput::make('xui_pass')->label('رمز عبور')->password()
                                    ->required(fn(Get $get): bool => $get('panel_type') === 'xui'),


                                // 🔥 فیکس کامل:
                                Select::make('xui_default_inbound_id')
                                    ->label('ورودی پیش‌فرض')
                                    ->options(function () {
                                        // 🔥 دیباگ: لاگ بزن ببین چی داریم
                                        $inbounds = \App\Models\Inbound::query()
                                            ->whereNotNull('inbound_data')
                                            ->get();

                                        \Illuminate\Support\Facades\Log::info('Inbounds for select:', [
                                            'count' => $inbounds->count(),
                                            'sample' => $inbounds->first()?->inbound_data
                                        ]);

                                        $options = [];
                                        foreach ($inbounds as $inbound) {
                                            $data = $inbound->inbound_data;

                                            // فقط اینباندهای فعال و معتبر
                                            if (!is_array($data) ||
                                                !isset($data['id']) ||
                                                !isset($data['enable']) ||
                                                $data['enable'] !== true) {
                                                continue;
                                            }

                                            $panelId = (string) $data['id'];
                                            $label = $inbound->dropdown_label;

                                            // اطمینان حاصل کن که label یه رشته ساده است
                                            if (!is_string($label)) {
                                                $label = strip_tags(json_encode($label));
                                            }

                                            $options[$panelId] = $label;
                                        }

                                        ksort($options);
                                        return $options;
                                    })
                                    ->getOptionLabelUsing(function ($value) {
                                        if (blank($value)) {
                                            return 'انتخاب نشده';
                                        }

                                        $inbound = \App\Models\Inbound::all()->firstWhere(function ($i) use ($value) {
                                            return isset($i->inbound_data['id']) &&
                                                (string) $i->inbound_data['id'] === (string) $value;
                                        });

                                        return $inbound?->dropdown_label ?? "⚠️ اینباند نامعتبر (ID: $value)";
                                    })
                                    ->dehydrateStateUsing(fn ($state) => $state ? (string) $state : null)
                                    ->native(false) // مهم: از سلکت سفارشی فیلمنت استفاده کن
                                    ->searchable()
                                    ->preload()
                                    ->allowHtml()
                                    ->placeholder('یک اینباند انتخاب کنید')
                                    ->helperText('اگر لیست خالی است، ابتدا از بخش "ورودی ها" دکمه همگام سازی را بزنید و صفحه را رفرش کنید.'),

                                Radio::make('xui_link_type')->label('نوع لینک تحویلی')->options(['single' => 'لینک تکی', 'subscription' => 'لینک سابسکریپشن'])->default('single')
                                    ->required(fn(Get $get): bool => $get('panel_type') === 'xui'),
                                TextInput::make('xui_subscription_url_base')->label('آدرس پایه لینک سابسکریپشن'),
                            ]),






        ])->statePath('data');
    }

    public function submit(): void
    {
        $this->form->validate();
        $formData = $this->form->getState();

        foreach ($formData as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        Cache::forget('settings');
        Notification::make()->title('تنظیمات با موفقیت ذخیره شد.')->success()->send();
    }
}
