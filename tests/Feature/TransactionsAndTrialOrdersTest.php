<?php

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use App\Models\User;

it('lists only financial transactions with purpose, method, tracking code and date on the user dashboard', function () {
    $user = User::factory()->create();

    // شارژ کیف پول با زرین‌پال (موفق)
    Order::create([
        'user_id' => $user->id, 'plan_id' => null, 'amount' => 50000, 'status' => 'paid',
        'payment_method' => 'zarinpal', 'zarinpal_ref_id' => '987654321',
    ]);

    // فیش کارت به کارت منتظر تایید
    Order::create([
        'user_id' => $user->id, 'plan_id' => null, 'amount' => 70000, 'status' => 'pending',
        'payment_method' => 'card', 'card_payment_receipt' => 'receipts/x.jpg',
    ]);

    // هنوز روش پرداخت انتخاب نشده → تراکنش نیست
    Order::create(['user_id' => $user->id, 'plan_id' => null, 'amount' => 11111, 'status' => 'pending']);

    // اکانت تست رایگان → تراکنش مالی نیست
    Order::create([
        'user_id' => $user->id, 'plan_id' => null, 'amount' => 0, 'status' => 'paid',
        'payment_method' => Order::PAYMENT_TRIAL, 'source' => 'trial',
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('تراکنش‌های مالی')
        ->assertSee('شارژ کیف پول')
        ->assertSee('درگاه زرین‌پال')
        ->assertSee('987654321')
        ->assertSee('50,000')
        ->assertSee('کارت به کارت')
        ->assertSee('70,000')
        ->assertSee('در انتظار تایید')
        ->assertDontSee('11,111');
});

it('shows free trial accounts in the admin orders list but not in sales statistics', function () {
    $user = User::factory()->create();

    $trial = Order::create([
        'user_id' => $user->id, 'plan_id' => null, 'amount' => 0, 'status' => 'paid',
        'payment_method' => Order::PAYMENT_TRIAL, 'source' => 'trial',
        'config_details' => 'https://sub.example.test/sub/abc', 'expires_at' => now()->addDay(),
    ]);

    $charge = Order::create([
        'user_id' => $user->id, 'plan_id' => null, 'amount' => 50000, 'status' => 'paid', 'payment_method' => 'card',
    ]);

    $listed = OrderResource::getEloquentQuery()->pluck('orders.id')->all();

    expect($listed)->toContain($trial->id)
        ->and($listed)->not->toContain($charge->id) // شارژ کیف پول در «مدیریت شارژ» است
        ->and(Order::query()->excludingTrial()->pluck('id')->all())->not->toContain($trial->id)
        ->and($trial->is_config_active)->toBeTrue();
});
