<?php

use App\Filament\Pages\VpnSettings;
use App\Models\Inbound;
use App\Models\Setting;
use App\Services\AlirezaXUIService;
use App\Services\SanaeiXUIService;
use App\Services\XUIServiceFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

it('uses the CSRF-protected Sanaei API paths and JSON client payloads', function () {
    Http::fake([
        'https://sanaei.test/csrf-token' => Http::response([
            'success' => true,
            'obj' => 'csrf-token',
        ], 200, ['Set-Cookie' => 'session=csrf-session; Path=/']),
        'https://sanaei.test/login' => Http::response([
            'success' => true,
            'msg' => 'Login successful',
        ], 200, ['Set-Cookie' => 'session=authenticated-session; Path=/']),
        'https://sanaei.test/panel/api/inbounds/list' => Http::response([
            'success' => true,
            'obj' => [[
                'id' => 7,
                'remark' => 'Sanaei VLESS',
                'enable' => true,
            ]],
        ]),
        'https://sanaei.test/panel/api/inbounds/get/7' => Http::response([
            'success' => true,
            'obj' => [
                'id' => 7,
                'settings' => json_encode([
                    'clients' => [[
                        'id' => 'existing-uuid',
                        'email' => 'user@example.com',
                        'subId' => 'existing-sub-id',
                        'totalGB' => 1,
                        'expiryTime' => 0,
                        'enable' => true,
                    ]],
                ]),
            ],
        ]),
        'https://sanaei.test/panel/api/clients/add' => Http::response([
            'success' => true,
            'msg' => 'Client added',
        ]),
        'https://sanaei.test/panel/api/clients/update/*' => Http::response([
            'success' => true,
            'msg' => 'Client updated',
        ]),
    ]);

    $service = new SanaeiXUIService('https://sanaei.test', 'admin', 'correct-password');

    expect($service->login())->toBeTrue()
        ->and($service->getInbounds())->toHaveCount(1)
        ->and($service->getClients(7))->toHaveCount(1);

    $created = $service->addClient(7, [
        'email' => 'new@example.com',
        'total' => 10737418240,
        'expiryTime' => 1767225600000,
    ]);
    $updated = $service->updateClient(7, 'existing-uuid', [
        'email' => 'user@example.com',
        'total' => 21474836480,
        'expiryTime' => 1767225600000,
    ]);

    expect($created['success'])->toBeTrue()
        ->and($created['generated_uuid'])->toBeString()->not->toBeEmpty()
        ->and($created['generated_subId'])->toBeString()->not->toBeEmpty()
        ->and($updated['success'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://sanaei.test/login'
        && $request->hasHeader('X-CSRF-Token', 'csrf-token')
        && $request->data() === ['username' => 'admin', 'password' => 'correct-password']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://sanaei.test/panel/api/inbounds/list');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://sanaei.test/panel/api/clients/add'
        && $request->data()['inboundIds'] === [7]
        && $request->data()['client']['email'] === 'new@example.com'
        && $request->data()['client']['totalGB'] === 10737418240);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://sanaei.test/panel/api/clients/update/')
        && $request->data()['email'] === 'user@example.com'
        && $request->data()['totalGB'] === 21474836480);
});

it('uses the Alireza API paths and form-encoded client payloads', function () {
    Http::fake([
        'https://alireza.test/panel/login' => Http::response([
            'success' => true,
            'msg' => 'Login successful',
        ], 200, ['Set-Cookie' => 'session=authenticated-session; Path=/panel']),
        'https://alireza.test/panel/xui/API/inbounds/' => Http::response([
            'success' => true,
            'obj' => [[
                'id' => 9,
                'remark' => 'Alireza VLESS',
                'enable' => true,
            ]],
        ]),
        'https://alireza.test/panel/xui/API/inbounds/get/9' => Http::response([
            'success' => true,
            'obj' => [
                'id' => 9,
                'settings' => json_encode([
                    'clients' => [[
                        'id' => 'existing-uuid',
                        'email' => 'user@example.com',
                        'subId' => 'existing-sub-id',
                        'totalGB' => 1,
                        'expiryTime' => 0,
                        'enable' => true,
                    ]],
                ]),
            ],
        ]),
        'https://alireza.test/panel/xui/API/inbounds/addClient' => Http::response([
            'success' => true,
            'msg' => 'Client added',
        ]),
        'https://alireza.test/panel/xui/API/inbounds/updateClient/*' => Http::response([
            'success' => true,
            'msg' => 'Client updated',
        ]),
    ]);

    $service = new AlirezaXUIService('https://alireza.test/panel', 'admin', 'correct-password');

    expect($service->login())->toBeTrue()
        ->and($service->getInbounds())->toHaveCount(1)
        ->and($service->getClients(9))->toHaveCount(1);

    $created = $service->addClient(9, [
        'email' => 'new@example.com',
        'total' => 10737418240,
        'expiryTime' => 1767225600000,
    ]);
    $updated = $service->updateClient(9, 'existing-uuid', [
        'email' => 'user@example.com',
        'total' => 21474836480,
        'expiryTime' => 1767225600000,
    ]);

    expect($created['success'])->toBeTrue()
        ->and($created['generated_uuid'])->toBeString()->not->toBeEmpty()
        ->and($updated['success'])->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://alireza.test/panel/login'
        && $request->data() === ['username' => 'admin', 'password' => 'correct-password']);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://alireza.test/panel/xui/API/inbounds/');
    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || $request->url() !== 'https://alireza.test/panel/xui/API/inbounds/addClient') {
            return false;
        }

        $settings = json_decode($request->data()['settings'], true);

        return $request->data()['id'] === 9
            && $settings['clients'][0]['email'] === 'new@example.com'
            && $settings['clients'][0]['totalGB'] === 10737418240;
    });
    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST' || ! str_starts_with($request->url(), 'https://alireza.test/panel/xui/API/inbounds/updateClient/')) {
            return false;
        }

        $settings = json_decode($request->data()['settings'], true);

        return $request->data()['id'] === 9
            && $settings['clients'][0]['email'] === 'user@example.com'
            && $settings['clients'][0]['totalGB'] === 21474836480;
    });
});

