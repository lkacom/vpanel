<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>صفحه یافت نشد - 404</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700;900&display=swap');
        body {
            font-family: 'Vazirmatn', sans-serif;
            overflow: hidden;
        }
        .glitch {
            font-size: 8rem;
            font-weight: 900;
            position: relative;
            color: #fff;
            text-shadow:
                0.05em 0 0 rgba(255, 0, 0, 0.75),
                -0.025em -0.05em 0 rgba(0, 255, 0, 0.75),
                0.025em 0.05em 0 rgba(0, 0, 255, 0.75);
            animation: glitch 500ms infinite;
        }
        .glitch span { display: block; position: absolute; top: 0; left: 0; right: 0; bottom: 0; }
        .glitch span:before, .glitch span:after {
            content: "404";
            position: absolute;
            left: 0;
            background: #1a1a2e;
            overflow: hidden;
        }
        .glitch span:before {
            left: 2px;
            text-shadow: -2px 0 #ff00c1;
            animation: glitch-anim-1 2s infinite linear alternate-reverse;
        }
        .glitch span:after {
            left: -2px;
            text-shadow: -2px 0 #00fff9, 2px 2px #ff00c1;
            animation: glitch-anim-2 3s infinite linear alternate-reverse;
        }
        @keyframes glitch { 0%, 100% { transform: translate(0); } 20% { transform: translate(-5px, 5px); } 40% { transform: translate(-5px, -5px); } 60% { transform: translate(5px, 5px); } 80% { transform: translate(5px, -5px); } }
        @keyframes glitch-anim-1 { 0% { clip-path: inset(40% 0 40% 0); } 100% { clip-path: inset(20% 0 50% 0); } }
        @keyframes glitch-anim-2 { 0% { clip-path: inset(15% 0 60% 0); } 100% { clip-path: inset(55% 0 20% 0); } }
    </style>
</head>
<body class="bg-[#1a1a2e] text-white flex items-center justify-center min-h-screen">
<div class="text-center">
    <div class="glitch" data-text="404">
        404
        <span></span>
    </div>
    <h1 class="text-2xl md:text-3xl font-bold mt-8 text-gray-300">اتصال با این صفحه برقرار نشد!</h1>
    <p class="mt-4 text-gray-400">به نظر می‌رسد در فضای بیکران دیجیتال گم شده‌اید. آدرسی که به دنبال آن بودید، وجود ندارد.</p>
    <div class="mt-10">
        <a href="{{ route('home') }}" class="px-8 py-3 bg-indigo-600 text-white font-semibold rounded-lg shadow-lg hover:bg-indigo-700 transition-transform transform hover:scale-105 duration-300">
            بازگشت به پایگاه اصلی
        </a>
    </div>
</div>
</body>
</html>
