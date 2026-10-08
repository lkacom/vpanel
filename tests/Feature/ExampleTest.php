<?php

// با قالب اصلی غیرفعال (پیش‌فرض نصب تازه)، مهمان از «/» به صفحه ورود هدایت می‌شود.
it('redirects guests from the home page to the login page by default', function () {
    $this->get('/')->assertRedirect(route('login'));
});

it('serves the login page successfully', function () {
    $this->get('/login')->assertStatus(200);
});
