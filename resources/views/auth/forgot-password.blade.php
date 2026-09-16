<x-guest-layout>
    <p class="helper-text">
        رمز عبور خود را فراموش کرده‌اید؟ نگران نباشید — ایمیل خود را وارد کنید تا لینک بازنشانی رمز برای شما ارسال شود.
    </p>

    <x-auth-session-status class="session-status" :status="session('status')" />

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="field-group" style="margin-bottom:1.25rem">
            <div class="field-label"><label for="email">ایمیل</label></div>
            <div class="input-wrap {{ $errors->has('email') ? 'has-error':'' }}">
                <input id="email" type="email" name="email" value="{{ old('email') }}"
                       required autofocus placeholder="example@email.com" />
            </div>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn-cyber">ارسال لینک بازنشانی</button>
    </form>
</x-guest-layout>
