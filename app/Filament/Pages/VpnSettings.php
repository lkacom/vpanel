<?php

namespace App\Filament\Pages;

use App\Models\Inbound;
use App\Models\Setting;
use App\Services\MarzbanService;
use App\Services\XUIServiceFactory;
use Filament\Forms\Components\Radio;
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

    protected static ?string $navigationLabel = 'پیکربندی سرور v2ray';

    protected static ?string $title = 'اتصال سرور v2ray';

    protected static string|\UnitEnum|null $navigationGroup = 'تنظیمات';

    public ?array $connectionData = [];

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
            'marzban_host' => null,
            'marzban_sudo_username' => null,
            'marzban_sudo_password' => null,
            'marzban_node_hostname' => null,
        ];

        $this->connectionForm->fill(array_merge($connectionDefaults, $settings));
    }

    public function connectionForm(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('تنظیمات پنل v2ray')
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

    /** @return array<string, string> */
    private function xuiConnectionSchema(array $panelTypes): array
    {
        $required = fn (Get $get): bool => in_array($get('panel_type'), $panelTypes, true);

        return [
            TextInput::make('xui_host')->label('آدرس کامل پنل')->required($required),
            TextInput::make('xui_user')->label('نام کاربری')->required($required),
            TextInput::make('xui_pass')->label('رمز عبور')->password()->required($required),
            TextInput::make('xui_subscription_port')->label('پورت sub')->numeric()->default('2096'),
            TextInput::make('xui_subscription_path')->label('مسیر sub')->default('/sub'),
        ];
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
                $this->notifySuccess('همگام‌سازی موفق', count($inbounds).' اینباند با موفقیت Sync شد.');

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
            Log::error('Panel connection configuration failed: '.$e->getMessage());
            $this->notifyError('خطا در تنظیمات', $e->getMessage());
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

        $this->saveConnection();
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
