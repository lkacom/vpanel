<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);


    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // 419 (Page Expired): به‌جای صفحه خطای پیش‌فرض، کاربر به صفحه ورود مناسب هدایت می‌شود
        // (بخش مدیریت → ورود مدیر، بقیه‌ی سایت → ورود کاربران).
        $exceptions->render(function (\Throwable $e, Request $request) {
            $isPageExpired = $e instanceof TokenMismatchException
                || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 419);

            if (! $isPageExpired) {
                return null;
            }

            // درخواست‌های Livewire/JSON با اسکریپت سمت کلاینت مدیریت می‌شوند (به پنل مدیریت اضافه شده است)
            if ($request->expectsJson() || $request->hasHeader('X-Livewire')) {
                return null;
            }

            $refererPath = (string) parse_url((string) $request->headers->get('referer'), PHP_URL_PATH);
            $isAdminArea = $request->is('admin', 'admin/*') || preg_match('#^/admin(/|$)#', $refererPath) === 1;

            return redirect()
                ->route($isAdminArea ? 'filament.admin.auth.login' : 'login')
                ->with('status', 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.');
        });
    })->create();
