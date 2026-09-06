<?php

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PaymentsResource;
use App\Models\Order;
use App\Models\User;
use App\Traits\ManagesServiceProvisioning;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Modules\Ticketing\Filament\Resources\TicketResource;

it('keeps the core admin resources available after Telegram removal', function () {
    expect(OrderResource::getNavigationLabel())->toBeString()->not->toBeEmpty()
        ->and(PaymentsResource::getNavigationLabel())->toBeString()->not->toBeEmpty()
        ->and(TicketResource::getNavigationLabel())->toBeString()->not->toBeEmpty();
});

it('preserves the login csrf token across separate requests', function () {
    $this->get(route('login'))->assertOk();

    $response = $this->post(route('login'), [
        '_token' => session()->token(),
        'email' => 'invalid@example.com',
        'password' => 'invalid-password',
    ]);

    expect($response->status())->not->toBe(419);
});

it('creates an admin account that can authenticate with the configured credentials', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', env('ADMIN_EMAIL', 'admin@example.com'))->first();

    expect($admin)->not->toBeNull()
        ->and($admin->is_admin)->toBeTrue()
        ->and(Hash::check(env('ADMIN_PASSWORD', 'admin'), $admin->password))->toBeTrue()
        ->and(Auth::attempt([
            'email' => env('ADMIN_EMAIL', 'admin@example.com'),
            'password' => env('ADMIN_PASSWORD', 'admin'),
        ]))->toBeTrue();

    Auth::logout();
});

it('reports invalid service orders through the normal admin notification flow', function () {
    $provisioner = new class {
        use ManagesServiceProvisioning;
    };

    $result = $provisioner->provisionService('marzban', collect(), Order::make([
        'id' => 1001,
        'plan_id' => null,
    ]));

    expect($result)->toBeFalse();
    expect(collect(session('filament.notifications', []))->pluck('title'))
        ->toContain('خطا در ساخت سرویس');
});

it('contains no executable Telegram bot artifacts', function () {
    $artifacts = [
        base_path('Modules/TelegramBot'),
        app_path('Console/Commands/SetTelegramWebhook.php'),
        app_path('Helpers/TelegramHelper.php'),
        app_path('Jobs/SendTelegramBroadcast.php'),
        config_path('telegram.php'),
    ];

    foreach ($artifacts as $artifact) {
        expect(file_exists($artifact))->toBeFalse("Telegram artifact still exists: {$artifact}");
    }
});
