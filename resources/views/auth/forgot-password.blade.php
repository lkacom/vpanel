<x-guest-layout>
    <p class="helper-text">
        ایمیل خود را وارد کنید تا لینک بازنشانی رمز عبور برای شما ارسال شود.
    </p>

    <x-auth-session-status class="session-status" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="field-group" style="margin-bottom:1.25rem">
            <div class="field-label"><label for="email">ایمیل</label></div>
            <div class="input-wrap {{ $errors->has('email') ? 'has-error':'' }}">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </span>
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required autofocus placeholder="example@email.com" />
            </div>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            ارسال لینک بازنشانی
        </button>
    </form>

    <div class="auth-footer-link">
        <a href="{{ route('login') }}">بازگشت به صفحه ورود</a>
    </div>
</x-guest-layout>
