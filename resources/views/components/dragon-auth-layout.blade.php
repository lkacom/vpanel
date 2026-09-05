<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel+Decorative:wght@400;700;900&family=Vazirmatn:wght@400;700;900&display=swap" rel="stylesheet">


    <link rel="stylesheet" href="{{ asset('themes/dragon/css/style.css') }}">
    <link rel="stylesheet" href="{{ asset('themes/dragon/css/auth.css') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="dragon-auth-body">
<div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0">
    {{ $slot }}
</div>
</body>
</html>
