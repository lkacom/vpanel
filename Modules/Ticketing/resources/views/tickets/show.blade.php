<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight text-right">
            مشاهده تیکت: {{ $ticket->subject }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm sm:rounded-lg p-6">

                {{-- نمایش مکالمات --}}
                <div class="space-y-4 mb-6">
                    @foreach ($ticket->replies()->orderBy('created_at')->get() as $reply)
                        <div class="p-4 rounded-lg {{ $reply->user->is_admin ? 'bg-blue-50 dark:bg-gray-800/50 ml-8' : 'bg-green-50 dark:bg-green-800/50 mr-8' }} text-right">
                            <p class="font-bold text-gray-900 dark:text-gray-100">
                                {{ $reply->user->name }}
                                <span class="text-xs text-gray-500 font-normal">({{ $reply->created_at->diffForHumans() }})</span>
                            </p>
                            <p class="mt-2 text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $reply->message }}</p>

                            {{-- ====================================================== --}}
                            {{-- ====> کد هوشمند برای نمایش فایل ضمیمه <==== --}}
                            {{-- ====================================================== --}}
                            @php
                                $attachmentPath = $reply->attachment_path;
                                // اگر مسیر یک آرایه JSON بود، اولین عضو آن را استخراج کن
                                if (is_string($attachmentPath) && ($decoded = json_decode($attachmentPath, true)) && is_array($decoded)) {
                                    $attachmentPath = $decoded[0] ?? null;
                                }
                            @endphp

                            @if($attachmentPath)
                                <div class="mt-3 border-t dark:border-gray-700 pt-2">
                                    <a href="{{ Storage::disk('public')->url($attachmentPath) }}"
                                       target="_blank"
                                       class="inline-flex items-center text-sm text-blue-600 dark:text-blue-400 hover:underline">
                                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                        دانلود فایل ضمیمه
                                    </a>
                                </div>
                            @endif
                            {{-- ====================================================== --}}
                        </div>
                    @endforeach
                </div>

                {{-- فرم ارسال پاسخ جدید --}}
                @if ($ticket->status !== 'closed')
                    <div class="border-t dark:border-gray-700 pt-6">
                        <h3 class="font-bold text-lg mb-4 text-gray-900 dark:text-gray-100 text-right">ارسال پاسخ جدید</h3>
                        <form action="{{ route('tickets.reply', $ticket->id) }}" method="POST" enctype="multipart/form-data">
                            @csrf
                            <div>
                                <textarea name="message" id="message" rows="5" class="block mt-1 w-full border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 focus:border-indigo-500 dark:focus:border-indigo-600 focus:ring-indigo-500 dark:focus:ring-indigo-600 rounded-md shadow-sm" required></textarea>
                                <x-input-error :messages="$errors->get('message')" class="mt-2" />
                            </div>
                            <div class="mt-4">
                                <x-input-label for="attachment" value="فایل ضمیمه (اختیاری)" />
                                <input id="attachment" name="attachment" type="file" class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 dark:text-gray-400 focus:outline-none dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400">
                                <x-input-error :messages="$errors->get('attachment')" class="mt-2" />
                            </div>
                            <div class="flex items-center justify-end mt-4">
                                <x-primary-button>
                                    ارسال پاسخ
                                </x-primary-button>
                            </div>
                        </form>
                    </div>
                @else
                    <div class="text-center p-4 bg-gray-100 dark:bg-gray-700 rounded-lg">
                        <p class="text-gray-600 dark:text-gray-300">این تیکت توسط ادمین بسته شده است.</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
