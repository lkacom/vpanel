<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
// RedirectResponse برای logout و store هنوز استفاده می‌شود

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     * اگر قالب اصلی غیرفعال است (welcome)، صفحه ورود کاربر در / نمایش داده می‌شود.
     * برای جلوگیری از نمایش فرم Breeze نیمه‌فارسی، /login را به / ریدایرکت می‌کنیم.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        // بازگشت از درگاه ممکن است کاربر را به host متفاوتی مانند 127.0.0.1
        // برده باشد؛ در این حالت intended می‌تواند به صفحه اصلی یا callback
        // اشاره کند. مقصد قطعی پس از ورود، داشبورد کاربری است.
        return redirect()->route('dashboard');
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
