#!/bin/bash

# ==================================================================================
# === اسکریپت حذف کامل، امن و نظم‌دار پروژه VPanel ===
# === توسعه و طراحی توسط Iranli.com                                           ===
# === https://github.com/iranli/VPanel                                          ===
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

# === شروع فرآیند حذف ===
print_header "حذف کامل VPanel"

echo -e "${RED}╔════════════════════════════════════════════════╗${NC}"
echo -e "${RED}║  ⚠️  هشدار: این عملیات غیرقابل بازگشت است! ║${NC}"
echo -e "${RED}╚════════════════════════════════════════════════╝${NC}\n"

print_warning "این اسکریپت تمام موارد زیر را حذف خواهد کرد:"
echo -e "   • تمام فایل‌های پروژه VPanel"
echo -e "   • پایگاه داده و کاربر مربوطه"
echo -e "   • تنظیمات Nginx و Supervisor"
echo -e "   • گواهی SSL (اختیاری)"
echo -e "   • نسخه‌های پشتیبان (اختیاری)"
echo

# === دریافت اطلاعات ضروری ===
print_header "جمع‌آوری اطلاعات"

read -p "${BLUE}🌐 دامنه سایت (برای حذف SSL):${NC} " DOMAIN
DOMAIN=$(echo $DOMAIN | sed 's|http[s]*://||g' | sed 's|/.*||g')

read -p "${BLUE}🗃 نام پایگاه داده برای حذف:${NC} " DB_NAME
read -p "${BLUE}👤 نام کاربری پایگاه داده برای حذف:${NC} " DB_USER

echo

# === تأیید نهایی ===
print_header "تأیید نهایی"

echo -e "${RED}آخرین هشدار: این عملیات ${YELLOW}غیرقابل بازگشت${RED} است!${NC}\n"

while true; do
    read -p "${RED}تایپ کنید 'بله، من متقاعد هستم' برای ادامه:${NC} " CONFIRMATION
    if [ "$CONFIRMATION" = "بله، من متقاعد هستم" ]; then
        print_success "تأیید شد"
        break
    else
        print_info "ورودی نادرست. دوباره سعی کنید یا Ctrl+C را فشار دهید"
    fi
done

echo

# === مرحله ۱: توقف سرویس‌ها ===
print_step "۱ از ۶: توقف سرویس‌ها"
echo

print_warning "توقف Supervisor Workers..."
sudo supervisorctl stop vpanel-worker:* 2>/dev/null || print_info "Worker‌ها قبلاً متوقف هستند یا یافت نشدند"

print_warning "توقف Nginx..."
sudo systemctl stop nginx || print_info "Nginx قبلاً متوقف است"

print_warning "توقف PHP-FPM..."
sudo systemctl stop php8.3-fpm || print_info "PHP-FPM قبلاً متوقف است"

print_success "تمام سرویس‌ها متوقف شدند"

echo

# === مرحله ۲: حذف کانفیگ‌های سرویس‌ها ===
print_step "۲ از ۶: حذف کانفیگ‌های سرویس‌ها"
echo

print_warning "حذف کانفیگ Nginx..."
sudo rm -f /etc/nginx/sites-available/vpanel
sudo rm -f /etc/nginx/sites-enabled/vpanel
print_success "کانفیگ Nginx حذف شد"

print_warning "حذف کانفیگ Supervisor..."
sudo rm -f /etc/supervisor/conf.d/vpanel-worker.conf
print_success "کانفیگ Supervisor حذف شد"

print_warning "بارگذاری مجدد سرویس‌ها..."
sudo supervisorctl reread 2>/dev/null || true
sudo supervisorctl update 2>/dev/null || true

print_warning "آزمایش Nginx..."
sudo nginx -t || print_warning "Nginx تستی شکست خورد (ممکن است عادی باشد)"

print_warning "ری‌استارت Nginx..."
sudo systemctl start nginx || print_warning "شروع Nginx شکست خورد"

print_success "کانفیگ‌های سرویس حذف و به‌روزرسانی شدند"

echo

# === مرحله ۳: حذف فایل‌های پروژه ===
print_step "۳ از ۶: حذف پوشه پروژه"
echo

