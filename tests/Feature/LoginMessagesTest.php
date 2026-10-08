<?php

use App\Models\User;

it('shows a translated Persian message (not the "auth.failed" key) for a wrong password', function () {
    $user = User::factory()->create();

    $response = $this->from('/login')->post('/login', [
        'email'    => $user->email,
        'password' => 'definitely-wrong-password',
    ]);

    $response->assertRedirect('/login')
        ->assertSessionHasErrors(['email' => 'ایمیل یا رمز عبور اشتباه است.']);

    expect(__('auth.failed'))->not->toBe('auth.failed')
        ->and(__('auth.throttle', ['seconds' => 30]))->toContain('30');
});
