<x-filament-panels::page>
    <form wire:submit="saveConnection" class="space-y-6">
        {{ $this->connectionForm }}

        <x-filament::button type="submit" color="primary">
            ذخیره تنظیمات اتصال و Sync با سرور
        </x-filament::button>
    </form>

    <form wire:submit="saveInbound" class="mt-8 space-y-6">
        {{ $this->inboundForm }}

        <x-filament::button type="submit" color="success">
            ذخیره Inbound
        </x-filament::button>
    </form>
</x-filament-panels::page>
