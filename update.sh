#!/bin/bash

# ==================================================================================
# === اسکریپت آپدیت هوشمند، امن و خودکار برای VPanel روی Ubuntu 22.04 ===
# === توسعه و طراحی توسط Iranli.com                                           ===
# === https://github.com/lkacom/vpanel                                         ===
# ==================================================================================

set -e

# رنگ‌ها برای رابط کاربری بهتر
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m'

PROJECT_PATH="/var/www/vpanel"
WEB_USER="www-data"
NPM_CACHE_DIR="/var/www/.npm"

# تابع چاپ پیام‌های مختلف
print_header() {
    echo -e "\n${CYAN}╔════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║ $1${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════╝${NC}\n"
}

print_step() {
    echo -e "${YELLOW}▶ مرحله $1${NC}"
}

print_success() {
    echo -e "${GREEN}✅ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ️ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠️ $1${NC}"
}

print_error() {
    echo -e "${RED}❌ $1${NC}"
}

# === بررسی‌های اولیه ===
print_header "آپدیت VPanel"

print_info "در حال بررسی محیط سیستم..."

if [ "$PWD" != "$PROJECT_PATH" ]; then
    print_error "این اسکریپت باید از درون پوشه $PROJECT_PATH اجرا شود!"
    print_info "دستور صحیح: cd $PROJECT_PATH && bash update.sh"
    exit 1
fi

if [ ! -f ".env" ]; then
    print_error "فایل .env یافت نشد!"
    print_info "اطمینان دهید که فایل .env در $PROJECT_PATH موجود است."
    exit 1
fi

if [ ! -d ".git" ]; then
    print_error "این پروژه یک مخزن Git نیست!"
    exit 1
fi

print_success "بررسی‌های اولیه تکمیل شد"
echo

# === مرحله ۱: آماده‌سازی محیط ===
print_step "۱ از ۸: آماده‌سازی محیط"
echo

print_warning "در حال ایجاد نسخه پشتیبان..."

# ایجاد پوشه پشتیبان اگر وجود نداشته باشد
BACKUP_DIR="$PROJECT_PATH/.backups"
sudo mkdir -p $BACKUP_DIR
sudo chown -R $WEB_USER:$WEB_USER $BACKUP_DIR

# ایجاد نسخه پشتیبان از .env
BACKUP_TIMESTAMP=$(date +%Y-%m-%d_%H-%M-%S)
sudo cp .env $BACKUP_DIR/.env.backup.$BACKUP_TIMESTAMP
print_success "نسخه پشتیبان در .backups/.env.backup.$BACKUP_TIMESTAMP ذخیره شد"

# آماده‌سازی پوشه کش NPM
print_warning "آماده‌سازی کش NPM..."
sudo mkdir -p $NPM_CACHE_DIR
sudo chown -R $WEB_USER:$WEB_USER $NPM_CACHE_DIR
sudo chown -R $WEB_USER:$WEB_USER $PROJECT_PATH
print_success "دسترسی‌ها تنظیم شدند"

# فعال‌سازی حالت تعمیر
print_warning "فعال‌سازی حالت تعمیر (Maintenance Mode)..."
sudo -u $WEB_USER php artisan down --render="errors::503" || true
print_success "سایت در حالت تعمیر قرار گرفت"

echo

# === مرحله ۲: دریافت آخرین کدها ===
print_step "۲ از ۸: دریافت آخرین نسخه کد"
echo

print_warning "در حال دریافت تغییرات از مخزن..."
sudo git fetch origin
print_info "شاخه فعلی: $(git rev-parse --abbrev-ref HEAD)"
print_info "آخرین کمیت محلی: $(git log -1 --pretty=format:'%h - %s' 2>/dev/null || echo 'نامشخص')"

print_warning "در حال بروزرسانی کد..."
sudo git reset --hard origin/main
print_success "کد با موفقیت آپدیت شد"

echo

# === مرحله ۳: تنظیم دسترسی‌ها ===
print_step "۳ از ۸: تنظیم دسترسی‌های فایل"
echo

print_warning "تنظیم مجدد دسترسی‌های صحیح..."
sudo chown -R $WEB_USER:$WEB_USER .
sudo chmod -R 775 storage bootstrap/cache
sudo find . -type f -exec chmod 644 {} \;
sudo find . -type d -exec chmod 755 {} \;
print_success "دسترسی‌های فایل به‌روزرسانی شد"

echo

# === مرحله ۴: آپدیت Composer ===
print_step "۴ از ۸: به‌روزرسانی پکیج‌های PHP"
echo

print_warning "در حال بررسی composer.lock..."
sudo -u $WEB_USER composer validate --no-check-publish || true

print_warning "نصب وابستگی‌های PHP..."
sudo -u $WEB_USER composer install --no-dev --optimize-autoloader --no-interaction
print_success "پکیج‌های PHP به‌روزرسانی شدند"

echo

