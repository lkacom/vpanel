<?php

namespace App\Filament\Pages;

use App\Models\Inbound;
use App\Models\Setting;
use App\Support\TrialSettings;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

class ManageTrialSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\UnitEnum|null $navigationGroup = 'تنظیمات';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';
    protected static ?string $navigationLabel = 'فعالسازی اکانت تست';
    protected string $view = 'filament.pages.manage-trial-settings';
    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'مدیریت تنظیمات اکانت تست';

    public ?array $data = [];

    /** کلیدهای قدیمی که با کلیدهای جدید جایگزین شده‌اند */
    private const LEGACY_KEYS = ['trial_volume_mb', 'trial_duration_hours'];

    private function panelType(): string
    {
        return (string) Setting::where('key', 'panel_type')->value('value');
    }

    /** فقط در پنل علیرضا (txui) مدیر باید یک Inbound انتخاب کند. */
    private function needsInboundSelection(): bool
    {
        return in_array($this->panelType(), ['txui', 'xui'], true);
    }

    /**
     * لیست Inbound های فعال از دیتابیس
     *
     * @return array<string, string>
     */
    private function inboundOptions(): array
    {
        return Inbound::query()
            ->whereNotNull('inbound_data')
            ->get()
            ->filter(fn (Inbound $inbound): bool => $inbound->is_active && $inbound->panel_id !== null)
            ->mapWithKeys(fn (Inbound $inbound): array => [$inbound->panel_id => $inbound->dropdown_label])
            ->all();
    }

    public function mount(): void
    {
        $trial = TrialSettings::load();

        $state = [
            'trial_enabled'        => $trial->enabled,
            'trial_volume_gb'      => $trial->volumeGb,
            'trial_duration_days'  => $trial->durationDays,
            'trial_limit_per_user' => $trial->limitPerUser,
        ];

        if ($this->needsInboundSelection()) {
            $state['trial_inbound_ids'] = $trial->inboundIds[0] ?? null;
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $panelType = $this->panelType();

        $fields = [
            Toggle::make('trial_enabled')
                ->label('فعال‌سازی اکانت تست')
                ->helperText('اگر فعال باشد، کاربران می‌توانند از داشبورد خود اکانت تست رایگان دریافت کنند.')
                ->live(),

            TextInput::make('trial_volume_gb')
                ->label('حجم اکانت تست (GB)')
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->required()
                ->helperText('اعشار مجاز است؛ مثلاً 0.5 یعنی ۵۰۰ مگابایت.'),

            TextInput::make('trial_duration_days')
                ->label('مدت اعتبار اکانت تست (روز)')
                ->numeric()
                ->minValue(0.01)
                ->step(0.01)
                ->required()
                ->helperText('اعشار مجاز است؛ مثلاً 0.5 یعنی ۱۲ ساعت.'),

            TextInput::make('trial_limit_per_user')
                ->label('دفعات مجاز')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->default(1)
                ->required(),
        ];

        // پنل علیرضا فقط یک Inbound می‌پذیرد؛ انتخاب تکی (نه چندانتخابی)
        if ($this->needsInboundSelection()) {
            $fields[] = Select::make('trial_inbound_ids')
                ->label('Inbound اکانت تست')
                ->options($this->inboundOptions())
                ->searchable()
                ->preload()
                ->native(false)
                ->required(fn (Get $get): bool => (bool) $get('trial_enabled'));
        }

        $description = match ($panelType) {
            'sanaei'  => 'در پنل ثنایی، اکانت تست به همه Inbound های فعال متصل می‌شود.',
            'marzban' => 'پنل مرزبان نیازی به انتخاب Inbound ندارد.',
            default   => 'در این بخش اکانت تست را فعال کرده و مشخصات آن را تعیین کنید.',
        };

        return $schema
            ->schema([
                Section::make('تنظیمات اکانت تست رایگان')
                    ->description($description)
                    ->schema($fields),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        // Inbound فقط برای پنل علیرضا ذخیره می‌شود و همیشه به صورت آرایه‌ی یک‌عنصری
        if (array_key_exists('trial_inbound_ids', $data)) {
            $ids = array_values(array_filter(
                array_map('strval', Arr::wrap($data['trial_inbound_ids'])),
                fn (string $id): bool => $id !== ''
            ));

            $data['trial_inbound_ids'] = array_slice($ids, 0, 1);
        }

        foreach ($data as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        // کلیدهای قدیمی دیگر استفاده نمی‌شوند
        Setting::whereIn('key', self::LEGACY_KEYS)->delete();

        Notification::make()->title('تنظیمات با موفقیت ذخیره شد.')->success()->send();
    }
}
