<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * نمایش فیش‌های کارت به کارت فقط برای ادمین و صاحب سفارش.
 * فایل مستقیماً از storage خوانده می‌شود، بنابراین به symlink ی public/storage وابسته نیست.
 */
class ReceiptController extends Controller
{
    public function show(Order $order): mixed
    {
        $user = Auth::user();

        abort_unless($user && ($user->is_admin || $user->id === $order->user_id), 403);

        $path = $order->card_payment_receipt;
        abort_if(blank($path), 404);

        // فیش‌های جدید روی disk عمومی ذخیره می‌شوند؛ disk خصوصی برای سازگاری در نظر گرفته شده است.
        foreach (['public', 'local'] as $disk) {
            if (Storage::disk($disk)->exists($path)) {
                return Storage::disk($disk)->response($path);
            }
        }

        abort(404);
    }
}
