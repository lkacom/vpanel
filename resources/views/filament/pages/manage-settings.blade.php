<x-filament-panels::page>
    <div class="space-y-6">

        {{-- Tabs --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="-mb-px flex space-x-4 rtl:space-x-reverse" aria-label="Tabs">

                <button type="button"
                        wire:click="$set('activeTab', 'messages')"
                        class="px-4 py-2 text-sm font-medium border-b-2 transition-all duration-200
                        {{ $activeTab === 'messages' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' }}">
                    پیام‌ها
                </button>

                <button type="button"
                        wire:click="$set('activeTab', 'wallet')"
                        class="px-4 py-2 text-sm font-medium border-b-2 transition-all duration-200
                        {{ $activeTab === 'wallet' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' }}">
                    کیف پول
                </button>

                <button type="button"
                        wire:click="$set('activeTab', 'tutorials')"
                        class="px-4 py-2 text-sm font-medium border-b-2 transition-all duration-200
                        {{ $activeTab === 'tutorials' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:hover:text-gray-300' }}">
                    آموزش‌ها
                </button>
            </nav>
        </div>

        {{-- بخش جدید: نمایش مبالغ فعلی به صورت Badge --}}
        @if($activeTab === 'wallet' && !empty($currentAmounts))
            <div class="p-4 bg-gray-50 dark:bg-gray-800/50 rounded-lg">
                <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">مبلغ‌های پیش‌فرض فعلی:</h3>
                <div class="flex flex-wrap gap-2">
                    @foreach($currentAmounts as $item)
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-primary-100 text-primary-800 dark:bg-primary-500/20 dark:text-primary-400">
                            {{ number_format($item['amount']) }} تومان
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Tab Content --}}
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow p-6">
            <form wire:submit.prevent="submit" class="space-y-6">
                {{ $this->form }}

                <div class="mt-4">
                    <x-filament::button type="submit">ذخیره تغییرات</x-filament::button>
                </div>
            </form>
        </div>

    </div>
</x-filament-panels::page>
