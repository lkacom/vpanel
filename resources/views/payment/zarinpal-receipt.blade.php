<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            رسید پرداخت
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-lg mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-2xl p-8 text-right">

                @if(session('status'))
                {{-- پرداخت موفق --}}
                <div class="flex flex-col items-center text-center mb-8">
                    <div class="w-20 h-20 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center mb-4">
                        <svg class="w-10 h-10 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-green-600 dark:text-green-400">پرداخت موفق!</h2>
                    <p class="text-gray-500 dark:text-gray-400 mt-2">{{ session('status') }}</p>
                </div>

                <div class="bg-gray-50 dark:bg-gray-700/50 rounded-xl p-5 space-y-4 text-sm mb-8">
                    @if(session('zarinpal_ref_id'))
                    <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-600 pb-3">
                        <span class="text-gray-500 dark:text-gray-400">کد رهگیری:</span>
                        <span class="font-mono font-bold text-gray-800 dark:text-gray-100 select-all text-base">
                            {{ session('zarinpal_ref_id') }}
                        </span>
                    </div>
                    @endif
                    @if(session('zarinpal_amount'))
                    <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-600 pb-3">
                        <span class="text-gray-500 dark:text-gray-400">مبلغ پرداخت‌شده:</span>
                        <span class="font-bold text-gray-800 dark:text-gray-100">
                            {{ number_format(session('zarinpal_amount')) }} تومان
                        </span>
                    </div>
                    @endif
                    @if(session('zarinpal_card'))
                    <div class="flex justify-between items-center border-b border-gray-200 dark:border-gray-600 pb-3">
                        <span class="text-gray-500 dark:text-gray-400">شماره کارت:</span>
                        <span class="font-mono text-gray-800 dark:text-gray-100 dir-ltr">
                            {{ session('zarinpal_card') }}
                        </span>
                    </div>
                    @endif
                    @if(session('zarinpal_sandbox'))
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500 dark:text-gray-400">حالت:</span>
                        <span class="text-amber-600 font-medium">آزمایشی (Sandbox)</span>
                    </div>
                    @endif
                    <div class="flex justify-between items-center">
                        <span class="text-gray-500 dark:text-gray-400">تاریخ:</span>
                        <span class="text-gray-800 dark:text-gray-100">
                            {{ verta(now())->format('Y/m/d H:i') }}
                        </span>
                    </div>
                </div>

                @elseif(session('error'))
                {{-- پرداخت ناموفق --}}
                <div class="flex flex-col items-center text-center mb-8">
                    <div class="w-20 h-20 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center mb-4">
                        <svg class="w-10 h-10 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-red-600 dark:text-red-400">پرداخت ناموفق</h2>
                    <p class="text-gray-500 dark:text-gray-400 mt-2">{{ session('error') }}</p>
                </div>
                @else
                {{-- حالت لغو --}}
                <div class="flex flex-col items-center text-center mb-8">
                    <div class="w-20 h-20 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-4">
                        <svg class="w-10 h-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-gray-600 dark:text-gray-300">پرداخت لغو شد</h2>
                    <p class="text-gray-500 dark:text-gray-400 mt-2">پرداخت توسط شما لغو شد یا با خطا مواجه شد.</p>
                </div>
                @endif

                <div class="flex flex-col gap-3">
                    <a href="{{ route('dashboard') }}"
                       class="w-full text-center bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl py-3 transition-colors">
                        بازگشت به داشبورد
                    </a>
                </div>

            </div>
        </div>
    </div>
</x-app-layout>
