@php

    $activeAuthTheme = $settings->get('active_auth_theme', 'default');
@endphp

    <!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>


    @if($activeAuthTheme === 'default')
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <link rel="stylesheet" href="{{ asset('themes/auth/' . $activeAuthTheme . '/css/style.css') }}">
    @endif

</head>


<body class="@switch($activeAuthTheme)
    @case('cyberpunk')
        auth-container
        @break
    @case('dragon')
        dragon-auth-body
        @break
    @default
        font-sans text-gray-900 antialiased
@endswitch">

@switch($activeAuthTheme)
    @case('cyberpunk')

        {{ $slot }}
        @break

    @case('dragon')

        <div class="embers-container">
            @for ($i = 0; $i < 20; $i++)
                <div class="ember"></div>
            @endfor
        </div>


        {{ $slot }}
        @break

    @default

        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gray-100">
            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-white shadow-md overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
@endswitch

</body>
</html>
