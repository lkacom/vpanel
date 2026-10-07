<script>
    // نشست منقضی‌شده (419) در درخواست‌های Livewire: به‌جای دیالوگ پیش‌فرض، صفحه را دوباره بارگذاری می‌کنیم
    // تا کاربر خودکار به صفحه ورود مدیر هدایت شود.
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('request', ({ fail }) => {
            fail(({ status, preventDefault }) => {
                if (status === 419) {
                    preventDefault();
                    window.location.reload();
                }
            });
        });
    });
</script>
