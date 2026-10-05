<?php

use App\Exceptions\TrialUnavailableException;
use App\Models\Inbound;
use App\Models\Setting;
use App\Models\TrialAccount;
use App\Models\User;
use App\Services\TrialAccountService;
use App\Support\TrialSettings;

function setSetting(string $key, mixed $value): void
{
    Setting::updateOrCreate(['key' => $key], ['value' => $value]);
}

function makeInbound(int $panelId, bool $enabled = true): Inbound
{
    return Inbound::create([
        'title'        => "inbound-{$panelId}",
        'inbound_data' => ['id' => $panelId, 'enable' => $enabled, 'remark' => "r{$panelId}", 'protocol' => 'vless', 'port' => 443],
    ]);
}

it('defaults to a limit of one trial per user and reads new and legacy keys', function () {
    setSetting('panel_type', 'marzban');
    setSetting('trial_enabled', true);

    $settings = TrialSettings::load();

    expect($settings->limitPerUser)->toBe(1)
        ->and($settings->enabled)->toBeTrue()
        ->and($settings->isProvisionable())->toBeTrue();

    // کلید قدیمی به‌عنوان fallback خوانده می‌شود
    setSetting('trial_volume_mb', 2);
    expect(TrialSettings::load()->volumeGb)->toBe(2.0);

    setSetting('trial_volume_gb', 0.5);
    expect(TrialSettings::load()->volumeGb)->toBe(0.5)
        ->and(TrialSettings::load()->volumeLabel())->toBe('512 مگابایت');
});

it('uses all active inbounds on Sanaei without any selection', function () {
    makeInbound(3);
    makeInbound(5);
    makeInbound(9, enabled: false);

    setSetting('panel_type', 'sanaei');
    setSetting('trial_enabled', true);
    setSetting('trial_inbound_ids', ['9']); // انتخاب ذخیره‌شده‌ی قدیمی روی ثنایی نادیده گرفته می‌شود

    $settings = TrialSettings::load();

    expect($settings->inboundIds)->toBe(['3', '5'])
        ->and($settings->isProvisionable())->toBeTrue();
});

it('uses only the single selected inbound on Alireza (txui)', function () {
    makeInbound(3);
    makeInbound(5);

    setSetting('panel_type', 'txui');
    setSetting('trial_enabled', true);
    setSetting('trial_inbound_ids', ['5', '3']);

    expect(TrialSettings::load()->inboundIds)->toBe(['5']);

    // بدون انتخاب Inbound اکانت قابل ساخت نیست
    setSetting('trial_inbound_ids', []);
    expect(TrialSettings::load()->isProvisionable())->toBeFalse();
});

it('does not need inbounds on Marzban', function () {
    setSetting('panel_type', 'marzban');
    setSetting('trial_enabled', true);

    expect(TrialSettings::load()->isProvisionable())->toBeTrue();
});

it('reports the trial as disabled only when the admin turns it off', function () {
    $user = User::factory()->create();
    setSetting('panel_type', 'sanaei');

    setSetting('trial_enabled', false);
    expect(app(TrialAccountService::class)->statusFor($user)['enabled'])->toBeFalse();

    setSetting('trial_enabled', true);
    expect(app(TrialAccountService::class)->statusFor($user)['enabled'])->toBeTrue();
});

it('refuses to create a trial account when the feature is disabled', function () {
    $user = User::factory()->create();
    setSetting('panel_type', 'marzban');
    setSetting('trial_enabled', false);

    expect(fn () => app(TrialAccountService::class)->claim($user))
        ->toThrow(TrialUnavailableException::class);

    expect(TrialAccount::count())->toBe(0);
});

it('shows a warning instead of the button for a user who already reached the limit', function () {
    $user = User::factory()->create(['trial_accounts_taken' => 1]);
    setSetting('panel_type', 'marzban');
    setSetting('trial_enabled', true);

    expect(app(TrialAccountService::class)->statusFor($user))
        ->toMatchArray(['can_claim' => false, 'remaining' => 0]);

    expect(fn () => app(TrialAccountService::class)->claim($user))
        ->toThrow(TrialUnavailableException::class, 'مجاز به دریافت اکانت تست نیستید');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('مجاز به دریافت اکانت تست نیستید')
        ->assertDontSee(route('trial.claim'));
});

it('shows the claim button on the dashboard while a trial is still available', function () {
    $user = User::factory()->create();
    setSetting('panel_type', 'marzban');
    setSetting('trial_enabled', true);
    setSetting('trial_limit_per_user', 2);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('دریافت اکانت تست رایگان')
        ->assertSee(route('trial.claim'))
        ->assertSee('2 از 2');
});

it('lists claimed trial accounts under My services and keeps the trial tab for the claim box', function () {
    $user = User::factory()->create(['trial_accounts_taken' => 1]);
    setSetting('panel_type', 'marzban');
    setSetting('trial_enabled', true);

    TrialAccount::create([
        'user_id'        => $user->id,
        'panel_username' => 'trial-1-abc123',
        'config_details' => 'https://sub.example.test/sub/trialtoken',
        'volume_gb'      => 0.5,
        'duration_days'  => 1,
        'expires_at'     => now()->addDay(),
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee("tab = 'trial'", escape: false)
        ->assertSee('https://sub.example.test/sub/trialtoken')
        ->assertSee('512 MB')
        ->assertSee('فعال');
});

it('requires login to claim a trial account', function () {
    $this->post('/trial')->assertRedirect('/login');
});
