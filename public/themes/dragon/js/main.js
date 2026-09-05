// main.js

AOS.init({
    duration: 1000,
    once: true,
});

function positionDragonTitleDecos() {
    const titleWrapper = document.querySelector('.hero-title-wrapper');
    const dragonTitle = document.querySelector('.dragon-title');
    const dragonLeft = document.querySelector('.dragon-title-left');
    const dragonRight = document.querySelector('.dragon-title-right');

    if (!titleWrapper || !dragonTitle || !dragonLeft || !dragonRight) return;

    const titleRect = dragonTitle.getBoundingClientRect();
    const wrapperRect = titleWrapper.getBoundingClientRect();

    // محاسبه فاصله از لبه های تایتل
    const offsetLeft = titleRect.left - wrapperRect.left;
    const offsetRight = wrapperRect.right - titleRect.right;

    // تنظیم موقعیت تصاویر اژدها
    // برای چپ: انتهای تصویر اژدها کمی قبل از شروع تایتل باشد
    dragonLeft.style.left = `${(offsetLeft - dragonLeft.offsetWidth * 0.7) / wrapperRect.width * 100}%`;
    dragonLeft.style.top = `${titleRect.top - wrapperRect.top + (titleRect.height / 2)}px`;

    // برای راست: ابتدای تصویر اژدها کمی بعد از پایان تایتل باشد
    dragonRight.style.right = `${(offsetRight - dragonRight.offsetWidth * 0.7) / wrapperRect.width * 100}%`;
    dragonRight.style.top = `${titleRect.top - wrapperRect.top + (titleRect.height / 2)}px`;
}

// برای بار اول و همچنین تغییر اندازه صفحه
window.addEventListener('load', positionDragonTitleDecos);
window.addEventListener('resize', positionDragonTitleDecos);

// اگر نیاز به افکت پارالاکس دارید (مثل کد قبلی)
window.addEventListener('scroll', function() {
    // ... کد پارالاکس اینجا ...
});
