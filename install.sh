#!/bin/bash

# ==================================================================================
# ===             اسکریپت نصب هوشمند، خودکار و ایمن برای VPanel روی Ubuntu 22.04 ===
# ===                                              توسعه و طراحی توسط Iranli.com ===
# === https://github.com/lkacom/vpanel                                           ===
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
GITHUB_REPO="https://github.com/lkacom/vpanel.git"
PHP_VERSION="8.3"

# تابع چاپ پیام‌های مختلف
print_header() {
    echo -e "\n${CYAN}╔════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║ $1${NC}"
    echo -e "${CYAN}╚════════════════════════════════════════╝${NC}\n"
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

# شروع نصب
print_header "خوش آمدید به نصب VPanel"
echo -e "این اسکریپ یک پلتفرم VPanel مجهز به بهترین تکنولوژی‌ها را برای شما نصب خواهد کرد.\n"

# === دریافت اطلاعات پیکربندی ===
print_header "تنظیمات اولیه"

read -p "${BLUE}🌐 دامنه (Domain):${NC} " DOMAIN
DOMAIN=$(echo $DOMAIN | sed 's|http[s]*://||g' | sed 's|/.*||g')
print_info "دامنه انتخاب شده: $DOMAIN"

read -p "${BLUE}🗃 نام پایگاه داده:${NC} " DB_NAME
read -p "${BLUE}👤 نام کاربری پایگاه داده:${NC} " DB_USER

while true; do
    read -s -p "${BLUE}🔑 رمز عبور پایگاه داده:${NC} " DB_PASS
    echo
    [ ! -z "$DB_PASS" ] && break
    print_error "رمز عبور نمی‌تواند خالی باشد!"
done

read -p "${BLUE}✉️ ایمیل برای SSL Let's Encrypt:${NC} " ADMIN_EMAIL
echo

# === حذف نسخه‌های قدیمی ===
print_header "آماده‌سازی سیستم"
print_warning "در حال حذف نسخه‌های قدیمی PHP..."
sudo apt-get remove -y php* || true
sudo apt-get autoremove -y
print_success "نسخه‌های قدیمی حذف شدند"

# === نصب ابزارهای ضروری ===
print_warning "نصب ابزارهای سیستمی..."
export DEBIAN_FRONTEND=noninteractive
sudo apt-get update -y
sudo apt-get install -y \
    git curl unzip software-properties-common gpg \
    nginx mysql-server redis-server supervisor ufw certbot python3-certbot-nginx
print_success "ابزارهای سیستمی نصب شدند"

# === نصب Node.js LTS ===
print_warning "نصب Node.js LTS..."
curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
sudo apt-get install -y nodejs build-essential
print_success "Node.js نصب شد (نسخه: $(node --version))"

# === نصب PHP 8.3 و افزونه‌های ضروری ===
print_warning "نصب PHP ${PHP_VERSION} و افزونه‌های لازم..."
sudo add-apt-repository -y ppa:ondrej/php
sudo apt-get update -y
sudo apt-get install -y \
    php${PHP_VERSION} php${PHP_VERSION}-fpm php${PHP_VERSION}-cli \
    php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml \
    php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-intl php${PHP_VERSION}-gd php${PHP_VERSION}-dom \
    php${PHP_VERSION}-redis php${PHP_VERSION}-dev
print_success "PHP ${PHP_VERSION} و افزونه‌ها نصب شدند"

# === تنظیم محدودیت‌های آپلود ===
print_warning "تنظیم محدودیت‌های آپلود فایل..."
PHP_INI_PATH="/etc/php/${PHP_VERSION}/fpm/php.ini"
sudo sed -i 's/upload_max_filesize = .*/upload_max_filesize = 256M/' $PHP_INI_PATH
sudo sed -i 's/post_max_size = .*/post_max_size = 256M/' $PHP_INI_PATH
sudo sed -i 's/max_file_uploads = .*/max_file_uploads = 100/' $PHP_INI_PATH
print_success "محدودیت‌های آپلود به 256 مگابایت تنظیم شدند"

# === نصب Composer ===
print_warning "نصب و تنظیم Composer..."
sudo apt-get remove -y composer || true
php${PHP_VERSION} -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php${PHP_VERSION} composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
sudo chmod +x /usr/local/bin/composer
print_success "Composer نصب و فعال‌سازی شد"

# === فعال‌سازی سرویس‌ها ===
print_warning "فعال‌سازی سرویس‌های سیستم..."
sudo systemctl enable --now php${PHP_VERSION}-fpm
sudo systemctl enable --now nginx
sudo systemctl enable --now mysql
sudo systemctl enable --now redis-server
sudo systemctl enable --now supervisor
print_success "تمام سرویس‌ها فعال و آماده هستند"

# === تنظیم فایروال ===
print_warning "تنظیم فایروال و دسترسی‌های امنیتی..."
sudo ufw allow 'OpenSSH'
sudo ufw allow 'Nginx Full'
echo "y" | sudo ufw enable
print_success "فایروال تنظیم شد"

# === دانلود و راه‌اندازی پروژه ===
print_header "دانلود و راه‌اندازی VPanel"
print_warning "دانلود کد منبع VPanel..."
sudo rm -rf "$PROJECT_PATH"
sudo git clone $GITHUB_REPO $PROJECT_PATH
sudo chown -R www-data:www-data $PROJECT_PATH
cd $PROJECT_PATH
print_success "کد منبع دانلود شد"

# === ایجاد پایگاه داده ===
print_warning "راه‌اندازی پایگاه داده..."
sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
print_success "پایگاه داده ایجاد و پیکربندی شد"

# === پیکربندی .env ===
print_warning "تنظیم فایل‌های پیکربندی..."
sudo -u www-data cp .env.example .env
sudo sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" .env
sudo sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|" .env
sudo sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env
sudo sed -i "s|APP_URL=.*|APP_URL=https://$DOMAIN|" .env
sudo sed -i "s|APP_ENV=.*|APP_ENV=production|" .env
sudo sed -i "s|APP_DEBUG=.*|APP_DEBUG=false|" .env
sudo sed -i "s|QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" .env
print_success "فایل‌های محیطی پیکربندی شدند"

# === نصب وابستگی‌های PHP (با پکیج Jalali) ===
print_warning "نصب وابستگی‌های PHP..."
sudo -u www-data composer install --no-dev --optimize-autoloader
print_success "وابستگی‌های PHP نصب شدند"

# === نصب پکیج Jalali ===
print_warning "نصب پکیج برای تقویم فارسی..."
sudo -u www-data composer require morilog/jalali
print_success "پکیج تاریخ شمسی نصب شد"

# === نصب وابستگی‌های Node.js ===
print_header "نصب وابستگی‌های فرانتی‌اند"
print_warning "آماده‌سازی NPM..."
sudo -u www-data rm -rf node_modules package-lock.json
sudo -u www-data npm cache clean --force

NPM_CACHE_DIR="/var/www/.npm"
sudo mkdir -p $NPM_CACHE_DIR
sudo chown -R www-data:www-data $NPM_CACHE_DIR
sudo chown -R www-data:www-data $PROJECT_PATH

print_warning "نصب پکیج‌های Node.js..."
sudo -u www-data npm install --cache $NPM_CACHE_DIR --legacy-peer-deps
print_warning "کامپایل اسکت‌های فرانتی‌اند..."
sudo -u www-data npm run build
print_success "وابستگی‌های فرانتی‌اند نصب و کامپایل شدند"

# === راه‌اندازی برنامه ===
print_warning "راه‌اندازی برنامه VPanel..."
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan migrate --seed --force
sudo -u www-data php artisan storage:link
print_success "پایگاه داده مهاجر و لینک‌های ذخیره‌سازی ایجاد شدند"

# === پیکربندی Nginx ===
print_header "پیکربندی وب‌سرور Nginx"
PHP_FPM_SOCK_PATH="/run/php/php${PHP_VERSION}-fpm.sock"

sudo tee /etc/nginx/sites-available/vpanel >/dev/null <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $PROJECT_PATH/public;

    # تنظیمات کارایی
    client_max_body_size 256M;
    client_body_timeout 300s;
    client_header_timeout 300s;

    index index.php;

    # فشرده‌سازی
    gzip on;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/xml+rss;

    # سرعت‌بخشی کش
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot)\$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:$PHP_FPM_SOCK_PATH;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    # جلوگیری از دسترسی به فایل‌های حساس
    location ~ /\. {
        deny all;
    }

    location ~ /\.env {
        deny all;
    }
}
EOF

