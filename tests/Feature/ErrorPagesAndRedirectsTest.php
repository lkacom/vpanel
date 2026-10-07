<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

it('redirects guests from the home page to /login when the main theme is disabled', function () {
    Setting::updateOrCreate(['key' => 'active_theme'], ['value' => 'welcome']);

    $this->get('/')->assertRedirect(route('login'));
});

it('redirects logged in users from the home page to the dashboard when the main theme is disabled', function () {
    Setting::updateOrCreate(['key' => 'active_theme'], ['value' => 'welcome']);

    $this->actingAs(User::factory()->create())
        ->get('/')
        ->assertRedirect(route('dashboard'));
});

it('shows the custom 404 page', function () {
    $this->get('/this-page-does-not-exist')
        ->assertNotFound()
        ->assertSee('404')
        ->assertSee('صفحه مورد نظر پیدا نشد');
});

it('sends users to the user login page when the session expires (419)', function () {
    Route::post('/_test-419', fn () => throw new TokenMismatchException('expired'))->middleware('web');

    $this->post('/_test-419')
        ->assertRedirect(route('login'))
        ->assertSessionHas('status');
});

it('sends admins to the admin login page when the session expires (419)', function () {
    Route::post('/admin/_test-419', fn () => throw new TokenMismatchException('expired'))->middleware('web');

    $this->post('/admin/_test-419')
        ->assertRedirect(route('filament.admin.auth.login'));
});
