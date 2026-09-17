<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php
        $s         = \App\Models\Setting::all()->pluck('value','key');
        $brandName = $s->get('login_brand_name') ?: config('app.name', 'VPanel');
        $stored    = $s->get('login_logo') ?: $s->get('site_logo');
        if (is_array($stored)) {
            $stored = array_values($stored)[0] ?? null;
        }
        if ($stored) {
            $abs  = storage_path('app/public/' . $stored);
            $dest = public_path('uploads/logos/' . basename($stored));
            if (file_exists($abs)) {
                $dir = public_path('uploads/logos');
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                if (!file_exists($dest) || filemtime($abs) > filemtime($dest)) copy($abs, $dest);
                $logoUrl = asset('uploads/logos/' . basename($stored)) . '?v=' . filemtime($dest);
            } else { $logoUrl = asset('images/logo.png'); }
        } else { $logoUrl = asset('images/logo.png'); }
    @endphp

    <title>{{ $brandName }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --blue-500: #3b82f6;
            --blue-600: #2563eb;
            --blue-700: #1d4ed8;
            --indigo-600: #4f46e5;
            --gray-50:  #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-900: #111827;
            --red-500:  #ef4444;
            --red-600:  #dc2626;
        }

        html, body {
            font-family: 'Vazirmatn', ui-sans-serif, system-ui, sans-serif;
            min-height: 100vh;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            background: #f0f4ff;
            position: relative;
            overflow: hidden;
        }

        /* ── دایره‌های پس‌زمینه ── */
        body::before, body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            pointer-events: none;
        }
        body::before {
            width: 600px; height: 600px;
            top: -200px; right: -150px;
            background: radial-gradient(circle, rgba(99,102,241,.15) 0%, transparent 70%);
        }
        body::after {
            width: 500px; height: 500px;
            bottom: -180px; left: -120px;
            background: radial-gradient(circle, rgba(59,130,246,.12) 0%, transparent 70%);
        }

        /* ── کارت یکپارچه ── */
        .auth-card {
            width: 100%;
            max-width: 28rem;
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 8px 40px rgba(37,99,235,.13), 0 2px 8px rgba(0,0,0,.06);
            padding: 2.5rem;
            position: relative;
            z-index: 1;
        }

        /* ── هدر داخل کارت ── */
        .auth-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .75rem;
            margin-bottom: 2rem;
            text-align: center;
        }

        .auth-logo-ring {
            width: 80px;
            height: 80px;
            border-radius: 1.25rem;
            background: linear-gradient(135deg, #eff6ff 0%, #e0e7ff 100%);
            border: 2px solid #dbeafe;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(37,99,235,.12);
        }
        .auth-logo {
            max-width: 58px;
            max-height: 58px;
            object-fit: contain;
        }

        .auth-brand {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-900);
            letter-spacing: -.02em;
        }

        .auth-subtitle {
            font-size: .8rem;
            color: var(--gray-400);
            font-weight: 500;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        /* ── خط جداکننده ── */
        .auth-divider {
            height: 1px;
            background: var(--gray-100);
            margin-bottom: 1.5rem;
        }

        /* ── فیلدها ── */
        .field-group { display: grid; gap: .4rem; margin-bottom: 1rem; }

        .field-label {
            font-size: .8rem;
            font-weight: 600;
            color: var(--gray-700);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .field-label a {
            font-size: .75rem;
            font-weight: 500;
            color: var(--blue-600);
            text-decoration: none;
        }
        .field-label a:hover { color: var(--blue-700); text-decoration: underline; }

        .input-wrap {
            display: flex;
            align-items: center;
            border: 1.5px solid var(--gray-200);
            border-radius: .625rem;
            background: var(--gray-50);
            transition: border-color 150ms, box-shadow 150ms, background 150ms;
        }
        .input-wrap:focus-within {
            border-color: var(--blue-500);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(59,130,246,.1);
        }
        .input-wrap.has-error {
            border-color: var(--red-500);
            box-shadow: 0 0 0 3px rgba(239,68,68,.08);
        }
        .input-icon {
            padding: 0 .75rem;
            color: var(--gray-400);
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }
        .input-wrap:focus-within .input-icon { color: var(--blue-500); }
        .input-wrap input {
            flex: 1;
            border: none;
            outline: none;
            background: transparent;
            padding: .65rem .75rem .65rem 0;
            font-size: .875rem;
            color: var(--gray-900);
            font-family: inherit;
        }
        .input-wrap input::placeholder { color: var(--gray-400); }

        .field-error { font-size: .75rem; color: var(--red-600); }

        /* ── session / helper ── */
        .session-status {
            margin-bottom: 1rem; padding: .6rem .85rem; font-size: .82rem;
            color: #15803d; border: 1px solid #bbf7d0; background: #f0fdf4;
            border-radius: .5rem; text-align: center;
        }
        .helper-text {
            margin-bottom: 1.1rem; font-size: .82rem;
            color: var(--gray-500); line-height: 1.7; text-align: center;
        }

        /* ── Remember ── */
        .remember-row { display: flex; align-items: center; gap: .5rem; margin-bottom: 1.25rem; }
        .remember-row input { accent-color: var(--blue-600); cursor: pointer; }
        .remember-row label { font-size: .82rem; color: var(--gray-600); cursor: pointer; user-select: none; }

        /* ── دکمه ── */
        .btn-primary {
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            width: 100%; padding: .72rem 1rem;
            background: linear-gradient(135deg, var(--blue-600) 0%, var(--indigo-600) 100%);
            color: #fff; font-family: inherit; font-size: .9rem; font-weight: 700;
            border: none; border-radius: .625rem; cursor: pointer;
            box-shadow: 0 4px 14px rgba(37,99,235,.3);
            transition: opacity 180ms, transform 150ms, box-shadow 180ms;
        }
        .btn-primary:hover { opacity: .92; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(37,99,235,.4); }
        .btn-primary:active { transform: translateY(0); }

        /* ── لینک پایین ── */
        .auth-footer-link {
            margin-top: 1.25rem; text-align: center;
            font-size: .82rem; color: var(--gray-400);
        }
        .auth-footer-link a {
            color: var(--blue-600); text-decoration: none; font-weight: 600; margin-right: .2rem;
        }
        .auth-footer-link a:hover { text-decoration: underline; }

        /* اندازه‌های فرم با پنل ادمین هم‌راستا است و در موبایل بدون اسکرول افقی می‌ماند. */
        @media (max-width: 640px) {
            body { padding: 1rem .75rem; }
            .auth-card {
                max-width: 100%;
                border-radius: .875rem;
                padding: 1.75rem 1.25rem 1.5rem;
            }
            .auth-header { margin-bottom: 1.5rem; }
            .auth-logo-ring { width: 68px; height: 68px; }
            .auth-logo { max-width: 50px; max-height: 50px; }
        }
    </style>
</head>

<body>
    <div class="auth-card">

        <div class="auth-header">
            <div class="auth-logo-ring">
                <img src="{{ $logoUrl }}"
                     alt="{{ $brandName }}"
                     class="auth-logo"
                     onerror="this.src='{{ asset('images/logo.png') }}'" />
            </div>
            <div>
                <div class="auth-brand">{{ $brandName }}</div>
                <div class="auth-subtitle">
                    @if(request()->routeIs('login'))           ورود به حساب
                    @elseif(request()->routeIs('register'))    ایجاد حساب جدید
                    @elseif(request()->routeIs('password.request')) بازیابی رمز عبور
                    @elseif(request()->routeIs('password.reset'))   تعیین رمز جدید
                    @endif
                </div>
            </div>
        </div>

        <div class="auth-divider"></div>

        {{ $slot }}

    </div>
</body>
</html>
