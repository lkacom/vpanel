<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>۴۰۴ | صفحه پیدا نشد</title>
    <link rel="icon" href="{{ asset('favicon.png') }}">
    <link rel="stylesheet" href="{{ asset('css/font.css') }}">
    <style>
        :root {
            --bg: #f8fafc;
            --card: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --accent: #6366f1;
            --accent-soft: #eef2ff;
            --border: #e2e8f0;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b1120;
                --card: #111827;
                --text: #f1f5f9;
                --muted: #94a3b8;
                --accent: #818cf8;
                --accent-soft: #1e1b4b;
                --border: #1f2937;
            }
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: var(--bg);
            color: var(--text);
            font-family: 'Vaz', Tahoma, 'Segoe UI', sans-serif;
        }
        .card {
            width: 100%;
            max-width: 28rem;
            padding: 2.5rem 2rem;
            text-align: center;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 1.25rem;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .08);
        }
        .code {
            font-size: 6rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: .05em;
            color: var(--accent);
            direction: ltr;
        }
        h1 { margin-top: 1rem; font-size: 1.35rem; font-weight: 700; }
        p { margin-top: .75rem; font-size: .95rem; line-height: 1.9; color: var(--muted); }
        .actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; margin-top: 2rem; }
        .btn {
            padding: .7rem 1.4rem;
            font: inherit;
            font-size: .9rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            border-radius: .75rem;
            border: 1px solid transparent;
            transition: opacity .15s ease;
        }
        .btn:hover { opacity: .85; }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-ghost { background: var(--accent-soft); color: var(--accent); }
    </style>
</head>
<body>
    <main class="card">
        <div class="code">404</div>
        <h1>صفحه مورد نظر پیدا نشد</h1>
        <p>آدرسی که وارد کرده‌اید وجود ندارد یا منتقل شده است. لطفاً آدرس را بررسی کنید یا به صفحه اصلی برگردید.</p>
        <div class="actions">
            <a class="btn btn-primary" href="{{ url('/') }}">صفحه اصلی</a>
            <button class="btn btn-ghost" type="button" onclick="history.length > 1 ? history.back() : (location.href = '{{ url('/') }}')">بازگشت</button>
        </div>
    </main>
</body>
</html>
