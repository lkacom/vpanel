<?php

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\PaymentsResource;
use App\Models\Order;
use App\Models\User;
use App\Filament\Pages\VpnSettings;
use App\Services\XUIService;
use App\Services\MarzbanService;
use App\Traits\ManagesServiceProvisioning;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
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

it('shows separate Sanaei and TX-UI choices in initial panel setup', function () {
    Livewire::test(VpnSettings::class)
        ->assertSee('سنایی (3X-UI)')
        ->assertSee('پنل علیرضا (x-ui/tx-ui)');
});

it('accepts the standard Sanaei login response and rejects an explicit failure', function () {
    Http::fake([
        'https://sanaei.test/*' => Http::response(['success' => true, 'msg' => '登录成功'], 200, [
            'Set-Cookie' => 'session=valid; Path=/',
        ]),
        'https://invalid-sanaei.test/*' => Http::response(['success' => false, 'msg' => '用户名或密码错误'], 200),
    ]);

    expect((new XUIService('https://sanaei.test', 'admin', 'correct-password'))->login())->toBeTrue();
    expect((new XUIService('https://invalid-sanaei.test/base', 'admin', 'wrong-password'))->login())->toBeFalse();

    Http::assertSent(fn ($request) => $request->url() === 'https://sanaei.test/login'
        && $request->data()['username'] === 'admin'
        && $request->data()['password'] === 'correct-password');
});

it('accepts a Marzban token and rejects a missing token', function () {
    Http::fake([
        'https://marzban.test/api/admin/token' => Http::response(['access_token' => 'token'], 200),
        'https://invalid-marzban.test/api/admin/token' => Http::response(['detail' => 'Invalid credentials'], 401),
    ]);

    expect((new MarzbanService('https://marzban.test', 'admin', 'correct-password', 'node.test'))->login())->toBeTrue()
        ->and((new MarzbanService('https://invalid-marzban.test', 'admin', 'wrong-password', 'node.test'))->login())->toBeFalse();
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
