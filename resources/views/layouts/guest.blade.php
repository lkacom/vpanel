<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name', 'VPanel') }}</title>

    @php
        // لوگوی سفارشی از DB — مستقیم از storage/app/public خوانده می‌شود
        $storedLogo = \App\Models\Setting::where('key', 'site_logo')->value('value');
        if ($storedLogo) {
            $absPath = storage_path('app/public/' . $storedLogo);
            if (file_exists($absPath)) {
                // کپی به public/uploads/logos برای دسترسی مستقیم از web
                $pubDir  = public_path('uploads/logos');
                if (!is_dir($pubDir)) mkdir($pubDir, 0755, true);
                $destFile = $pubDir . '/' . basename($storedLogo);
                if (!file_exists($destFile) || filemtime($absPath) > filemtime($destFile)) {
                    copy($absPath, $destFile);
                }
                $logoUrl = asset('uploads/logos/' . basename($storedLogo));
            } else {
                $logoUrl = asset('images/logo.png');
            }
        } else {
            $logoUrl = asset('images/logo.png');
        }
    @endphp

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700;900&display=swap');

        :root {
            --neon-cyan:    #00f6ff;
            --neon-magenta: #ff00c1;
            --bg-dark:      #0a0a1a;
            --bg-card:      rgba(15, 15, 35, 0.85);
            --text-light:   #e0e0ff;
            --border-color: rgba(0, 246, 255, 0.25);
            --danger:       #ff4d6d;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Vazirmatn', sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-light);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            /* پس‌زمینه سایبرپانک */
            background-image:
                radial-gradient(ellipse at 20% 50%, rgba(0,246,255,.07) 0%, transparent 60%),
                radial-gradient(ellipse at 80% 20%, rgba(255,0,193,.07) 0%, transparent 60%);
        }

        /* ── کارت اصلی ── */
        .auth-card {
            width: 100%;
            max-width: 26rem;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 2rem 2rem 2.25rem;
            clip-path: polygon(0 0, 100% 0, 100% calc(100% - 18px), calc(100% - 18px) 100%, 0 100%);
            backdrop-filter: blur(12px);
        }

        /* ── هدر ── */
        .auth-header {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .6rem;
            margin-bottom: 1.75rem;
            text-align: center;
        }
        .auth-logo {
            max-height: 60px;
            max-width: 160px;
            object-fit: contain;
        }
        .auth-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--neon-cyan);
            text-shadow: 0 0 10px rgba(0,246,255,.5);
            letter-spacing: .03em;
        }

        /* ── تب‌ها ── */
        .auth-tabs {
            display: flex;
            gap: 2px;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .auth-tab {
            flex: 1;
            text-align: center;
            padding: .45rem .5rem;
            font-size: .875rem;
            font-weight: 600;
            color: rgba(224,224,255,.5);
            text-decoration: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -1px;
            transition: color 150ms, border-color 150ms;
        }
        .auth-tab:hover { color: var(--text-light); }
        .auth-tab.active {
            color: var(--neon-cyan);
            border-bottom-color: var(--neon-cyan);
            text-shadow: 0 0 8px rgba(0,246,255,.4);
        }

        /* ── فیلدها ── */
        .field-group { display: grid; gap: .45rem; margin-bottom: 1.1rem; }

        .field-label {
            font-size: .8rem;
            font-weight: 600;
            color: rgba(224,224,255,.75);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .field-label a {
            font-size: .75rem;
            color: var(--neon-cyan);
            text-decoration: none;
            opacity: .8;
        }
        .field-label a:hover { opacity: 1; }

        .input-wrap {
            display: flex;
            border: 1px solid var(--border-color);
            background: rgba(0,0,0,.3);
            transition: border-color 150ms, box-shadow 150ms;
        }
        .input-wrap:focus-within {
            border-color: var(--neon-cyan);
            box-shadow: 0 0 8px rgba(0,246,255,.2);
        }
        .input-wrap.has-error { border-color: var(--danger); }

        .input-wrap input {
            display: block;
            width: 100%;
            border: none;
            outline: none;
            background: transparent;
            padding: .5rem .75rem;
            font-size: .875rem;
            color: var(--text-light);
            font-family: inherit;
        }
        .input-wrap input::placeholder { color: rgba(224,224,255,.3); }

        .field-error { font-size: .78rem; color: var(--danger); }

        /* ── session status ── */
        .session-status {
            margin-bottom: 1rem;
            padding: .65rem .9rem;
            font-size: .82rem;
            color: #4ade80;
            border: 1px solid rgba(74,222,128,.3);
            background: rgba(74,222,128,.05);
        }
        .helper-text {
            margin-bottom: 1.1rem;
            font-size: .82rem;
            color: rgba(224,224,255,.55);
            line-height: 1.6;
        }

        /* ── Checkbox ── */
        .remember-row { display: flex; align-items: center; gap: .6rem; margin-bottom: 1.25rem; }
        .remember-row input { accent-color: var(--neon-cyan); cursor: pointer; }
        .remember-row label { font-size: .82rem; color: rgba(224,224,255,.7); cursor: pointer; }

        /* ── دکمه ── */
        .btn-cyber {
            display: block;
            width: 100%;
            padding: .6rem 1rem;
            font-family: inherit;
            font-size: .875rem;
            font-weight: 700;
            color: var(--neon-cyan);
            background: transparent;
            border: 2px solid var(--neon-cyan);
            cursor: pointer;
            transition: all 200ms;
            text-align: center;
            box-shadow: 0 0 8px rgba(0,246,255,.2), inset 0 0 8px rgba(0,246,255,.05);
        }
        .btn-cyber:hover {
            background: var(--neon-cyan);
            color: var(--bg-dark);
            box-shadow: 0 0 20px rgba(0,246,255,.5);
        }
    </style>
</head>

<body>
    <div class="auth-card">

        {{-- هدر --}}
        <div class="auth-header">
            <a href="{{ url('/') }}">
                <img src="{{ $logoUrl }}"
                     alt="{{ config('app.name') }}"
                     class="auth-logo"
                     onerror="this.src='{{ asset('images/logo.png') }}'" />
            </a>
            <div class="auth-title">{{ config('app.name', 'VPanel') }}</div>
        </div>

        {{-- تب‌های ورود / ثبت‌نام (فقط در صفحات login/register) --}}
        @if(request()->routeIs('login') || request()->routeIs('register'))
        <div class="auth-tabs">
            <a href="{{ route('login') }}"    class="auth-tab {{ request()->routeIs('login')    ? 'active':'' }}">ورود</a>
            <a href="{{ route('register') }}" class="auth-tab {{ request()->routeIs('register') ? 'active':'' }}">ثبت‌نام</a>
        </div>
        @endif

        {{-- محتوای صفحه --}}
        {{ $slot }}

    </div>
</body>
</html>
