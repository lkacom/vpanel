@php
    use App\Models\Setting;
    use Illuminate\Support\Facades\Storage;
    $settings  = Setting::all()->pluck('value', 'key');
    $storedLogo = $settings->get('site_logo');   // مسیر نسبی در disk public، مثلاً logos/abc.png
    $logoUrl    = $storedLogo
                    ? Storage::disk('public')->url($storedLogo)
                    : asset('images/logo.png');
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>ورود — {{ config('app.name', 'VPanel') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary-400: #60a5fa;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --danger-400:  #f87171;
            --danger-600:  #dc2626;
        }

        html {
            font-family: 'Vazirmatn', ui-sans-serif, system-ui, sans-serif;
            background-color: #f9fafb;
            color: #111827;
        }
        html.dark {
            background-color: #0f172a;
            color: #f1f5f9;
        }

        body {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
        }

        /* ── کارت اصلی ── */
        .login-card {
            width: 100%;
            max-width: 28rem;
            background: #ffffff;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06);
            padding: 2rem;
        }
        html.dark .login-card {
            background: #1e293b;
            box-shadow: 0 1px 3px rgba(0,0,0,.4);
        }

        /* ── هدر / لوگو ── */
        .login-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .75rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        .login-logo {
            max-height: 64px;
            width: auto;
            object-fit: contain;
        }
        .login-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: #111827;
            letter-spacing: -.025em;
        }
        html.dark .login-title { color: #f8fafc; }

        /* ── فیلدها ── */
        .field-group { display: grid; gap: .5rem; margin-bottom: 1.25rem; }

        .field-label {
            font-size: .875rem;
            font-weight: 500;
            color: #374151;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        html.dark .field-label { color: #e2e8f0; }

        .field-label a {
            font-size: .875rem;
            font-weight: 500;
            color: var(--primary-600);
            text-decoration: none;
        }
        .field-label a:hover { color: var(--primary-500); }
        html.dark .field-label a { color: var(--primary-400); }

        .input-wrap {
            display: flex;
            border-radius: .5rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.05);
            border: 1px solid #d1d5db;
            background: #ffffff;
            transition: border-color 75ms ease, box-shadow 75ms ease;
        }
        html.dark .input-wrap {
            border-color: rgba(255,255,255,.15);
            background: rgba(255,255,255,.05);
        }
        .input-wrap:focus-within {
            border-color: var(--primary-600);
            box-shadow: 0 0 0 2px rgba(37,99,235,.25);
        }
        .input-wrap.has-error { border-color: var(--danger-600); }

        .input-wrap input {
            display: block;
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            padding: .5rem .75rem;
            font-size: .875rem;
            color: #111827;
            font-family: inherit;
        }
        html.dark .input-wrap input { color: #f1f5f9; }
        .input-wrap input::placeholder { color: #9ca3af; }
        html.dark .input-wrap input::placeholder { color: #64748b; }

        .field-error { font-size: .875rem; color: var(--danger-600); }
        html.dark .field-error { color: var(--danger-400); }

        /* ── Checkbox ── */
        .remember-row {
            display: flex;
            align-items: center;
            gap: .6rem;
            margin-bottom: 1.5rem;
        }
        .remember-row input[type="checkbox"] {
            width: 1rem; height: 1rem;
            border-radius: .25rem;
            border: 1px solid #d1d5db;
            accent-color: var(--primary-600);
            cursor: pointer;
        }
        .remember-row label {
            font-size: .875rem;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
        }
        html.dark .remember-row label { color: #e2e8f0; }

        /* ── دکمه ورود ── */
        .btn-login {
            display: block;
            width: 100%;
            padding: .55rem 1rem;
            background: var(--primary-600);
            color: #ffffff;
            font-family: inherit;
            font-size: .875rem;
            font-weight: 600;
            border: none;
            border-radius: .5rem;
            box-shadow: 0 1px 2px rgba(0,0,0,.1);
            cursor: pointer;
            transition: background 75ms ease;
            text-align: center;
        }
        .btn-login:hover { background: var(--primary-500); }
        html.dark .btn-login { background: var(--primary-500); }
        html.dark .btn-login:hover { background: var(--primary-400); }

        /* ── لینک ثبت‌نام ── */
        .register-row {
            margin-top: 1.25rem;
            text-align: center;
            font-size: .875rem;
            color: #6b7280;
        }
        html.dark .register-row { color: #94a3b8; }
        .register-row a {
            font-weight: 500;
            color: var(--primary-600);
            text-decoration: none;
        }
        .register-row a:hover { color: var(--primary-500); }
        html.dark .register-row a { color: var(--primary-400); }

        /* ── وضعیت session ── */
        .session-status {
            margin-bottom: 1rem;
            padding: .75rem 1rem;
            border-radius: .5rem;
            font-size: .875rem;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        html.dark .session-status {
            background: #052e16;
            color: #4ade80;
            border-color: #14532d;
        }
    </style>

    <script>
        (function () {
            var t = localStorage.getItem('theme') ?? 'light';
            if (t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            }
        })();
    </script>
</head>

<body>
    <div class="login-card">

        {{-- هدر --}}
        <div class="login-header">
            <a href="{{ url('/') }}">
                <img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" class="login-logo" />
            </a>
            <h1 class="login-title">ورود به حساب کاربری</h1>
        </div>

        {{-- Session Status --}}
        @if (session('status'))
            <div class="session-status">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            {{-- Email --}}
            <div class="field-group">
                <div class="field-label">
                    <label for="email">ایمیل</label>
                </div>
                <div class="input-wrap {{ $errors->has('email') ? 'has-error' : '' }}">
                    <input id="email" type="email" name="email"
                           value="{{ old('email') }}"
                           required autofocus autocomplete="username"
                           placeholder="example@email.com" />
                </div>
                @error('email')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Password --}}
            <div class="field-group">
                <div class="field-label">
                    <label for="password">رمز عبور</label>
                    @if (Route::has('password.request'))
                        <a href="{{ route('password.request') }}">فراموشی رمز عبور؟</a>
                    @endif
                </div>
                <div class="input-wrap {{ $errors->has('password') ? 'has-error' : '' }}">
                    <input id="password" type="password" name="password"
                           required autocomplete="current-password"
                           placeholder="••••••••" />
                </div>
                @error('password')
                    <p class="field-error">{{ $message }}</p>
                @enderror
            </div>

            {{-- Remember Me --}}
            <div class="remember-row">
                <input id="remember_me" type="checkbox" name="remember" />
                <label for="remember_me">مرا به خاطر بسپار</label>
            </div>

            {{-- Submit --}}
            <button type="submit" class="btn-login">ورود</button>

            {{-- Register Link --}}
            @if (Route::has('register'))
                <div class="register-row">
                    حساب کاربری ندارید؟
                    <a href="{{ route('register') }}">ثبت‌نام کنید</a>
                </div>
            @endif
        </form>
    </div>
</body>
</html>