it('does not accept an HTTP-success response that the panel marked as failed', function () {
    Http::fake([
        'https://failed-sanaei.test/csrf-token' => Http::response(['success' => true, 'obj' => 'csrf-token']),
        'https://failed-sanaei.test/login' => Http::response(['success' => true]),
        'https://failed-sanaei.test/panel/api/inbounds/list' => Http::response([
            'success' => false,
            'msg' => 'permission denied',
        ]),
    ]);

    $service = new SanaeiXUIService('https://failed-sanaei.test', 'admin', 'correct-password');

    expect($service->getInbounds())->toBe([]);
});

it('maps panel types to their dedicated services and preserves the legacy Sanaei value', function () {
    expect(XUIServiceFactory::make('sanaei', 'https://panel.test', 'admin', 'password'))
        ->toBeInstanceOf(SanaeiXUIService::class)
        ->and(XUIServiceFactory::make('xui', 'https://panel.test', 'admin', 'password'))
        ->toBeInstanceOf(SanaeiXUIService::class)
        ->and(XUIServiceFactory::make('txui', 'https://panel.test', 'admin', 'password'))
        ->toBeInstanceOf(AlirezaXUIService::class);
});

it('synchronizes inbounds only after either configured XUI panel is authenticated', function () {
    $panels = [
        'sanaei' => [
            'host' => 'https://sanaei.test',
            'csrf' => 'https://sanaei.test/csrf-token',
            'login' => 'https://sanaei.test/login',
            'inbounds' => 'https://sanaei.test/panel/api/inbounds/list',
        ],
        'txui' => [
            'host' => 'https://alireza.test/panel',
            'csrf' => null,
            'login' => 'https://alireza.test/panel/login',
            'inbounds' => 'https://alireza.test/panel/xui/API/inbounds/',
        ],
    ];

    foreach ($panels as $panelType => $panel) {
        Inbound::query()->delete();
        Setting::query()->delete();
        Cache::flush();

        Inbound::create([
            'title' => 'Stale inbound',
            'inbound_data' => ['id' => 999, 'enable' => true],
        ]);
        Setting::create(['key' => 'xui_default_inbound_id', 'value' => '999']);

        $responses = [
            $panel['login'] => Http::response(['success' => true, 'msg' => 'Login successful']),
            $panel['inbounds'] => Http::response([
                'success' => true,
                'obj' => [[
                    'id' => 12,
                    'remark' => strtoupper($panelType).' inbound',
                    'enable' => true,
                    'protocol' => 'vless',
                    'port' => 443,
                ]],
            ]),
        ];
        if ($panel['csrf']) {
            $responses[$panel['csrf']] = Http::response(['success' => true, 'obj' => 'csrf-token']);
        }
        Http::fake($responses);

        Livewire::test(VpnSettings::class)
            ->set('data.panel_type', $panelType)
            ->set('data.xui_host', $panel['host'])
            ->set('data.xui_user', 'admin')
            ->set('data.xui_pass', 'correct-password')
            ->set('data.xui_link_type', 'single')
            ->call('submit', true)
            ->assertHasNoErrors();

        expect(Inbound::query()->count())->toBe(1)
            ->and(Inbound::query()->sole()->inbound_data['id'])->toBe(12)
            ->and(Setting::where('key', 'panel_type')->value('value'))->toBe($panelType)
            // The global default is no longer changed; each plan owns its inbound.
            ->and(Setting::where('key', 'xui_default_inbound_id')->value('value'))->toBe('999');
    }
});
