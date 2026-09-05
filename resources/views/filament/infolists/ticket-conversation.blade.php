<div class="space-y-4">
    @php
        $ticket = $getRecord();
    @endphp

    @foreach($ticket->replies()->orderBy('created_at')->get() as $reply)
        <div @class([
            'p-4 rounded-lg',
            'bg-blue-50 dark:bg-blue-900/50 ml-auto w-11/12' => $reply->user->is_admin,
            'bg-green-50 dark:bg-green-900/50 mr-auto w-11/12' => !$reply->user->is_admin,
        ])>
            <div class="flex justify-between items-center">
                <p class="font-bold text-gray-900 dark:text-gray-100">{{ $reply->user->name }}</p>
                <span class="text-xs text-gray-500">{{ $reply->created_at->diffForHumans() }}</span>
            </div>
            <p class="mt-2 text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $reply->message }}</p>


            @php
                $attachmentPath = $reply->attachment_path;

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

        </div>
    @endforeach
</div>
