@extends('layouts.frontend')

@section('title', 'پنل مدیریت و فروش - VPanel')

@push('styles')
    <link rel="stylesheet" href="{{ asset('themes/welcome/css/style.css') }}">
@endpush

@section('content')
    <div class="welcome-box" data-aos="fade-up" style="text-align: center; margin-top: 100px;">
        <!-- لوگو -->
        <img src="{{ asset('images/logo.png') }}" alt="VPanel Logo" style="max-width: 200px; margin-bottom: 20px;">

        <!-- عنوان -->
        <h1>پنل مدیریت و فروش</h1>

        <!-- دکمه ورود -->
        <a href="/admin" class="btn-admin-panel" style="margin-top: 30px; display: inline-block; padding: 12px 30px; font-size: 18px;">
            ورود به پنل کاربری
        </a>
    </div>
@endsection

@push('scripts')
    <script>
        AOS.init({
            duration: 800,
            once: true,
        });
    </script>
@endpush
