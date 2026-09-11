<?php

namespace App\Filament\Pages;

use App\Models\Inbound;
use App\Models\Setting;
use App\Services\MarzbanService;
use App\Services\XUIServiceFactory;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VpnSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';
    protected string $view = 'filament.pages.vpn-settings';
    protected static ?string $navigationLabel = 'افزودن سرور v2ray';
    protected static ?string $title = 'افزودن سرور جدید';
    protected static string|\UnitEnum|null $navigationGroup = 'تنظیمات';

    public ?array $connectionData = [];
    public ?array $inboundData = [];
    /** @deprecated Kept for compatibility with older Livewire callers. */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();

        foreach ($settings as $key => $value) {
            if ($value === '') {
                $settings[$key] = null;
            }
        }

        if (($settings['panel_type'] ?? null) === 'xui') {
            $settings['panel_type'] = 'sanaei';
        }

        $connectionDefaults = [
            'panel_type' => 'marzban',
            'xui_host' => null,
            'xui_user' => null,
            'xui_pass' => null,
            'xui_link_type' => 'single',
            'xui_subscription_url_base' => null,
            'marzban_host' => null,
            'marzban_sudo_username' => null,
            'marzban_sudo_password' => null,
            'marzban_node_hostname' => null,
        ];

        $this->connectionForm->fill(array_merge($connectionDefaults, $settings));
        $this->inboundForm->fill([
            'xui_default_inbound_id' => $settings['xui_default_inbound_id'] ?? null,
        ]);
    }

    public function connectionForm(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('تنظیمات اتصال پنل')
                    ->schema([
                        Radio::make('panel_type')
                            ->label('نوع پنل')
                            ->options([
                                'marzban' => 'مرزبان',
                                'sanaei' => 'سنایی (3X-UI)',
                                'txui' => 'پنل علیرضا (x-ui/tx-ui)',
                            ])
                            ->live()
                            ->required(),

                        Section::make('تنظیمات پنل مرزبان')
                            ->visible(fn (Get $get): bool => $get('panel_type') === 'marzban')
                            ->schema([
                                TextInput::make('marzban_host')->label('آدرس پنل مرزبان')->required(),
                                TextInput::make('marzban_sudo_username')->label('نام کاربری ادمین')->required(),
                                TextInput::make('marzban_sudo_password')->label('رمز عبور ادمین')->password()->required(),
                                TextInput::make('marzban_node_hostname')->label('آدرس دامنه/سرور برای کانفیگ'),
                            ]),

                        Section::make('تنظیمات پنل سنایی')
                            ->visible(fn (Get $get): bool => $get('panel_type') === 'sanaei')
                            ->schema($this->xuiConnectionSchema(['sanaei'])),

                        Section::make('تنظیمات پنل علیرضا')
                            ->visible(fn (Get $get): bool => $get('panel_type') === 'txui')
                            ->schema($this->xuiConnectionSchema(['txui'])),
                    ]),
            ])
            ->statePath('connectionData');
    }

    public function inboundForm(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('کانفیگ پیش‌فرض')
                    ->schema([
                        Select::make('xui_default_inbound_id')
                            ->label('Inbound پیش‌فرض')
                            ->options(fn (): array => $this->inboundOptions())
                            ->native(false)
                            ->preload()
                            ->allowHtml()
                            ->placeholder('یک اینباند انتخاب کنید')
                            ->helperText('کانفیگ کاربران توسط inbound پیش فرض ساخته خواهد شد.'),
                    ]),
            ])
            ->statePath('inboundData');
    }

    /** @return array<string, string> */
    private function xuiConnectionSchema(array $panelTypes): array
    {
        $required = fn (Get $get): bool => in_array($get('panel_type'), $panelTypes, true);

        return [
            TextInput::make('xui_host')->label('آدرس کامل پنل')->required($required),
            TextInput::make('xui_user')->label('نام کاربری')->required($required),
            TextInput::make('xui_pass')->label('رمز عبور')->password()->required($required),
            Radio::make('xui_link_type')
                ->label('نوع لینک تحویلی')
                ->options(['single' => 'لینک تکی', 'subscription' => 'لینک سابسکریپشن'])
                ->default('single')
                ->required($required),
            TextInput::make('xui_subscription_url_base')->label('آدرس پایه لینک سابسکریپشن'),
        ];
    }

    /** @return array<string, string> */
    private function inboundOptions(): array
    {
        $options = [];

        foreach (Inbound::query()->whereNotNull('inbound_data')->get() as $inbound) {
            $data = $inbound->inbound_data;
            if (! is_array($data) || ! isset($data['id']) || ($data['enable'] ?? false) !== true) {
                continue;
            }

            $label = $inbound->dropdown_label;
            $options[(string) $data['id']] = is_string($label)
                ? $label
                : strip_tags((string) json_encode($label));
        }

        ksort($options);

        return $options;
    }

    public function saveConnection(): void
    {
        try {
            $this->connectionForm->validate();
            $formData = $this->connectionForm->getState();
            $panelType = $formData['panel_type'] ?? null;

            if ($panelType === 'xui') {
                $panelType = 'sanaei';
                $formData['panel_type'] = $panelType;
            }

            if (in_array($panelType, ['sanaei', 'txui'], true)) {
                $xui = XUIServiceFactory::make(
                    $panelType,
                    (string) ($formData['xui_host'] ?? ''),
                    (string) ($formData['xui_user'] ?? ''),
                    (string) ($formData['xui_pass'] ?? '')
                );

                if (! $xui->login()) {
                    $this->notifyError('خطا در اتصال', 'نام کاربری یا رمز عبور اشتباه است یا سرور در دسترس نیست.');
                    return;
                }

                $inbounds = collect($xui->getInbounds())
                    ->filter(fn ($inbound): bool => is_array($inbound) && isset($inbound['id']))
                    ->keyBy(fn (array $inbound): string => (string) $inbound['id'])
                    ->values()
                    ->all();

                if (empty($inbounds)) {
                    $this->notifyError('خطا در دریافت اینباندها', 'سرور در دسترس نیست یا اینباندی موجود نیست.');
                    return;
                }

                $inboundIds = array_map(
                    static fn (array $inbound): string => (string) $inbound['id'],
                    $inbounds,
                );
                $currentDefaultInbound = Setting::where('key', 'xui_default_inbound_id')->value('value');
                if ($currentDefaultInbound !== null
                    && $currentDefaultInbound !== ''
                    && ! in_array((string) $currentDefaultInbound, $inboundIds, true)) {
                    Setting::updateOrCreate(
                        ['key' => 'xui_default_inbound_id'],
                        ['value' => ''],
                    );
                    $this->inboundForm->fill(['xui_default_inbound_id' => null]);
                }

                DB::transaction(function () use ($formData, $inbounds): void {
                    Inbound::query()->delete();
                    foreach ($inbounds as $inbound) {
                        Inbound::create([
                            'title' => $inbound['remark'] ?? "Inbound {$inbound['id']}",
                            'inbound_data' => $inbound,
                        ]);
                    }
                    $this->saveSettings($formData);
                });

                Cache::forget('inbounds_dropdown');
                $this->notifySuccess('همگام‌سازی موفق', count($inbounds) . ' اینباند با موفقیت Sync شد.');
                return;
            }

            if ($panelType === 'marzban') {
                $marzban = new MarzbanService(
                    $formData['marzban_host'] ?? '',
                    $formData['marzban_sudo_username'] ?? '',
                    $formData['marzban_sudo_password'] ?? '',
                    $formData['marzban_node_hostname'] ?? ''
                );

                if (! $marzban->login()) {
                    $this->notifyError('خطا در اتصال به مرزبان', 'نام کاربری یا رمز عبور مرزبان صحیح نیست یا آدرس پنل در دسترس نمی‌باشد.');
                    return;
                }

                $this->saveSettings($formData);
                $this->notifySuccess('تنظیمات اتصال ذخیره شد');
            }
        } catch (\Throwable $e) {
            Log::error('Panel connection configuration failed: ' . $e->getMessage());
            $this->notifyError('خطا در تنظیمات', $e->getMessage());
        }
    }

    public function saveInbound(): void
    {
        try {
            $this->inboundForm->validate();
            $inboundId = $this->inboundForm->getState()['xui_default_inbound_id'] ?? null;

            Setting::updateOrCreate([
                'key' => 'xui_default_inbound_id',
            ], [
                'value' => $inboundId ?? '',
            ]);

            Cache::forget('settings');
            $this->notifySuccess('Inbound پیش‌فرض ذخیره شد');
        } catch (\Throwable $e) {
            Log::error('Default inbound configuration failed: ' . $e->getMessage());
            $this->notifyError('خطا در ذخیره Inbound', $e->getMessage());
        }
    }

    /**
     * Compatibility bridge for older Livewire callers. The page UI uses the
     * independent saveConnection() and saveInbound() actions instead.
     */
    public function submit(bool $initialSave = false): void
    {
        if ($initialSave) {
            $this->connectionForm->fill($this->data ?? []);
            $this->saveConnection();
            return;
        }

        $this->inboundForm->fill($this->data ?? []);
        $this->saveInbound();
    }

    /** @param array<string, mixed> $settings */
    private function saveSettings(array $settings): void
    {
        foreach ($settings as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }
        Cache::forget('settings');
    }

    private function notifySuccess(string $title, ?string $body = null): void
    {
        $notification = Notification::make()->title($title)->success();
        if ($body !== null) {
            $notification->body($body);
        }
        $notification->send();
    }

    private function notifyError(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }
}
