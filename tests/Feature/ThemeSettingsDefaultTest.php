<?php

use App\Filament\Pages\ThemeSettings;
use App\Models\Setting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

it('shows the main site theme as disabled on a fresh install, matching the real behavior', function () {
    Livewire::test(ThemeSettings::class)
        ->assertFormSet(['main_theme_enabled' => false]);
});

it('shows the main site theme as enabled once the rocket theme is active', function () {
    Setting::updateOrCreate(['key' => 'active_theme'], ['value' => 'rocket']);

    Livewire::test(ThemeSettings::class)
        ->assertFormSet(['main_theme_enabled' => true]);
});
