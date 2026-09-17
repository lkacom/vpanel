<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            نتیجه پرداخت جیبیت
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-6 text-right">
                @if (!empty($error))
                    <div class="rounded-lg bg-red-100 border border-red-300 text-red-800 p-4 mb-6">
                        {{ $error }}
                    </div>
                    <p class="text-gray-600 dark:text-gray-300 mb-6">
                        در صورت کسر شدن مبلغ، از انجام پرداخت مجدد خودداری کنید و با پشتیبانی تماس بگیرید.
                    </p>
                @else
                    <div class="rounded-lg bg-green-100 border border-green-300 text-green-800 p-4 mb-6">
                        پرداخت با موفقیت تأیید شد و سفارش شما در حال تکمیل است.
                    </div>
                    <dl class="space-y-3 text-gray-700 dark:text-gray-200">
                        <div class="flex justify-between gap-4">
                            <dt>مبلغ</dt>
                            <dd class="font-semibold">{{ number_format($amount ?? 0) }} تومان</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>کد رهگیری</dt>
                            <dd class="font-mono">{{ $ref_id ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt>شماره سفارش</dt>
                            <dd>#{{ $order->id }}</dd>
                        </div>
                    </dl>
                @endif

                <a href="{{ route('dashboard') }}"
                   class="inline-block mt-8 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2">
                    بازگشت به داشبورد
                </a>
            </div>
        </div>
    </div>
</x-app-layout>
