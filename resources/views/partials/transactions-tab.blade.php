{{-- برگه «تراکنش‌ها» داشبورد کاربر: فقط تراکنش‌های مالی (شارژ کیف پول، خرید و تمدید سرویس) --}}
<h2 class="text-xl font-bold mb-4 text-gray-900 dark:text-white text-right">تراکنش‌های مالی</h2>

<div class="space-y-3">
    @forelse ($transactions as $transaction)
        @php
            $amount = $transaction->paid_amount ?? $transaction->amount ?? $transaction->plan?->price ?? 0;
        @endphp
        <div class="p-4 rounded-xl bg-gray-50 dark:bg-gray-800/50">
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-6 gap-4 items-center text-right">
                <div class="md:col-span-2">
                    <span class="text-xs text-gray-500">بابت</span>
                    <p class="font-bold text-gray-900 dark:text-white">{{ $transaction->purpose_label }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">مبلغ</span>
                    <p class="font-bold text-gray-900 dark:text-white">{{ number_format((float) $amount) }} تومان</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">روش پرداخت</span>
                    <p class="font-semibold text-gray-900 dark:text-white">{{ $transaction->payment_method_label }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">شماره رهگیری</span>
                    <p class="font-mono text-gray-900 dark:text-white" dir="ltr">{{ $transaction->tracking_code }}</p>
                </div>
                <div>
                    <span class="text-xs text-gray-500">تاریخ</span>
                    <p class="font-mono text-gray-900 dark:text-white" dir="ltr">{{ \App\Support\PersianDate::format($transaction->created_at, 'Y/m/d H:i') }}</p>
                </div>
            </div>

            <div class="mt-3 text-right">
                @if ($transaction->status === 'paid')
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">موفق</span>
                @elseif ($transaction->status === 'pending')
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">در انتظار تایید</span>
                @else
                    <span class="px-3 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">ناموفق</span>
                @endif
            </div>
        </div>
    @empty
        <p class="text-gray-500 dark:text-gray-400 text-center py-10">هیچ تراکنشی یافت نشد.</p>
    @endforelse
</div>
