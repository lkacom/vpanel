
@foreach($this->record->replies()->orderBy('created_at')->get() as $reply)
    <div class="p-4 rounded-lg ...">

        <p class="mt-2 whitespace-pre-wrap">{{ $reply->message }}</p>


        @if($reply->attachment_path)
            <div class="mt-3 border-t dark:border-gray-700 pt-2">
                <a href="{{ Storage::disk('public')->url($reply->attachment_path) }}"
                   target="_blank"
                   class="inline-flex items-center text-sm text-blue-600 dark:text-blue-400 hover:underline">
                    <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" ...><path ... /></svg>
                    دانلود فایل ضمیمه
                </a>
            </div>
        @endif
    </div>
@endforeach
