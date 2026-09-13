<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            پرداخت سفارش #{{ $order->id }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6 space-y-8">


                @if (session('status'))
                    <div class="bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 rounded-xl">
                        <p>{{ session('status') }}</p>
                    </div>
                @endif
                @if (session('error'))
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-xl">
                        <p>{{ session('error') }}</p>
                    </div>
                @endif


                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 text-right border-b dark:border-gray-700 pb-3 mb-4">
                        جزئیات فاکتور
                    </h3>
                    <div class="space-y-3 text-right">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500 dark:text-gray-400">موضوع:</span>
                            <span class="font-semibold text-gray-800 dark:text-gray-200">
                                @if ($order->plan)
                                    {{ $order->renews_order_id ? 'تمدید سرویس' : 'خرید سرویس' }} ({{ $order->plan->name }})
                                @else
                                    شارژ کیف پول
                                @endif
                            </span>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500 dark:text-gray-400">مبلغ قابل پرداخت:</span>
                            <span class="font-bold text-lg text-green-500">
                                {{ number_format($order->plan->price ?? $order->amount) }} تومان
                            </span>
                        </div>
                    </div>
                </div>


                <div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 text-right">
                        انتخاب روش پرداخت
                    </h3>
                    <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-6">


                        @if ($order->plan)
                        <form method="POST" action="{{ route('payment.wallet.process', $order->id) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full text-center p-6 border-2 rounded-lg transition dark:border-gray-600
                                               @if($order->plan->price > auth()->user()->balance)
                                                   border-red-400 cursor-not-allowed bg-red-50 dark:bg-red-900/20
                                               @else
                                                   hover:border-purple-500 dark:hover:border-purple-500
                                               @endif"
                                    @if($order->plan->price > auth()->user()->balance) disabled @endif>

                                <h4 class="font-bold text-gray-900 dark:text-gray-100">پرداخت از کیف پول (آنی)</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                                    موجودی شما: {{ number_format(auth()->user()->balance) }} تومان
                                </p>
                                @if ($order->plan->price > auth()->user()->balance)
                                    <p class="text-xs font-semibold text-red-500 mt-2">موجودی کافی نیست</p>
                                @endif
                            </button>
                        </form>
                        @endif



                        <form method="POST" action="{{ route('payment.card.process', $order->id) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full text-center p-6 border-2 rounded-lg hover:border-blue-500 transition dark:border-gray-600 dark:hover:border-blue-500">
                                <h4 class="font-bold text-gray-900 dark:text-gray-100">پرداخت با کارت به کارت</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">
                                    ارسال رسید و انتظار برای تایید
                                </p>
                            </button>
                        </form>

                        {{-- درگاه زرین‌پال --}}
                        @php
                            $zarinpalEnabled = \App\Models\Setting::where('key', 'zarinpal_merchant_id')->whereNotNull('value')->where('value', '!=', '')->exists();
                            $zarinpalSandbox = filter_var(\App\Models\Setting::where('key', 'zarinpal_sandbox')->value('value'), FILTER_VALIDATE_BOOLEAN);
                        @endphp
                        @if($zarinpalEnabled)
                        <form method="POST" action="{{ route('payment.zarinpal.initiate', $order->id) }}">
                            @csrf
                            <button type="submit"
                                    class="w-full text-center p-6 border-2 rounded-lg hover:border-yellow-400 transition dark:border-gray-600 dark:hover:border-yellow-400 group">
                                <div class="flex items-center justify-center mb-2">
                                    <svg class="w-7 h-7 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                    </svg>
                                </div>
                                <h4 class="font-bold text-gray-900 dark:text-gray-100">پرداخت آنلاین — زرین‌پال</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-2">پرداخت سریع و امن با کارت بانکی</p>
                                @if($zarinpalSandbox)
                                <span class="inline-block mt-2 text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">حالت آزمایشی</span>
                                @endif
                            </button>
                        </form>
                        @endif

                        {{-- گزینه ارز دیجیتال (غیرفعال) --}}
                        <div class="w-full text-center p-6 border-2 rounded-lg transition dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 cursor-not-allowed opacity-60">
                            <h4 class="font-bold text-gray-500 dark:text-gray-400">پرداخت با ارز دیجیتال</h4>
                            <p class="text-sm text-gray-500 dark:text-gray-500 mt-2">
                                (به زودی)
                            </p>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
