<?php

use App\Models\Order;

it('has no Jibit settings left in the theme settings page', function () {
    $source = file_get_contents(app_path('Filament/Pages/ThemeSettings.php'));

    expect(strtolower($source))->not->toContain('jibit');
});

it('builds the receipt url from the authenticated receipt route (no storage symlink needed)', function () {
    $withReceipt = new Order(['card_payment_receipt' => 'receipts/abc.jpg']);
    $withReceipt->id = 15;
    $withoutReceipt = new Order(['card_payment_receipt' => null]);

    expect($withReceipt->receipt_url)->toEndWith('/order/15/receipt')
        ->and($withoutReceipt->receipt_url)->toBeNull();
});
