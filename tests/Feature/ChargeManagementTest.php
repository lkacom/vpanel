<?php

use App\Filament\Resources\ChargeResource;
use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

it('contains no Jibit gateway artifacts', function () {
    $artifacts = [
        app_path('Http/Controllers/JibitController.php'),
        app_path('Services/JibitService.php'),
        resource_path('views/payment/jibit-cancelled.blade.php'),
        resource_path('views/payment/jibit-receipt.blade.php'),
        database_path('migrations/2026_09_13_000000_add_jibit_columns_to_orders_table.php'),
    ];

    foreach ($artifacts as $artifact) {
        expect(file_exists($artifact))->toBeFalse("Jibit artifact still exists: {$artifact}");
    }

    expect(Route::has('payment.jibit.initiate'))->toBeFalse()
        ->and(Route::has('payment.jibit.callback'))->toBeFalse()
        ->and((new Order)->getFillable())->not->toContain('jibit_authority', 'jibit_ref_id');
});

it('registers the charge management menu next to orders without route conflicts', function () {
    expect(ChargeResource::getNavigationLabel())->toBe('مدیریت شارژ')
        ->and(ChargeResource::getNavigationGroup())->toBe(OrderResource::getNavigationGroup())
        ->and(ChargeResource::getSlug())->not->toBe(OrderResource::getSlug());
});

it('reports a paid order config as active only while it has a config and is not expired', function () {
    $active = Order::make(['status' => 'paid', 'config_details' => 'https://sub.test/sub/abc', 'expires_at' => now()->addDays(5)]);
    $expired = Order::make(['status' => 'paid', 'config_details' => 'https://sub.test/sub/abc', 'expires_at' => now()->subDay()]);
    $noConfig = Order::make(['status' => 'paid', 'config_details' => null, 'expires_at' => now()->addDays(5)]);
    $pending = Order::make(['status' => 'pending', 'config_details' => 'x', 'expires_at' => now()->addDays(5)]);

    expect($active->is_config_active)->toBeTrue()
        ->and($expired->is_config_active)->toBeFalse()
        ->and($noConfig->is_config_active)->toBeFalse()
        ->and($pending->is_config_active)->toBeFalse();
});
