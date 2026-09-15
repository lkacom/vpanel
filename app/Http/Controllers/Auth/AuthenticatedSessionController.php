<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     * اگر قالب اصلی غیرفعال است (welcome)، صفحه ورود کاربر در / نمایش داده می‌شود.
     * برای جلوگیری از نمایش فرم Breeze نیمه‌فارسی، /login را به / ریدایرکت می‌کنیم.
     */
    public function create(): View|RedirectResponse
    {
        $activeTheme = \App\Models\Setting::where('key', 'active_theme')->value('value') ?? 'rocket';

        if ($activeTheme === 'welcome') {
            return redirect('/');
        }

        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
