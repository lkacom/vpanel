@extends('layouts.frontend')

@section('title', 'خوش آمدید - VPanel')

@push('styles')
    <link rel="stylesheet" href="{{ asset('themes/welcome/css/style.css') }}">
@endpush

@section('content')
    <div class="welcome-box" data-aos="fade-up">
        <!-- لوگو بالای پیام خوش آمدید -->
        <img src="{{ asset('images/logo.png') }}" alt="Logo" class="welcome-logo" />

        <h1>نصب با موفقیت انجام شد!</h1>
        <p>
            به <span class="brand">VPanel</span> خوش آمدید.
            <br>
            برای شروع و انتخاب قالب اصلی وب‌سایت، لطفاً از طریق دکمه زیر وارد پنل مدیریت شوید.
        </p>
        <a href="/admin" class="btn-admin-panel">
            <i class="fas fa-cogs me-2"></i>
            ورود به پنل مدیریت
        </a>
    </div>
@endsection

@push('styles')
    <style>
        .welcome-logo {
            display: block;
            margin: 0 auto 20px auto; /* مرکز و فاصله از متن */
            max-width: 150px; /* اندازه مناسب لوگو */
            height: auto;
        }
    </style>
@endpush

@push('scripts')
    <script>
        AOS.init({
            duration: 800,
            once: true,
        });
    </script>
@endpush
