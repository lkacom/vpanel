{{-- اکانت‌های تست دریافت‌شده (برگه «سرویس‌های من») --}}
@if(isset($trialAccounts) && $trialAccounts->isNotEmpty())
    <div class="mb-6 space-y-4">
        @foreach($trialAccounts as $account)
            <div class="p-5 rounded-xl bg-gray-50 dark:bg-gray-800/50 shadow-md text-right" x-data="{ open: false }">
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4 items-center">
                    <div>
                        <span class="text-xs text-gray-500">پلن</span>
                        <p class="font-bold text-gray-900 dark:text-white">🎁 اکانت تست #{{ $loop->remaining + 1 }}</p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500">حجم</span>
                        <p class="font-bold text-gray-900 dark:text-white">
                            @if($account->volume_gb < 1)
                                {{ (int) round($account->volume_gb * 1024) }} MB
                            @else
                                {{ rtrim(rtrim(number_format($account->volume_gb, 2, '.', ''), '0'), '.') }} GB
                            @endif
                        </p>
                    </div>
                    <div>
                        <span class="text-xs text-gray-500">وضعیت</span>
                        @if($account->is_active)
                            <p class="font-semibold text-green-500">فعال</p>
                        @else
                            <p class="font-semibold text-red-500">منقضی شده</p>
                        @endif
                    </div>
                    <div>
                        <span class="text-xs text-gray-500">تاریخ انقضا</span>
                        <p class="font-mono text-gray-900 dark:text-white" dir="ltr">{{ \App\Support\PersianDate::format($account->expires_at, 'Y/m/d H:i') }}</p>
                    </div>
                    <div class="text-left sm:text-right md:text-left">
                        <button @click="open = !open" type="button" class="w-full sm:w-auto px-3 py-2 bg-gray-700 text-white text-xs rounded-lg hover:bg-gray-600 focus:outline-none">
                            <span x-show="!open">کانفیگ</span>
                            <span x-show="open" x-cloak>بستن</span>
                        </button>
                    </div>
                </div>

                <div x-show="open" x-cloak x-transition class="mt-4 pt-4 border-t dark:border-gray-700 space-y-2">
                    <h4 class="font-bold mb-2 text-gray-900 dark:text-white text-right">اطلاعات سرویس:</h4>
                    @foreach($account->config_items as $idx => $item)
                        <div class="p-3 bg-gray-100 dark:bg-gray-900 rounded-lg" x-data="{ copied: false }">
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-xs font-bold text-gray-500">
                                    @if(count($account->config_items) > 1) سرور {{ $idx + 1 }} @else کانفیگ @endif
                                </span>
                                <div class="flex gap-1">
                                    <button type="button"
                                            @click="navigator.clipboard.writeText(@js($item)); copied = true; setTimeout(() => copied = false, 2000)"
                                            class="px-2 py-0.5 text-xs bg-gray-300 dark:bg-gray-700 rounded hover:bg-gray-400 flex items-center gap-1">
                                        <span x-show="!copied">📋 کپی</span>
                                        <span x-show="copied" x-cloak class="text-green-600 font-bold">✓ کپی شد</span>
                                    </button>
                                    <button type="button"
                                            @click="$store.qrModal.open(@js($item), 'اکانت تست')"
                                            class="px-2 py-0.5 text-xs bg-blue-500 text-white rounded hover:bg-blue-600">📱 QR</button>
                                </div>
                            </div>
                            <pre class="text-left text-xs text-gray-800 dark:text-gray-300 overflow-x-auto break-all" dir="ltr">{{ $item }}</pre>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
