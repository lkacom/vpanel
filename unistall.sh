#!/bin/bash

# ==================================================================================
# ===                              اسکریپت حذف کامل VPanel                     ===
# ==================================================================================

set -e

# --- تعریف رنگ‌ها ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

PROJECT_PATH="/var/www/vpanel"

# --- گرفتن عرض ترمینال ---
TERM_WIDTH=$(tput cols)

# --- تابع وسط‌چین کردن متن ---
center() {
    local text="$1"
    local color="$2"
    local text_length=${#text}
    local padding=$(( (TERM_WIDTH - text_length) / 2 ))
    printf "%*s%s%s%s\n" $padding "" "$color" "$text" "$NC"
}

echo -e "${YELLOW}--- شروع فرآیند حذف کامل پروژه VPanel ---${NC}"
echo -e "${RED}⚠️ هشدار: این عملیات غیرقابل بازگشت است و تمام فایل‌ها و دیتابیس پروژه را حذف می‌کند.${NC}"
echo

# --- خواندن اطلاعات دیتابیس از فایل .env ---
ENV_FILE="$PROJECT_PATH/.env"
if [ -f "$ENV_FILE" ]; then
    DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2)
    DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2)
else
    center "⚠️ فایل .env یافت نشد. حذف دیتابیس امکان‌پذیر نیست." "$RED"
    DB_NAME=""
    DB_USER=""
fi

# --- دریافت اطلاعات دامنه ---
read -p "🌐 دامنه سایت را برای حذف گواهی SSL وارد کنید (مثال: vpanel.example.com): " DOMAIN

read -p "آیا از حذف کامل پروژه و کانفیگ‌ها اطمینان دارید؟ (y/n): " CONFIRMATION
if [[ "$CONFIRMATION" != "y" && "$CONFIRMATION" != "Y" ]]; then
    center "عملیات لغو شد." "$YELLOW"
    exit 0
fi

# --- مرحله ۱: توقف سرویس‌ها ---
center "M 1/7: در حال توقف سرویس‌های VPanel و مرتبط..." "$YELLOW"
sudo systemctl is-active --quiet php8.3-fpm && sudo systemctl stop php8.3-fpm || true
sudo systemctl is-active --quiet nginx && sudo systemctl stop nginx || true
sudo systemctl is-active --quiet mysql && sudo systemctl stop mysql || true
sudo systemctl is-active --quiet redis-server && sudo systemctl stop redis-server || true
sudo supervisorctl status &>/dev/null && sudo supervisorctl stop all || true

# --- مرحله ۲: حذف کانفیگ‌های Nginx و Supervisor ---
center "M 2/7: در حال حذف فایل‌های کانفیگ..." "$YELLOW"
sudo rm -f /etc/nginx/sites-available/vpanel
sudo rm -f /etc/nginx/sites-enabled/vpanel
sudo rm -f /etc/supervisor/conf.d/vpanel-worker.conf

sudo supervisorctl reread &>/dev/null || true
sudo supervisorctl update &>/dev/null || true

# --- مرحله ۳: حذف فایل‌های پروژه ---
center "M 3/7: در حال حذف کامل پوشه پروژه..." "$YELLOW"
if [ -d "$PROJECT_PATH" ]; then
    sudo rm -rf "$PROJECT_PATH"
    center "پوشه پروژه با موفقیت حذف شد." "$GREEN"
else
    center "پوشه پروژه یافت نشد (احتمالا قبلاً حذف شده است)." "$YELLOW"
fi

# --- مرحله ۴: حذف دیتابیس و کاربر دیتابیس ---
if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
    center "M 4/7: در حال حذف دیتابیس و کاربر مربوطه..." "$YELLOW"
    sudo mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" || true
    sudo mysql -e "DROP USER IF EXISTS '$DB_USER'@'localhost';" || true
    sudo mysql -e "FLUSH PRIVILEGES;" || true
    center "دیتابیس و کاربر با موفقیت حذف شدند." "$GREEN"
else
    center "نام دیتابیس یا کاربر یافت نشد، حذف دیتابیس انجام نشد." "$RED"
fi

# --- مرحله ۵: حذف PHP 8.3 ---
center "M 5/7: حذف PHP 8.3 و ماژول‌ها..." "$YELLOW"
sudo apt-get remove -y php8.3* || true
sudo apt autoremove -y || true

# --- مرحله ۶: حذف Node.js، Composer و وابستگی‌ها ---
center "M 6/7: حذف Node.js، Composer و وابستگی‌های پروژه..." "$YELLOW"
sudo apt-get remove -y nodejs npm || true
sudo rm -f /usr/local/bin/composer || true
sudo rm -rf /var/www/.npm || true

# --- مرحله ۷: حذف SSL ---
read -p "آیا گواهی SSL مربوط به دامنه $DOMAIN نیز حذف شود؟ (y/n): " DELETE_SSL
if [[ "$DELETE_SSL" == "y" || "$DELETE_SSL" == "Y" ]]; then
    center "M 7/7: در حال حذف گواهی SSL..." "$YELLOW"
    sudo certbot delete --cert-name $DOMAIN --non-interactive || echo "گواهی SSL یافت نشد یا در حذف آن مشکلی پیش آمد."
fi

# --- ری‌استارت سرویس‌های اصلی ---
sudo systemctl is-active --quiet nginx && sudo systemctl start nginx || true
sudo systemctl is-active --quiet mysql && sudo systemctl start mysql || true
sudo systemctl is-active --quiet redis-server && sudo systemctl start redis-server || true

# --- پیام نهایی وسط‌چین ---
echo
center "=====================================================" "$GREEN"
center "✅ فرآیند حذف کامل با موفقیت انجام شد." "$GREEN"
center "سرور شما اکنون برای نصب مجدد آماده است." ""
center "=====================================================" "$GREEN"
