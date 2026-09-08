<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Keep indexed UTF-8 strings compatible with MySQL/MariaDB servers
        // that enforce the legacy 1000-byte index limit.
        Schema::defaultStringLength(191);

        User::creating(function ($user) {
            do {
                $code = 'REF-' . strtoupper(\Illuminate\Support\Str::random(6));
            } while (User::where('referral_code', $code)->exists());

            $user->referral_code = $code;
        });

        // ==========================================================
    }
}
