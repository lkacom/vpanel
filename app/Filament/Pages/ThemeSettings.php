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
use Illuminate\Support\Facades\Storage;

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

        // تبدیل مقدار active_theme به boolean برای toggle
        $settings['main_theme_enabled'] = ($settings['active_theme'] ?? 'rocket') === 'rocket';

        // لوگو ذخیره‌شده — FileUpload انتظار array دارد
        if (!empty($settings['site_logo'])) {
            $settings['site_logo'] = [$settings['site_logo']];
        } else {
            $settings['site_logo'] = [];
        }

        $this->form->fill(array_merge([
            'main_theme_enabled' => true,
            'site_logo'          => [],
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

                    // ──────────────────────────────────────────
                    //  تب ۱: تنظیمات قالب
                    // ──────────────────────────────────────────
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

                                    FileUpload::make('site_logo')
                                        ->label('لوگوی سایت (صفحه ورود کاربران)')
                                        ->helperText('در صورت عدم آپلود، لوگوی پیش‌فرض (/images/logo.png) استفاده می‌شود.')
                                        ->image()
                                        ->imagePreviewHeight('80')
                                        ->maxSize(1024)
                                        ->disk('public')
                                        ->directory('logos')
                                        ->visibility('public')
                                        ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'])
                                        ->columnSpanFull(),
                                ]),
                        ]),

                    // ──────────────────────────────────────────
                    //  تب ۲: محتوای قالب RoketVPN
                    // ──────────────────────────────────────────
                    Tabs\Tab::make('محتوای قالب RoketVPN (موشکی)')
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

                    // ──────────────────────────────────────────
                    //  تب ۳: تنظیمات پرداخت
                    // ──────────────────────────────────────────
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
                                    Toggle::make('zarinpal_active')
                                        ->label('فعال‌سازی درگاه زرین‌پال')
                                        ->helperText('درگاه را برای کاربران نمایش دهید یا مخفی کنید.')
                                        ->onColor('success')
                                        ->offColor('gray')
                                        ->columnSpanFull(),
                                    TextInput::make('zarinpal_merchant_id')
                                        ->label('کد پذیرنده (Merchant ID)')
                                        ->placeholder('xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx')
                                        ->helperText('از پنل زرین‌پال → درگاه‌ها → کد پذیرنده دریافت کنید.')
                                        ->maxLength(36)
                                        ->columnSpanFull(),
                                    Select::make('zarinpal_currency')
                                        ->label('واحد پول')
                                        ->options(['IRT' => 'تومان (IRT)', 'IRR' => 'ریال (IRR)'])
                                        ->default('IRT'),
                                    TextInput::make('zarinpal_gateway_name')
                                        ->label('نام نمایشی درگاه')
                                        ->placeholder('پرداخت آنلاین — زرین‌پال')
                                        ->helperText('این نام در دکمه انتخاب روش پرداخت به کاربر نمایش داده می‌شود.')
                                        ->maxLength(100),
                                    Toggle::make('zarinpal_sandbox')
                                        ->label('حالت آزمایشی (Sandbox)')
                                        ->helperText('فعال کنید تا پول واقعی کسر نشود — فقط برای تست.')
                                        ->onColor('warning')
                                        ->offColor('gray'),
                                ])
                                ->columns(2),
                        ]),

                    // ──────────────────────────────────────────
                    //  تب ۴: سیستم دعوت از دوستان
                    // ──────────────────────────────────────────
                    Tabs\Tab::make('سیستم دعوت از دوستان')
                        ->icon('heroicon-o-gift')
                        ->schema([
                            Section::make('تنظیمات پاداش دعوت')
                                ->description('مبالغ پاداش را به تومان وارد کنید.')
                                ->schema([
                                    TextInput::make('referral_welcome_gift')
                                        ->label('هدیه خوش‌آمدگویی')
                                        ->numeric()
                                        ->default(0)
                                        ->helperText('مبلغی که بلافاصله پس از ثبت‌نام با کد معرف، به کیف پول کاربر جدید اضافه می‌شود.'),
                                    TextInput::make('referral_referrer_reward')
                                        ->label('پاداش معرف')
                                        ->numeric()
                                        ->default(0)
                                        ->helperText('مبلغی که پس از اولین خرید موفق کاربر جدید، به کیف پول معرف او اضافه می‌شود.'),
                                ]),
                        ]),

                ])->columnSpanFull(),
        ])->statePath('data');
    }

    public function submit(): void
    {
        $this->form->validate();
        $formData = $this->form->getState();

        // ── active_theme از toggle ──────────────────────────
        $mainThemeEnabled = $formData['main_theme_enabled'] ?? true;
        $formData['active_theme'] = $mainThemeEnabled ? 'rocket' : 'welcome';
        unset($formData['main_theme_enabled']);

        // ── پردازش لوگو ────────────────────────────────────
        // FileUpload مقدار را به صورت array برمی‌گرداند
        $logoArray = $formData['site_logo'] ?? [];
        if (is_array($logoArray) && count($logoArray) > 0) {
            // مسیر نسبی فایل در disk public
            $formData['site_logo'] = array_values($logoArray)[0];
        } else {
            // اگر خالی است، کلید را حذف کن تا لوگوی قبلی پاک نشود
            unset($formData['site_logo']);
        }

        // ── ذخیره همه تنظیمات ──────────────────────────────
        foreach ($formData as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        Cache::forget('settings');
        Notification::make()->title('تنظیمات با موفقیت ذخیره شد.')->success()->send();
    }
}
