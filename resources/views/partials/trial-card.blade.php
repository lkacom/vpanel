{{-- برگه «اکانت تست» داشبورد کاربر: دکمه دریافت یا هشدار --}}
{{-- رنگ‌ها inline هستند تا بدون build مجدد Tailwind هم همیشه خوانا باشند --}}
@isset($trial)
    @if($trial['enabled'])
        <div class="p-6 rounded-xl text-right" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#064e3b;">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h3 class="font-bold text-lg" style="color:#064e3b;">🎁 اکانت تست رایگان</h3>
                    <p class="text-sm mt-1" style="color:#065f46;">
                        {{ $trial['volume_label'] }} حجم · {{ $trial['duration_label'] }} اعتبار
                        @if($trial['limit'] > 1)
                            · {{ $trial['remaining'] }} از {{ $trial['limit'] }} دریافت باقی‌مانده
                        @endif
                    </p>
                </div>

                {{-- کاربری که هنوز اکانت تست نگرفته: دکمه دریافت --}}
                @if($trial['can_claim'] && ($trial['available'] ?? true))
                    <form method="POST" action="{{ route('trial.claim') }}"
                          onsubmit="return confirm('اکانت تست رایگان ساخته شود؟')">
                        @csrf
                        <button type="submit"
                                class="w-full sm:w-auto px-5 py-2.5 text-sm font-bold rounded-lg shadow focus:outline-none"
                                style="background:#6ee7b7;color:#064e3b;border:1px solid #34d399;">
                            دریافت اکانت تست رایگان
                        </button>
                    </form>
                @endif
            </div>

            {{-- کاربری که قبلاً دریافت کرده: هشدار به‌جای دکمه --}}
            @if(! $trial['can_claim'])
                <div class="mt-4 p-3 rounded-lg text-sm" role="alert" style="background:#fef3c7;border:1px solid #fcd34d;color:#78350f;">
                    ⚠️ شما مجاز به دریافت اکانت تست نیستید. شما قبلاً اکانت تست رایگان را دریافت کرده‌اید.
                </div>
            @elseif(! ($trial['available'] ?? true))
                <div class="mt-4 p-3 rounded-lg text-sm" role="alert" style="background:#f3f4f6;border:1px solid #d1d5db;color:#1f2937;">
                    در حال حاضر امکان دریافت اکانت تست وجود ندارد. لطفاً بعداً دوباره تلاش کنید.
                </div>
            @endif

            @if(isset($trialAccounts) && $trialAccounts->isNotEmpty())
                <p class="mt-4 text-sm" style="color:#065f46;">
                    اکانت‌های تست دریافت‌شده‌ی شما (لینک و کانفیگ) در برگه «سرویس‌های من» نمایش داده می‌شوند.
                </p>
            @endif
        </div>
    @endif
@endisset
