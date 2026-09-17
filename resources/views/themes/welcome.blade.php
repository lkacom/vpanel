@php
    use App\Models\Setting;

    $storedLogo = Setting::where('key', 'login_logo')->value('value');
    // سازگاری با نصب‌های قبلی که لوگوی ورود را با site_logo ذخیره کرده‌اند.
    if (!$storedLogo) {
        $storedLogo = Setting::where('key', 'site_logo')->value('value');
    }
    // مقدار FileUpload ممکن است بسته به نسخه Filament به‌صورت آرایه ذخیره شود.
    if (is_array($storedLogo)) {
        $storedLogo = array_values($storedLogo)[0] ?? null;
    }

    if ($storedLogo) {
        // ابتدا symlink را چک کن (php artisan storage:link)
        if (file_exists(public_path('storage/' . $storedLogo))) {
            $logoUrl = asset('storage/' . $storedLogo) . '?v=' . filemtime(public_path('storage/' . $storedLogo));
        // بعد مستقیم از storage/app/public چک کن
        } elseif (file_exists(storage_path('app/public/' . $storedLogo))) {
            // fallback: فایل را به public کپی کن (یک‌بار)
            $destDir = public_path('uploads/logos');
            if (!is_dir($destDir)) mkdir($destDir, 0755, true);
            $filename = basename($storedLogo);
            $destPath = $destDir . '/' . $filename;
            // لوگو را هر بار همگام کن تا آپلود جدید با همان نام هم اعمال شود.
            copy(storage_path('app/public/' . $storedLogo), $destPath);
            $logoUrl = asset('uploads/logos/' . $filename) . '?v=' . filemtime($destPath);
        } else {
            $logoUrl = asset('images/logo.png');
        }
    } else {
        $logoUrl = asset('images/logo.png');
    }

    $mode ??= 'login';
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>{{ $mode === 'register' ? 'ثبت‌نام' : 'ورود' }} — {{ config('app.name', 'VPanel') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --primary-400: #60a5fa; --primary-500: #3b82f6; --primary-600: #2563eb;
            --danger-400: #f87171;  --danger-600: #dc2626;
        }
        html { font-family: 'Vazirmatn', ui-sans-serif, system-ui, sans-serif; background-color: #f9fafb; }
        html.dark { background-color: #0f172a; color: #f1f5f9; }
        body { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 1.5rem 1rem; }

        .login-card { width: 100%; max-width: 28rem; background: #fff; border-radius: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.1); padding: 2rem; }
        html.dark .login-card { background: #1e293b; }

        .login-header { display: flex; flex-direction: column; align-items: center; gap: .75rem; margin-bottom: 1.5rem; text-align: center; }
        .login-logo { max-height: 64px; max-width: 180px; width: auto; object-fit: contain; }
        .login-title { font-size: 1.1rem; font-weight: 600; color: #111827; }
        html.dark .login-title { color: #f8fafc; }

        /* تب‌ها */
        .auth-tabs { display: flex; background: #f3f4f6; border-radius: .5rem; padding: 3px; gap: 3px; margin-bottom: 1.5rem; }
        html.dark .auth-tabs { background: #0f172a; }
        .auth-tab { flex: 1; text-align: center; padding: .4rem; font-size: .875rem; font-weight: 500; border-radius: .375rem; color: #6b7280; text-decoration: none; transition: all 100ms; }
        .auth-tab.active { background: #fff; color: #111827; box-shadow: 0 1px 2px rgba(0,0,0,.1); }
        html.dark .auth-tab { color: #94a3b8; }
        html.dark .auth-tab.active { background: #1e293b; color: #f8fafc; }

        /* فیلدها */
        .field-group { display: grid; gap: .5rem; margin-bottom: 1.1rem; }
        .field-label { font-size: .875rem; font-weight: 500; color: #374151; display: flex; justify-content: space-between; align-items: center; }
        html.dark .field-label { color: #e2e8f0; }
        .field-label a { font-size: .8rem; color: var(--primary-600); text-decoration: none; }
        html.dark .field-label a { color: var(--primary-400); }
        .input-wrap { display: flex; border: 1px solid #d1d5db; border-radius: .5rem; background: #fff; transition: border-color 75ms, box-shadow 75ms; }
        html.dark .input-wrap { border-color: rgba(255,255,255,.15); background: rgba(255,255,255,.05); }
        .input-wrap:focus-within { border-color: var(--primary-600); box-shadow: 0 0 0 2px rgba(37,99,235,.2); }
        .input-wrap.has-error { border-color: var(--danger-600); }
        .input-wrap input { display: block; width: 100%; border: none; outline: none; background: transparent; padding: .5rem .75rem; font-size: .875rem; color: #111827; font-family: inherit; }
        html.dark .input-wrap input { color: #f1f5f9; }
        .input-wrap input::placeholder { color: #9ca3af; }
        .field-error { font-size: .8rem; color: var(--danger-600); }
        html.dark .field-error { color: var(--danger-400); }

        .remember-row { display: flex; align-items: center; gap: .6rem; margin-bottom: 1.25rem; }
        .remember-row input { accent-color: var(--primary-600); width: 1rem; height: 1rem; cursor: pointer; }
        .remember-row label { font-size: .875rem; color: #374151; cursor: pointer; }
        html.dark .remember-row label { color: #e2e8f0; }

        .btn-submit { display: block; width: 100%; padding: .55rem 1rem; background: var(--primary-600); color: #fff; font-family: inherit; font-size: .875rem; font-weight: 600; border: none; border-radius: .5rem; cursor: pointer; transition: background 75ms; text-align: center; }
        .btn-submit:hover { background: var(--primary-500); }
        html.dark .btn-submit { background: var(--primary-500); }

        .session-status { margin-bottom: 1rem; padding: .75rem 1rem; border-radius: .5rem; font-size: .875rem; background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
    </style>

    <script>
        (function(){
            var t=localStorage.getItem('theme')||'light';
            if(t==='dark'||(t==='system'&&window.matchMedia('(prefers-color-scheme: dark)').matches))
                document.documentElement.classList.add('dark');
        })();
    </script>
</head>
<body>
    <div class="login-card">

        <div class="login-header">
            <a href="{{ url('/') }}">
                <img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" class="login-logo"
                     onerror="this.src='{{ asset('images/logo.png') }}'" />
            </a>
            <h1 class="login-title">{{ config('app.name', 'VPanel') }}</h1>
        </div>

        <div class="auth-tabs">
            <a href="{{ route('login') }}"    class="auth-tab {{ $mode==='login'    ? 'active':'' }}">ورود</a>
            <a href="{{ route('register') }}" class="auth-tab {{ $mode==='register' ? 'active':'' }}">ثبت‌نام</a>
        </div>

        @if(session('status'))
            <div class="session-status">{{ session('status') }}</div>
        @endif

        @if($mode === 'login')
        {{-- ──── فرم ورود ──── --}}
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <div class="field-group">
                <div class="field-label">
                    <label for="email">ایمیل</label>
                </div>
                <div class="input-wrap {{ $errors->has('email') ? 'has-error':'' }}">
                    <input id="email" type="email" name="email" value="{{ old('email') }}"
                           required autofocus autocomplete="username" placeholder="example@email.com" />
                </div>
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field-group">
                <div class="field-label">
                    <label for="password">رمز عبور</label>
                    @if(Route::has('password.request'))
                        <a href="{{ route('password.request') }}">فراموشی رمز عبور؟</a>
                    @endif
                </div>
                <div class="input-wrap {{ $errors->has('password') ? 'has-error':'' }}">
                    <input id="password" type="password" name="password"
                           required autocomplete="current-password" placeholder="••••••••" />
                </div>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="remember-row">
                <input id="remember_me" type="checkbox" name="remember" />
                <label for="remember_me">مرا به خاطر بسپار</label>
            </div>

            <button type="submit" class="btn-submit">ورود</button>
        </form>

        @else
        {{-- ──── فرم ثبت‌نام ──── --}}
        <form method="POST" action="{{ route('register') }}">
            @csrf
            @if(request()->has('ref'))
                <input type="hidden" name="ref" value="{{ request()->query('ref') }}">
            @endif

            <div class="field-group">
                <div class="field-label"><label for="name">نام</label></div>
                <div class="input-wrap {{ $errors->has('name') ? 'has-error':'' }}">
                    <input id="name" type="text" name="name" value="{{ old('name') }}"
                           required autofocus autocomplete="name" placeholder="نام و نام خانوادگی" />
                </div>
                @error('name')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field-group">
                <div class="field-label"><label for="reg_email">ایمیل</label></div>
                <div class="input-wrap {{ $errors->has('email') ? 'has-error':'' }}">
                    <input id="reg_email" type="email" name="email" value="{{ old('email') }}"
                           required autocomplete="username" placeholder="example@email.com" />
                </div>
                @error('email')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field-group">
                <div class="field-label"><label for="reg_password">رمز عبور</label></div>
                <div class="input-wrap {{ $errors->has('password') ? 'has-error':'' }}">
                    <input id="reg_password" type="password" name="password"
                           required autocomplete="new-password" placeholder="حداقل ۸ کاراکتر" />
                </div>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
            </div>

            <div class="field-group" style="margin-bottom:1.25rem">
                <div class="field-label"><label for="reg_password_confirmation">تکرار رمز عبور</label></div>
                <div class="input-wrap">
                    <input id="reg_password_confirmation" type="password" name="password_confirmation"
                           required autocomplete="new-password" placeholder="••••••••" />
                </div>
            </div>

            <button type="submit" class="btn-submit">ایجاد حساب کاربری</button>
        </form>
        @endif

    </div>
</body>
</html>
