<?php

namespace App\Http\Controllers;

use App\Exceptions\TrialUnavailableException;
use App\Services\TrialAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class TrialController extends Controller
{
    public function store(TrialAccountService $service): RedirectResponse
    {
        try {
            $service->claim(Auth::user());
        } catch (TrialUnavailableException $e) {
            return redirect()->route('dashboard')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard')
            ->with('status', 'اکانت تست رایگان شما با موفقیت ساخته شد. لینک اتصال را از بخش «سرویس‌های من» کپی کنید.');
    }
}
