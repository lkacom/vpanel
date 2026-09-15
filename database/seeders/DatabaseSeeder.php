<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@example.com');
        $password = env('ADMIN_PASSWORD', 'admin');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => $password,
                'is_admin' => true,
            ],
        );

        $this->seedThemeContentDefaults();
    }

    /**
     * Seed the default Theme Content settings for the RoketVPN theme.
     *
     * These defaults are only created when the corresponding setting does not
     * already exist, so existing installations keep their current values and
     * never have their Theme Content settings overwritten.
     */
    private function seedThemeContentDefaults(): void
    {
        $defaults = [
            'rocket_navbar_brand' => 'برند شما',
            'rocket_footer_text' => 'کلیه حقوق برای برند شما محفوظ است.',
            'rocket_hero_title' => 'فروش کانفیگ و ارائه نمایندگی فروش',
            'rocket_hero_subtitle' => 'با شارژ کیف پول خود در هر زمان کانفیگ خود را به صورت خودکار برای مشتریان ایجاد کنید.',
            'rocket_hero_button_text' => 'سفارش دهید',
            'rocket_pricing_title' => 'قیمت سرویس ها',
            'rocket_faq_title' => 'سوالات متداول',
            'rocket_faq1_q' => 'چگونه پس از خرید از کانفیگ ها استفاده کنیم؟',
            'rocket_faq1_a' => 'پس از ورود به حساب کاربری در بخش راهنمای اتصال آموزش و لینکهای دانلود جهت استفاده از کانفیگ ها موجود است.',
        ];

        foreach ($defaults as $key => $value) {
            if (!Setting::where('key', $key)->exists()) {
                Setting::create([
                    'key' => $key,
                    'value' => $value,
                ]);
            }
        }
    }
}
