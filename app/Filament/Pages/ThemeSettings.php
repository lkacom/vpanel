<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Forms\Components\FileUpload;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Tabs;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class ThemeSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';
    protected string $view = 'filament.pages.theme-settings';
    protected static ?string $navigationLabel = 'پیکربندی ';
    protected static ?string $title = 'تنظیمات قالب و محتوای سایت';
    protected static string|\UnitEnum|null $navigationGroup = 'تنظیمات';

    public ?array $data = [];

    public function mount(): void
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();

        $settings['main_theme_enabled'] = ($settings['active_theme'] ?? 'rocket') === 'rocket';

        // FileUpload با disk=public و directory=logos کار می‌کند
        // مقدار ذخیره‌شده در DB: "logos/filename.png"
        // لوگوی ورود کلید مستقل دارد؛ برای نصب‌های قبلی از site_logo نیز fallback می‌گیریم.
        $storedLogo = $settings['login_logo'] ?? ($settings['site_logo'] ?? null);
        if (is_array($storedLogo)) {
            $storedLogo = array_values($storedLogo)[0] ?? null;
        }
        $settings['login_logo'] = $storedLogo ? [$storedLogo] : [];

        $this->form->fill(array_merge([
            'main_theme_enabled' => true,
            'login_logo'         => [],
        ], $settings));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->schema([
            Tabs::make('Tabs')
                ->id('main-tabs')
                ->persistTab()
                ->extraAttributes(['class' => 'max-w-max'])
                ->tabs([

                    Tabs\Tab::make('تنظیمات قالب')
                        ->icon('heroicon-o-swatch')
                        ->schema([
                            Section::make('تنظیمات عمومی نمایش')
                                ->schema([
                                    Toggle::make('main_theme_enabled')
                                        ->label('قالب اصلی سایت (RocketVPN)')
                                        ->helperText('غیرفعال = فقط صفحه ورود کاربران نمایش داده می‌شود.')
                                        ->onColor('success')
                                        ->offColor('gray')
                                        ->live(),
                                ]),

                            Section::make('تنظیمات فرم ورود کاربران')
                                ->schema([
                                    TextInput::make('login_brand_name')
                                        ->label('نام نمایشی بالای فرم')
                                        ->placeholder(config('app.name', 'VPanel'))
                                        ->maxLength(60),

                                    FileUpload::make('login_logo')
                                        ->label('لوگوی فرم ورود کاربران')
                                        ->helperText('لوگوی جدید را انتخاب کنید تا جایگزین لوگوی پیش‌فرض شود.')
                                        ->image()
                                        ->imagePreviewHeight('100')
                                        ->maxSize(1024)
                                        ->disk('public')
                                        ->directory('logos')
                                        ->visibility('public')
                                        ->deletable(true)
                                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'])
                                        ->columnSpanFull(),

                                ])->columns(1),
                        ]),

                    Tabs\Tab::make('محتوای قالب ')
                        ->icon('heroicon-o-rocket-launch')
                        ->visible(fn(Get $get) => (bool) $get('main_theme_enabled'))
                        ->schema([
                            Section::make('عمومی')->schema([
                                TextInput::make('rocket_navbar_brand')->label('نام برند در Navbar'),
                                TextInput::make('rocket_footer_text')->label('متن فوتر'),
                            ])->columns(2),
                            Section::make('بخش اصلی (Hero Section)')->schema([
                                TextInput::make('rocket_hero_title')->label('تیتر اصلی'),
                                Textarea::make('rocket_hero_subtitle')->label('زیرتیتر')->rows(2),
                                TextInput::make('rocket_hero_button_text')->label('متن دکمه اصلی'),
                            ]),
                            Section::make('بخش قیمت‌گذاری (Pricing)')->schema([
                                TextInput::make('rocket_pricing_title')->label('عنوان بخش'),
                            ]),
                            Section::make('بخش سوالات متداول (FAQ)')->schema([
                                TextInput::make('rocket_faq_title')->label('عنوان بخش'),
                                TextInput::make('rocket_faq1_q')->label('سوال اول'),
                                Textarea::make('rocket_faq1_a')->label('پاسخ اول')->rows(2),
                                TextInput::make('rocket_faq2_q')->label('سوال دوم'),
                                Textarea::make('rocket_faq2_a')->label('پاسخ دوم')->rows(2),
                            ]),
                            Section::make('لینک‌های اجتماعی')->schema([
                                TextInput::make('telegram_link')->label('لینک تلگرام (کامل)'),
                                TextInput::make('instagram_link')->label('لینک اینستاگرام (کامل)'),
                            ])->columns(2),
                        ]),

                    Tabs\Tab::make('تنظیمات پرداخت')
                        ->icon('heroicon-o-credit-card')
                        ->schema([
                            Section::make('پرداخت کارت به کارت')->schema([
                                TextInput::make('payment_card_number')
                                    ->label('شماره کارت')
                                    ->mask('9999-9999-9999-9999')
                                    ->placeholder('XXXX-XXXX-XXXX-XXXX')
                                    ->helperText('شماره کارت ۱۶ رقمی خود را وارد کنید.')
                                    ->numeric(false)
                                    ->validationAttribute('شماره کارت'),
                                TextInput::make('payment_card_holder_name')->label('نام صاحب حساب'),
                                Textarea::make('payment_card_instructions')->label('توضیحات اضافی')->rows(3),
                            ]),

                            Section::make('درگاه زرین‌پال')
                                ->description('تنظیمات اتصال به درگاه پرداخت زرین‌پال')
                                ->schema([
                                    Toggle::make('zarinpal_active')->label('فعال‌سازی درگاه زرین‌پال')
                                        ->onColor('success')->offColor('gray')->columnSpanFull(),
                                    TextInput::make('zarinpal_merchant_id')->label('کد پذیرنده (Merchant ID)')
                                        ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')->maxLength(36)->columnSpanFull(),
                                    Select::make('zarinpal_currency')->label('واحد پول')
                                        ->options(['IRT' => 'تومان (IRT)', 'IRR' => 'ریال (IRR)'])->default('IRT'),
                                    TextInput::make('zarinpal_gateway_name')->label('نام نمایشی درگاه')
                                        ->placeholder('پرداخت آنلاین — زرین‌پال')->maxLength(100),
                                    Toggle::make('zarinpal_sandbox')->label('حالت آزمایشی (Sandbox)')
                                        ->onColor('warning')->offColor('gray'),
                                ])->columns(2),

                            Section::make('درگاه جیبیت')
                                ->description('تنظیمات اتصال به درگاه پرداخت جیبیت')
                                ->schema([
                                    Toggle::make('jibit_active')->label('فعال‌سازی درگاه جیبیت')
                                        ->onColor('success')->offColor('gray')->columnSpanFull(),
                                    TextInput::make('jibit_api_key')->label('کد API')
                                        ->placeholder('xxxxxxxxxxxxxxxxxxxxxxxxxxxx')->maxLength(64)->columnSpanFull(),
                                    TextInput::make('jibit_secret_key')->label('کد رمز نگهدارنده (Secret Key)')
                                        ->placeholder('xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx')->maxLength(64)->columnSpanFull(),
                                    Select::make('jibit_currency')->label('واحد پول')
                                        ->options(['IRT' => 'تومان (IRT)', 'IRR' => 'ریال (IRR)'])->default('IRT'),
                                    TextInput::make('jibit_gateway_name')->label('نام نمایشی درگاه')
                                        ->placeholder('پرداخت آنلاین — جیبیت')->maxLength(100),
                                ])->columns(2),
                        ]),

                    Tabs\Tab::make('سیستم دعوت از دوستان')
                        ->icon('heroicon-o-gift')
                        ->schema([
                            Section::make('تنظیمات پاداش دعوت')
                                ->description('مبالغ پاداش را به تومان وارد کنید.')
                                ->schema([
                                    TextInput::make('referral_welcome_gift')->label('هدیه خوش‌آمدگویی')
                                        ->numeric()->default(0),
                                    TextInput::make('referral_referrer_reward')->label('پاداش معرف')
                                        ->numeric()->default(0),
                                ]),
                        ]),

                ])->columnSpanFull(),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $this->form->validate();
        $formData = $this->form->getState();

        // ── active_theme از toggle ──
        $formData['active_theme'] = ($formData['main_theme_enabled'] ?? true) ? 'rocket' : 'welcome';
        unset($formData['main_theme_enabled']);

        // ── پردازش لوگو ──
        // FileUpload با disk=public مقدار را به صورت "logos/filename.ext" برمی‌گرداند
        $logoArray = $formData['login_logo'] ?? [];
        if (is_array($logoArray) && count($logoArray) > 0) {
            $item = array_values($logoArray)[0];
            if (is_string($item) && strlen($item) > 0) {
                $formData['login_logo'] = $item;
            } else {
                unset($formData['login_logo']);
            }
        } else {
            $formData['login_logo'] = ''; // حذف لوگوی سفارشی و بازگشت به پیش‌فرض
        }

        foreach ($formData as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        Cache::forget('settings');
        Cache::forget('jibit_access_token');
        Cache::forget('jibit_refresh_token');

        // لوگو ذخیره‌شده را به public/uploads/logos کپی کن تا بدون symlink قابل دسترسی باشد
        $savedLogo = Setting::where('key', 'login_logo')->value('value');
        if ($savedLogo) {
            $src = storage_path('app/public/' . $savedLogo);
            if (file_exists($src)) {
                $pubDir = public_path('uploads/logos');
                if (!is_dir($pubDir)) mkdir($pubDir, 0755, true);
                copy($src, $pubDir . '/' . basename($savedLogo));
            }
        }

        $this->mount();
        Notification::make()->title('تنظیمات با موفقیت ذخیره شد.')->success()->send();
    }
}
