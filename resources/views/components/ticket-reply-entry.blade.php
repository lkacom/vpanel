@php
    $reply = $getRecord();
    $user = $reply->user;
    $isAdmin = $user->is_admin;
@endphp

<div @class([
    'p-4 rounded-lg',
    'bg-blue-50 dark:bg-blue-900/50 text-right' => $isAdmin,
    'bg-green-50 dark:bg-green-900/50 text-left' => !$isAdmin,
])>
    <p class="font-bold text-gray-900 dark:text-gray-100">
        {{ $user->name }}
        <span class="text-xs text-gray-500 font-normal">({{ $reply->created_at->diffForHumans() }})</span>
    </p>
    <p class="mt-2 text-gray-700 dark:text-gray-300 whitespace-pre-wrap">{{ $reply->message }}</p>
</div>
