<x-guest-layout>
    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="field-group">
            <div class="field-label"><label for="email">ایمیل</label></div>
            <div class="input-wrap {{ $errors->has('email') ? 'has-error':'' }}">
                <input id="email" type="email" name="email"
                       value="{{ old('email', $request->email) }}"
                       required autofocus autocomplete="username" placeholder="example@email.com" />
            </div>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field-group">
            <div class="field-label"><label for="password">رمز عبور جدید</label></div>
            <div class="input-wrap {{ $errors->has('password') ? 'has-error':'' }}">
                <input id="password" type="password" name="password"
                       required autocomplete="new-password" placeholder="حداقل ۸ کاراکتر" />
            </div>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="field-group" style="margin-bottom:1.25rem">
            <div class="field-label"><label for="password_confirmation">تکرار رمز عبور جدید</label></div>
            <div class="input-wrap">
                <input id="password_confirmation" type="password" name="password_confirmation"
                       required autocomplete="new-password" placeholder="••••••••" />
            </div>
        </div>

        <button type="submit" class="btn-cyber">بازنشانی رمز عبور</button>
    </form>
</x-guest-layout>