# === مرحله ۵: آپدیت NPM و کامپایل Assets ===
print_step "۵ از ۸: به‌روزرسانی پکیج‌های Node.js"
echo

print_warning "پاکسازی کش NPM..."
sudo -u $WEB_USER npm cache clean --force

print_warning "نصب وابستگی‌های Node.js..."
sudo -u $WEB_USER HOME=/var/www npm install --legacy-peer-deps
print_success "وابستگی‌های Node.js نصب شدند"

print_warning "کامپایل Assets برای محیط Production..."
sudo -u $WEB_USER HOME=/var/www npm run build
print_success "Assets با موفقیت کامپایل شدند"

echo

# === مرحله ۶: آپدیت پایگاه داده ===
print_step "۶ از ۸: آپدیت پایگاه داده"
echo

print_warning "اجرای migrations..."
sudo -u $WEB_USER php artisan migrate --force
print_success "Migrations اجرا شد"

echo

# === مرحله ۷: بروزرسانی سرویس‌ها ===
print_step "۷ از ۸: بروزرسانی سرویس‌های سیستم"
echo

print_warning "ری‌استارت Supervisor Workers..."
sudo supervisorctl restart vpanel-worker:* || true
print_success "Supervisor Workers ری‌استارت شدند"

print_warning "ری‌استارت PHP-FPM..."
sudo systemctl restart php8.3-fpm
print_success "PHP-FPM ری‌استارت شد"

print_warning "ری‌لود Nginx..."
sudo nginx -t && sudo systemctl reload nginx
print_success "Nginx ری‌لود شد"

echo

# === مرحله ۸: پاکسازی کش و فعال‌سازی ===
print_step "۸ از ۸: پاکسازی کش‌ها و فعال‌سازی سایت"
echo

print_warning "پاکسازی تمام کش‌های برنامه..."
sudo -u $WEB_USER php artisan optimize:clear
print_success "کش‌ها پاکسازی شدند"

print_warning "بهینه‌سازی برنامه..."
sudo -u $WEB_USER php artisan optimize
print_success "برنامه بهینه‌سازی شد"

print_warning "خروج از حالت تعمیر..."
sudo -u $WEB_USER php artisan up
print_success "سایت فعال شد"

echo

# === پیام نهایی ===
print_header "✨ آپدیت با موفقیت انجام شد ✨"

echo -e "${GREEN}╔════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  VPanel به آخرین نسخه آپدیت شد!             ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════╝${NC}\n"

echo -e "${BLUE}📊 اطلاعات آپدیت:${NC}"
echo -e "   📅 تاریخ و ساعت: $(date '+%Y-%m-%d %H:%M:%S')"
echo -e "   🔖 نسخه فعلی: $(git describe --tags --always 2>/dev/null || git rev-parse --short HEAD)"
echo -e "   💾 نسخه پشتیبان: $BACKUP_TIMESTAMP"
echo -e "   📂 مسیر پروژه: $PROJECT_PATH"
echo

echo -e "${BLUE}✔️ مواردی که آپدیت شدند:${NC}"
echo -e "   ✓ کد منبع برنامه"
echo -e "   ✓ وابستگی‌های PHP"
echo -e "   ✓ وابستگی‌های Node.js"
echo -e "   ✓ Assets فرانتی‌اند"
echo -e "   ✓ پایگاه داده"
echo -e "   ✓ تنظیمات سرویس‌ها"
echo

echo -e "${BLUE}🔍 بررسی سرعت و وضعیت:${NC}"
print_info "بررسی وضعیت سرویس‌ها..."
echo -e "   ${GREEN}PHP-FPM:${NC} $(systemctl is-active php8.3-fpm || echo 'متوقف')"
echo -e "   ${GREEN}Nginx:${NC} $(systemctl is-active nginx || echo 'متوقف')"
echo -e "   ${GREEN}MySQL:${NC} $(systemctl is-active mysql || echo 'متوقف')"
echo -e "   ${GREEN}Redis:${NC} $(systemctl is-active redis-server || echo 'متوقف')"
echo -e "   ${GREEN}Supervisor:${NC} $(systemctl is-active supervisor || echo 'متوقف')"
echo

echo -e "${YELLOW}💡 نکات مهم:${NC}"
echo -e "   • بررسی logs برای هرگونه خطا: ${CYAN}sudo tail -f /var/log/nginx/error.log${NC}"
echo -e "   • وضعیت Queue Workers: ${CYAN}sudo supervisorctl status${NC}"
echo -e "   • نسخه پشتیبان در: ${CYAN}$BACKUP_DIR${NC}"
echo

echo -e "${CYAN}📧 درصورت مشکل، با تیم پشتیبانی Iranli.com تماس بگیرید${NC}\n"

# ری‌استارت supervisor به صورت کامل برای اطمینان
print_warning "انجام ری‌استارت نهایی Supervisor..."
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
print_success "تکمیل شد"

print_header "آپدیت کامل شد 🎉"