sudo ln -sf /etc/nginx/sites-available/vpanel /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl restart nginx
print_success "Nginx پیکربندی شد"

# === راه‌اندازی Supervisor برای صف‌های کار ===
print_header "پیکربندی سیستم صف‌های کار"
sudo tee /etc/supervisor/conf.d/vpanel-worker.conf >/dev/null <<EOF
[program:vpanel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_PATH/artisan queue:work redis --sleep=3 --tries=3 --timeout=120
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/vpanel-worker.log
stopwaitsecs=3600
EOF

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start all
print_success "سیستم صف‌های کار فعال شد"

# === بهینه‌سازی کش ===
print_warning "بهینه‌سازی کش برنامه..."
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
print_success "کش بهینه‌سازی شد"

# === فعال‌سازی SSL ===
print_header "تنظیم SSL و امنیت"
read -p "${BLUE}🔒 آیا می‌خواهید SSL Let's Encrypt را فعال کنید؟ (y/n):${NC} " ENABLE_SSL
if [[ "$ENABLE_SSL" =~ ^[Yy]$ ]]; then
    print_warning "درحال درخواست گواهی SSL..."
    sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos -m $ADMIN_EMAIL
    print_success "گواهی SSL نصب شد"
else
    print_info "SSL فعال نشد. می‌توانید بعداً آن را نصب کنید."
fi

# === پیام نهایی ===
print_header "✨ نصب با موفقیت انجام شد ✨"
echo -e "${GREEN}╔════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║  VPanel آماده است!                          ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════╝${NC}\n"

echo -e "${BLUE}📍 اطلاعات اتصال:${NC}"
echo -e "   🌐 وب‌سایت: ${GREEN}https://$DOMAIN${NC}"
echo -e "   🛠 پنل مدیریت: ${GREEN}https://$DOMAIN/admin${NC}"
echo -e "   📊 پایگاه داده: ${YELLOW}$DB_NAME${NC}"
echo

echo -e "${BLUE}👤 اطلاعات ورود پیش‌فرض:${NC}"
echo -e "   📧 ایمیل: ${YELLOW}admin@example.com${NC}"
echo -e "   🔑 رمز عبور: ${YELLOW}password${NC}"
echo

echo -e "${RED}⚠️  مهم - اقدامات فوری:${NC}"
echo -e "   1️⃣ درحال ورود، رمز عبور ادمین را تغییر دهید"
echo -e "   2️⃣ اطلاعات حساس را بررسی و به‌روز کنید"
echo -e "   3️⃣ بکاپ منظم از پایگاه داده برنامه‌ریزی کنید"
echo -e "   4️⃣ فایل .env را از دسترس عمومی محافظت کنید"
echo

echo -e "${CYAN}📧 درصورت مشکل، تیم پشتیبانی Iranli.com را تماس بگیرید${NC}\n"

print_header "نصب تکمیل شد 🎉"
