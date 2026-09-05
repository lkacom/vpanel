<x-filament-panels::page>
    <div class="space-y-8">


        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <x-heroicon-o-cloud-arrow-up class="w-5 h-5 text-primary-500 animate-bounce"/>
                    <span class="text-lg font-semibold text-primary-700 dark:text-primary-300">نصب افزونه جدید</span>
                </div>
            </x-slot>

            <form wire:submit="installModule" class="p-6 bg-gradient-to-tr from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-900 rounded-2xl shadow-lg">
                {{ $this->form }}
                <div class="mt-6 flex justify-end">
                    <x-filament::button type="submit" icon="heroicon-o-arrow-down-on-square" size="lg"
                                        wire:loading.attr="disabled" class="transition hover:scale-105">
                        <span wire:loading.remove>آپلود و نصب افزونه</span>
                        <span wire:loading>در حال نصب...</span>
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>


        <x-filament::section>
            <x-slot name="heading">
                <div class="flex items-center space-x-2 space-x-reverse">
                    <x-heroicon-o-puzzle-piece class="w-5 h-5 text-primary-500"/>
                    <span class="text-lg font-semibold text-primary-700 dark:text-primary-300">افزونه‌های نصب شده</span>
                </div>
            </x-slot>

            @forelse ($modules as $module)
                <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-md hover:shadow-xl transition transform hover:scale-[1.02] p-5 grid grid-cols-1 md:grid-cols-2 gap-4 items-center">

                    <div class="space-y-2">
                        <p class="text-xl font-bold flex items-center gap-2">
                            {{ $module['name'] }}
                            <span class="text-xs bg-primary-100 dark:bg-primary-800 text-primary-700 dark:text-primary-300 px-2 py-0.5 rounded-full font-mono">
                                v{{ $module['version'] ?? '1.0.0' }}
                            </span>
                        </p>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $module['description'] ?? 'بدون توضیحات' }}
                        </p>
                    </div>


                    <div class="flex items-center justify-end gap-3">
                        @if ($module['isEnabled'])
                            <x-filament::badge color="success">فعال</x-filament::badge>
                            <x-filament::button color="warning" size="sm"
                                                wire:click="disableModule('{{ $module['name'] }}')" icon="heroicon-o-x-circle"
                                                tooltip="غیرفعال‌سازی افزونه">
                                غیرفعال
                            </x-filament::button>
                        @else
                            <x-filament::badge color="gray">غیرفعال</x-filament::badge>
                            <x-filament::button color="success" size="sm"
                                                wire:click="enableModule('{{ $module['name'] }}')" icon="heroicon-o-check-circle"
                                                tooltip="فعال‌سازی افزونه">
                                فعال‌سازی
                            </x-filament::button>
                        @endif

                        <x-filament::button color="danger" size="sm"
                                            wire:click="deleteModule('{{ $module['name'] }}')"
                                            wire:confirm="آیا از حذف کامل افزونه {{ $module['name'] }} اطمینان دارید؟ این عمل غیرقابل بازگشت است."
                                            icon="heroicon-o-trash"
                                            tooltip="حذف افزونه">
                            حذف
                        </x-filament::button>
                    </div>
                </div>
            @empty
                <div class="p-10 text-center">
                    <x-heroicon-o-puzzle-piece class="mx-auto w-12 h-12 text-gray-400 animate-pulse"/>
                    <p class="mt-3 text-gray-500">هیچ افزونه‌ای نصب نشده است.</p>
                </div>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
