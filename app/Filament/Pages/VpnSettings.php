<?php

namespace App\Filament\Pages;

use App\Models\Inbound;
use App\Models\Setting;
use App\Services\XUIService;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Wizard;
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
    protected static ?string $navigationLabel = 'راه اندازی اولیه پنل ';
    protected static ?string $title = 'تنظیمات اولیه پنل V2Ray';
    protected static ?string $navigationGroup = 'تنظیمات';

    public ?array $data = [];
    private bool $connectionFailed = false;

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

            Wizard::make([

                /* مرحله ۱ — انتخاب پنل */
                Wizard\Step::make('انتخاب نوع پنل')
                    ->schema([
                        Radio::make('panel_type')
                            ->label('نوع پنل')
                            ->options([
                                'marzban' => 'مرزبان',
                                'xui' => 'سنایی / TX-UI',
                            ])
                            ->live()
                            ->required(),
                    ]),

                /* مرحله ۲ — تنظیمات اتصال */
                Wizard\Step::make('اتصال پنل V2Ray به  VPanel')
                    ->schema([
                        Section::make('تنظیمات پنل مرزبان')
                            ->visible(fn (Get $get) => $get('panel_type') === 'marzban')
                            ->schema([
                                TextInput::make('marzban_host')->label('آدرس پنل مرزبان')->required(),
                                TextInput::make('marzban_sudo_username')->label('نام کاربری ادمین')->required(),
                                TextInput::make('marzban_sudo_password')->label('رمز عبور ادمین')->password()->required(),
                                TextInput::make('marzban_node_hostname')->label('آدرس دامنه/سرور برای کانفیگ'),
                            ]),

                        Section::make('تنظیمات پنل X-UI')
                            ->visible(fn (Get $get) => $get('panel_type') === 'xui')
                            ->schema([
                                TextInput::make('xui_host')
                                    ->label('آدرس کامل پنل')
                                    ->required(fn (Get $get): bool => $get('panel_type') === 'xui'),
                                TextInput::make('xui_user')
                                    ->label('نام کاربری')
                                    ->required(fn (Get $get): bool => $get('panel_type') === 'xui'),
                                TextInput::make('xui_pass')
                                    ->label('رمز عبور')
                                    ->password()
                                    ->required(fn (Get $get): bool => $get('panel_type') === 'xui'),

                                Radio::make('xui_link_type')
                                    ->label('نوع لینک تحویلی')
                                    ->options(['single' => 'لینک تکی', 'subscription' => 'لینک سابسکریپشن'])
                                    ->default('single')
                                    ->required(fn (Get $get): bool => $get('panel_type') === 'xui'),

                                TextInput::make('xui_subscription_url_base')
                                    ->label('آدرس پایه لینک سابسکریپشن'),
                            ]),
                    ])
                    ->afterValidation(function () {
                        $this->submit(initialSave: true);
                    }),

                /* مرحله ۳ — انتخاب ورودی پیش‌فرض */
                Wizard\Step::make('انتخاب "ورودی" پیش‌فرض ')
                    ->icon('heroicon-o-chevron-up-down')
                    ->schema([
                        Select::make('xui_default_inbound_id')
                            ->label('ورودی پیش‌فرض')
                            ->options(function () {
                                $inbounds = Inbound::query()
                                    ->whereNotNull('inbound_data')
                                    ->get();

                                $options = [];
                                foreach ($inbounds as $inbound) {
                                    $data = $inbound->inbound_data;
                                    if (!is_array($data) || !isset($data['id']) || !isset($data['enable']) || $data['enable'] !== true) {
                                        continue;
                                    }
                                    $panelId = (string) $data['id'];
                                    $label = $inbound->dropdown_label;
                                    if (!is_string($label)) {
                                        $label = strip_tags(json_encode($label));
                                    }
                                    $options[$panelId] = $label;
                                }

                                ksort($options);
                                return $options;
                            })
                            ->native(false)
                            ->preload()
                            ->allowHtml()
                            ->placeholder('یک اینباند انتخاب کنید')
                            ->helperText('در صورتی که این لیست خالی باشد اطلاعات ورود به پنل در مرحله قبل صحیح نمیباشد یا در سرور خود ورودی تعریف نکرده اید.')
                    ]),

            ])->statePath('data')
                ->submitAction(
                    \Filament\Actions\Action::make('save')
                        ->label('ذخیره تغییرات')
                        ->color('success')
                        ->button()
                        ->action('submitForm')
                ),

        ]);
    }

    /**
     * متد برای رفع مشکل Submit
     */
    public function submitForm(): void
    {
        $this->submit(initialSave: false);
    }

    /**
     * SUBMIT — دو حالت دارد:
     * 1. initialSave = true → مرحله ۲ ذخیره + Sync
     * 2. مرحله پایانی → ذخیره نهایی ورودی پیش‌فرض
     */
    public function submit(bool $initialSave = false): void
    {
        try {
            $this->form->validate();

            $formData = $this->form->getState()['data'] ?? [];

            /* اگر مرحله دوم است → Sync انجام می‌شود */
            if ($initialSave) {
                $panelType = $formData['panel_type'] ?? null;

                if ($panelType === 'xui') {
                    $xui = new XUIService(
                        $formData['xui_host'] ?? null,
                        $formData['xui_user'] ?? null,
                        $formData['xui_pass'] ?? null
                    );

                    if (!$xui->login()) {
                        Notification::make()
                            ->title('خطا در اتصال')
                            ->body('نام کاربری یا رمز عبور اشتباه است یا سرور در دسترس نیست.')
                            ->danger()
                            ->send();
                        return;
                    }

                    $inbounds = $xui->getInbounds();
                    if (is_null($inbounds) || empty($inbounds)) {
                        Notification::make()
                            ->title('خطا در دریافت اینباندها')
                            ->body('سرور در دسترس نیست یا اینباندی موجود نیست.')
                            ->danger()
                            ->send();
                        return;
                    }
                    Inbound::truncate();
                    foreach ($formData as $key => $value) {
                        Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
                    }

                    Cache::forget('settings');

                    $synced = 0;
                    foreach ($inbounds as $inbound) {
                        $existing = Inbound::where('inbound_data->id', $inbound['id'])->first();

                        if ($existing) {
                            $existing->update([
                                'title' => $existing->title ?: ($inbound['remark'] ?? "Inbound {$inbound['id']}"),
                                'inbound_data' => $inbound
                            ]);
                        } else {
                            Inbound::create([
                                'title' => $inbound['remark'] ?? "Inbound {$inbound['id']}",
                                'inbound_id' => $inbound['id'],
                                'inbound_data' => $inbound
                            ]);
                        }
                        $synced++;
                    }

                    Cache::forget('inbounds_dropdown');

                    Notification::make()
                        ->title('همگام‌سازی موفق')
                        ->body("{$synced} اینباند با موفقیت Sync شد.")
                        ->success()
                        ->send();

                } elseif ($panelType === 'marzban') {
                    foreach ($formData as $key => $value) {
                        Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
                    }

                    Cache::forget('settings');

                    Notification::make()
                        ->title('تنظیمات ذخیره شد')
                        ->success()
                        ->send();
                }

                return;
            }

            /* ذخیره نهایی مرحله آخر */
            foreach ($formData as $key => $value) {
                Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
            }

            Cache::forget('settings');

            Notification::make()
                ->title('تنظیمات با موفقیت ذخیره شد')
                ->success()
                ->send();

        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()
                ->title('خطا در اعتبارسنجی')
                ->body('لطفا تمام فیلدهای الزامی را پر کنید.')
                ->danger()
                ->send();

            Log::error('Validation failed: ' . json_encode($e->errors()));
        } catch (\Exception $e) {
            Log::error('Panel configuration failed: ' . $e->getMessage());
            Notification::make()
                ->title('خطا در تنظیمات')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
