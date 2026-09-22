<x-guest-layout>
    <x-auth-session-status class="session-status" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        {{-- ایمیل --}}
        <div class="field-group">
            <div class="field-label"><label for="email">ایمیل</label></div>
            <div class="input-wrap {{ $errors->has('email') ? 'has-error':'' }}">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </span>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required autofocus autocomplete="username" placeholder="example@email.com" />
            </div>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        {{-- رمز عبور --}}
        <div class="field-group">
            <div class="field-label">
                <label for="password">رمز عبور</label>
            </div>
            <div class="input-wrap {{ $errors->has('password') ? 'has-error':'' }}">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </span>
                <input id="password" type="password" name="password"
                       required autocomplete="current-password" placeholder="رمز عبور خود را وارد کنید" />
            </div>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        {{-- مرا به خاطر بسپار --}}
        <div class="remember-row">
            <input id="remember_me" type="checkbox" name="remember" />
            <label for="remember_me">مرا به خاطر بسپار</label>
        </div>

        <button type="submit" class="btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
            ورود به حساب
        </button>
    </form>

    @if(Route::has('register'))
        <div class="auth-footer-link">
            حساب کاربری ندارید؟
            <a href="{{ route('register') }}">ثبت‌نام کنید</a>
        </div>
    @endif
</x-guest-layout>
