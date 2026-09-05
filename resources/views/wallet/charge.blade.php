<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            شارژ کیف پول
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white/50 dark:bg-gray-800/50 backdrop-blur-xl overflow-hidden shadow-2xl sm:rounded-2xl">
                <div class="p-6 md:p-8 text-gray-900 dark:text-gray-100 text-right space-y-8" x-data="{ amount: '' }">

                    {{-- نمایش موجودی فعلی --}}
                    <div class="text-center">
                        <p class="text-sm text-gray-500 dark:text-gray-400">موجودی فعلی شما</p>
                        <p class="font-bold text-3xl text-green-500 mt-1">
                            {{ number_format(auth()->user()->balance) }}
                            <span class="text-lg font-normal">تومان</span>
                        </p>
                    </div>

                    {{-- فرم افزایش موجودی --}}
                    <form method="POST" action="{{ route('wallet.charge.create') }}" class="space-y-6">
                        @csrf
                        <div>
                            <h3 class="text-lg font-medium mb-4 text-center">افزایش موجودی</h3>

                            @if ($errors->any())
                                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                                    <p>{{ $errors->first() }}</p>
                                </div>
                            @endif

                            {{-- دکمه‌های انتخاب سریع --}}
                            <div class="grid grid-cols-3 gap-3 mb-4">
                                <button type="button" @click="amount = 50000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۵۰,۰۰۰</button>
                                <button type="button" @click="amount = 100000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۱۰۰,۰۰۰</button>
                                <button type="button" @click="amount = 250000" class="p-2 text-center bg-gray-200 dark:bg-gray-700 rounded-md hover:bg-indigo-500 hover:text-white transition">۲۵۰,۰۰۰</button>
                            </div>

                            {{-- فیلد ورود مبلغ --}}
                            <div class="relative">
                                <label for="amount" class="absolute -top-2 right-4 text-xs bg-white/50 dark:bg-gray-800/50 px-1 text-gray-500">یا مبلغ دلخواه را وارد کنید (تومان)</label>
                                <input id="amount" name="amount" x-model="amount" type="number" class="block mt-1 w-full p-4 text-lg text-center font-bold bg-transparent dark:bg-gray-700/50 border-2 border-gray-300 dark:border-gray-600 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" placeholder="حداقل ۱۰,۰۰۰" required>
                            </div>
                        </div>

                        {{-- دکمه نهایی --}}
                        <div>
                            <button type="submit" class="w-full flex items-center justify-center px-4 py-3 bg-gradient-to-r from-green-500 to-emerald-600 text-white font-semibold rounded-lg shadow-lg hover:scale-105 transform transition-transform duration-300">
                                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                                <span>ثبت و ادامه جهت پرداخت</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