if [ -d "$PROJECT_PATH" ]; then
    print_warning "حذف $PROJECT_PATH..."

    # اگر نسخه پشتیبان وجود دارد
    if [ -d "$PROJECT_PATH/.backups" ]; then
        read -p "${BLUE}🔄 نسخه‌های پشتیبان یافت شد. آن‌ها را نیز حذف کنید؟ (y/n):${NC} " DELETE_BACKUPS
        if [[ "$DELETE_BACKUPS" =~ ^[Yy]$ ]]; then
            sudo rm -rf "$PROJECT_PATH/.backups"
            print_success "نسخه‌های پشتیبان حذف شدند"
        else
            print_info "نسخه‌های پشتیبان حفظ شدند"
        fi
    fi

    sudo rm -rf "$PROJECT_PATH"
    print_success "پوشه پروژه کاملاً حذف شد"
else
    print_warning "پوشه پروژه یافت نشد (احتمالاً قبلاً حذف شده است)"
fi

echo

# === مرحله ۴: حذف پایگاه داده ===
print_step "۴ از ۶: حذف پایگاه داده"
echo

print_warning "حذف پایگاه داده '$DB_NAME'..."
sudo mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" 2>/dev/null && print_success "پایگاه داده حذف شد" || print_warning "پایگاه داده یافت نشد یا خطایی رخ داد"

print_warning "حذف کاربر دیتابیس '$DB_USER'..."
sudo mysql -e "DROP USER IF EXISTS '$DB_USER'@'localhost';" 2>/dev/null && print_success "کاربر دیتابیس حذف شد" || print_warning "کاربر یافت نشد"

sudo mysql -e "FLUSH PRIVILEGES;" 2>/dev/null || true

echo

# === مرحله ۵: حذف گواهی SSL ===
print_step "۵ از ۶: حذف گواهی SSL"
echo

read -p "${BLUE}🔒 آیا گواهی SSL برای دامنه $DOMAIN حذف شود؟ (y/n):${NC} " DELETE_SSL
if [[ "$DELETE_SSL" =~ ^[Yy]$ ]]; then
    print_warning "حذف گواهی SSL برای $DOMAIN..."

    if sudo certbot delete --cert-name $DOMAIN --non-interactive 2>/dev/null; then
        print_success "گواهی SSL حذف شد"
    else
        print_warning "گواهی SSL یافت نشد یا خطایی در حذف رخ داد"
    fi
else
    print_info "گواهی SSL حفظ شد"
fi

echo

# === مرحله ۶: پاکسازی نهایی ===
print_step "۶ از ۶: پاکسازی نهایی"
echo

print_warning "پاکسازی کش NPM..."
sudo rm -rf /var/www/.npm 2>/dev/null || true
print_success "کش NPM پاکسازی شد"

print_warning "شروع مجدد Nginx..."
sudo systemctl start nginx || print_warning "شروع Nginx شکست خورد"

print_success "پاکسازی نهایی تکمیل شد"

echo

# === پیام نهایی ===
print_header "✨ حذف کامل تکمیل شد ✨"

echo -e "${GREEN}╔════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  VPanel کاملاً حذف شد!                       ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════╝${NC}\n"

echo -e "${BLUE}📊 خلاصه حذف:${NC}"
echo -e "   ✓ پوشه پروژه: حذف شد"
echo -e "   ✓ پایگاه داده: حذف شد"
echo -e "   ✓ کاربر دیتابیس: حذف شد"
echo -e "   ✓ کانفیگ Nginx: حذف شد"
echo -e "   ✓ کانفیگ Supervisor: حذف شد"
echo -e "   ✓ کش NPM: پاکسازی شد"

if [[ "$DELETE_SSL" =~ ^[Yy]$ ]]; then
    echo -e "   ✓ گواهی SSL: حذف شد"
else
    echo -e "   ⊘ گواهی SSL: حفظ شد"
fi

echo

echo -e "${BLUE}🖥️ سرور شما آماده است برای:${NC}"
echo -e "   • نصب مجدد VPanel"
echo -e "   • نصب پروژه‌های دیگر"
echo -e "   • بکارگیری برای منظور دیگری"
echo

echo -e "${YELLOW}💡 برای نصب مجدد VPanel:${NC}"
echo -e "   ${CYAN}wget -O install.sh https://raw.githubusercontent.com/iranli/VPanel/main/install.sh && sudo bash install.sh${NC}"
echo

echo -e "${CYAN}📧 درصورت نیاز، از تیم پشتیبانی Iranli.com کمک بگیرید${NC}\n"

print_header "با موفقیت انجام شد 🎉"
