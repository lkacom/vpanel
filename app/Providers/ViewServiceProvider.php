<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

use Illuminate\Support\Facades\Schema;
class ViewServiceProvider extends ServiceProvider
{
    public function boot(): void
    {




        if (Schema::hasTable('settings')) {
            $settings = Setting::all()->pluck('value', 'key');

            View::share('settings', $settings);
        }



    }
}
