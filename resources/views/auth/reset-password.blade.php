<x-guest-layout>
    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="field-group">
            <div class="field-label"><label for="email">ایمیل</label></div>
            <div class="input-wrap {{ $errors->has('email') ? 'has-error':'' }}">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </span>
                <input id="email" type="email" name="email"
                       value="{{ old('email', $request->email) }}"
                       required autofocus autocomplete="username" placeholder="example@email.com" />
            </div>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field-group">
            <div class="field-label"><label for="password">رمز عبور جدید</label></div>
            <div class="input-wrap {{ $errors->has('password') ? 'has-error':'' }}">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                </span>
                <input id="password" type="password" name="password"
                       required autocomplete="new-password" placeholder="حداقل ۸ کاراکتر" />
            </div>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field-group" style="margin-bottom:1.25rem">
            <div class="field-label"><label for="password_confirmation">تکرار رمز عبور جدید</label></div>
            <div class="input-wrap">
                <span class="input-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                </span>
                <input id="password_confirmation" type="password" name="password_confirmation"
                       required autocomplete="new-password" placeholder="رمز عبور را تکرار کنید" />
            </div>
        </div>

        <button type="submit" class="btn-primary">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            ذخیره رمز عبور جدید
        </button>
    </form>
</x-guest-layout>
