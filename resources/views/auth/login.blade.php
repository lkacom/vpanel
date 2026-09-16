<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'VPanel') }}</title>

    @php
        $storedLogo = \App\Models\Setting::where('key', 'site_logo')->value('value');
        if ($storedLogo) {
            $src = storage_path('app/public/' . $storedLogo);
            if (file_exists($src)) {
                $pubDir = public_path('uploads/logos');
                if (!is_dir($pubDir)) mkdir($pubDir, 0755, true);
                $dest = $pubDir . '/' . basename($storedLogo);
                if (!file_exists($dest) || filemtime($src) > filemtime($dest)) {
                    copy($src, $dest);
                }
                $logoUrl = asset('uploads/logos/' . basename($storedLogo));
            } else {
                $logoUrl = null;
            }
        } else {
            $logoUrl = null;
        }
    @endphp

    <link rel="stylesheet" href="{{ asset('themes/auth/cyberpunk/css/style.css') }}">
</head>
<body>
    <div class="auth-container">
        <div class="auth-card">

            {{-- لوگو --}}
            <div class="auth-logo">
                @if($logoUrl)
                    <img src="{{ $logoUrl }}"
                         alt="{{ config('app.name') }}"
                         style="max-height:70px; max-width:180px; object-fit:contain;"
                         onerror="this.style.display='none'; document.getElementById('logo-text').style.display='block'">
                    <span id="logo-text" style="display:none">{{ config('app.name') }}</span>
                @else
                    <img src="{{ asset('images/logo.png') }}"
                         alt="{{ config('app.name') }}"
                         style="max-height:70px; max-width:180px; object-fit:contain;"
                         onerror="this.style.display='none'; document.getElementById('logo-text2').style.display='block'">
                    <span id="logo-text2" style="display:none">{{ config('app.name') }}</span>
                @endif
            </div>

            {{-- Session Status --}}
            @if(session('status'))
                <div style="margin-bottom:1rem; padding:.6rem 1rem; color:#4ade80; border:1px solid rgba(74,222,128,.3); border-radius:6px; font-size:.85rem;">
                    {{ session('status') }}
                </div>
            @endif

            {{-- فرم ورود --}}
            <div class="auth-title">{{ __('ورود به حساب کاربری') }}</div>

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="input-group">
                    <input id="email" class="input-field" type="email" name="email"
                           value="{{ old('email') }}" required autofocus
                           placeholder="{{ __('ایمیل') }}" />
                    @error('email')
                        <div class="input-error-message">{{ $message }}</div>
                    @enderror
                </div>

                <div class="input-group">
                    <input id="password" class="input-field" type="password" name="password"
                           required placeholder="{{ __('رمز عبور') }}" />
                    @error('password')
                        <div class="input-error-message">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-row">
                    <label class="remember-me">
                        <input type="checkbox" name="remember">
                        <span>{{ __('مرا به خاطر بسپار') }}</span>
                    </label>
                    @if(Route::has('password.request'))
                        <a href="{{ route('password.request') }}" class="auth-link">
                            {{ __('فراموشی رمز؟') }}
                        </a>
                    @endif
                </div>

                <button type="submit" class="btn-submit">{{ __('ورود') }}</button>
            </form>

            @if(Route::has('register'))
                <hr class="separator">
                <div class="register-link">
                    {{ __('حساب کاربری ندارید؟') }}
                    <a href="{{ route('register') }}" class="auth-link">{{ __('ثبت‌نام کنید') }}</a>
                </div>
            @endif

        </div>
    </div>
</body>
</html>
