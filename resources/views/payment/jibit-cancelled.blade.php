<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>لغو پرداخت</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100 dark:bg-gray-900 flex items-center justify-center p-4">
    <main class="w-full max-w-lg bg-white dark:bg-gray-800 shadow-sm rounded-lg p-6 text-right">
        <div class="rounded-lg bg-red-100 border border-red-300 text-red-800 dark:bg-red-900/30 dark:border-red-700 dark:text-red-200 p-4 mb-6">
            {{ $error ?? 'پرداخت لغو شد یا ناموفق بود.' }}
        </div>

        <dl class="space-y-3 text-gray-700 dark:text-gray-200">
            <div class="flex justify-between gap-4">
                <dt>مبلغ</dt>
                <dd class="font-semibold">{{ number_format($amount ?? 0) }} تومان</dd>
            </div>
            @if ($order)
                <div class="flex justify-between gap-4">
                    <dt>شماره سفارش</dt>
                    <dd class="font-semibold">#{{ $order->id }}</dd>
                </div>
            @endif
        </dl>

        <div class="mt-8 flex flex-wrap gap-3">
            <a href="{{ route('home') }}" class="inline-block rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white px-5 py-2">
                بازگشت به سایت
            </a>
            @if (auth()->check())
                <a href="{{ route('dashboard') }}" class="inline-block rounded-lg border border-gray-300 dark:border-gray-600 px-5 py-2 text-gray-700 dark:text-gray-200">
                    داشبورد کاربری
                </a>
            @endif
        </div>
    </main>
</body>
</html>
