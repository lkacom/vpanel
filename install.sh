#!/bin/bash

# ==================================================================================
# ===                     VPanel Management Script for Ubuntu 22.04            ===
# ===                        Designed & Developed by Iranli                    ===
# ===                              www.iranli.com                              ===
# === Repo: https://github.com/lkacom/vpanel                                   ===
# ==================================================================================
#
# This single script handles three actions:
#   install   - Fresh installation of VPanel on a clean server
#   update    - Pull the latest code and update an existing installation
#   uninstall - Completely remove VPanel and all related components
#
# Usage:
#   sudo ./install.sh install
#   sudo ./install.sh update
#   sudo ./install.sh uninstall
#
# If no argument is passed, an interactive menu will be shown.
# ==================================================================================

set -e

# ---------------------------- Colors ----------------------------
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
RED='\033[0;31m'
NC='\033[0m'

# ---------------------------- Globals ----------------------------
PROJECT_PATH="/var/www/vpanel"
GITHUB_REPO="https://github.com/lkacom/vpanel.git"
PHP_VERSION="8.4"
WEB_USER="www-data"

# ---------------------------- Helpers ----------------------------
banner() {
        echo -e "${CYAN}"
        echo "╔══════════════════════════════════════════════════════════╗"
        echo "║                                                          ║"
        echo "║  ██╗   ██╗██████╗  █████╗ ███╗   ██╗███████╗██╗         ║"
        echo "║  ██║   ██║██╔══██╗██╔══██╗████╗  ██║██╔════╝██║         ║"
        echo "║  ██║   ██║██████╔╝███████║██╔██╗ ██║█████╗  ██║         ║"
        echo "║  ╚██╗ ██╔╝██╔═══╝ ██╔══██║██║╚██╗██║██╔══╝  ██║         ║"
        echo "║   ╚████╔╝ ██║     ██║  ██║██║ ╚████║███████╗███████╗    ║"
        echo "║    ╚═══╝  ╚═╝     ╚═╝  ╚═╝╚═╝  ╚═══╝╚══════╝╚══════╝    ║"
        echo "║                                                          ║"
        echo "║          Website : www.iranli.com                       ║"
        echo "║          Creator : Iranli                               ║"
        echo "║          Tool    : VPanel Auto Installer v1.0           ║"
        echo "║                                                          ║"
        echo "╚══════════════════════════════════════════════════════════╝"
        echo -e "${NC}"
}

install_banner() {
    echo -e "${CYAN}=====================================================${NC}"
    echo -e "${CYAN}                 VPanel by Iranli.com               ${NC}"
    echo -e "${CYAN}=====================================================${NC}"
}

step() {
    echo -e "${YELLOW}$1${NC}"
}

success() {
    echo -e "${GREEN}$1${NC}"
}

error() {
    echo -e "${RED}$1${NC}"
}

require_root() {
    if [ "$EUID" -ne 0 ] && ! sudo -n true 2>/dev/null; then
        error "This script requires sudo privileges. Please run it with a user that has sudo access."
    fi
}

# ==================================================================================
# ===                                INSTALL                                    ===
# ==================================================================================
install_vpanel() {
    install_banner
    echo -e "${NC}|| Starting VPanel installation ||${NC}"
    echo

    # --- Collect information from the user ---
    read -p "🌐 Domain: " DOMAIN
    DOMAIN=$(echo "$DOMAIN" | sed 's|http[s]*://||g' | sed 's|/.*||g')

    read -p "✉️ Email (used for SSL certificate): " ADMIN_EMAIL
    echo

    # --- Auto-generate database credentials ---
    step "🎲 Generating random database credentials..."
    DB_NAME="vpanel_$(tr -dc 'a-z0-9' </dev/urandom | head -c 8)"
    DB_USER="vpuser_$(tr -dc 'a-z0-9' </dev/urandom | head -c 8)"
    DB_PASS="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
    success "Database credentials generated successfully."

    # --- Remove old PHP versions ---
    step "🧹 Removing old PHP versions..."
    sudo apt-get remove -y php* || true
    sudo apt autoremove -y

    # --- Prerequisites ---
    step "📦 Installing required packages..."
    export DEBIAN_FRONTEND=noninteractive
    sudo apt-get update -y
    sudo apt-get install -y git curl unzip software-properties-common gpg nginx mysql-server redis-server supervisor ufw certbot python3-certbot-nginx

    # --- Install Node.js LTS ---
    step "📦 Installing Node.js..."
    curl -fsSL https://deb.nodesource.com/setup_lts.x | sudo -E bash -
    sudo apt-get install -y nodejs build-essential

    # --- Install PHP 8.3 ---
    step "☕ Installing PHP ${PHP_VERSION}..."
    sudo add-apt-repository -y ppa:ondrej/php
    sudo apt-get update -y
    sudo apt-get install -y \
        php${PHP_VERSION} php${PHP_VERSION}-fpm php${PHP_VERSION}-cli \
        php${PHP_VERSION}-mysql php${PHP_VERSION}-mbstring php${PHP_VERSION}-xml \
        php${PHP_VERSION}-curl php${PHP_VERSION}-zip php${PHP_VERSION}-bcmath \
        php${PHP_VERSION}-intl php${PHP_VERSION}-gd php${PHP_VERSION}-dom \
        php${PHP_VERSION}-redis

    step "🔧 Configuring PHP upload limits..."
    PHP_INI_PATH="/etc/php/${PHP_VERSION}/fpm/php.ini"
    sudo sed -i 's/upload_max_filesize = .*/upload_max_filesize = 10M/' "$PHP_INI_PATH"
    sudo sed -i 's/post_max_size = .*/post_max_size = 12M/' "$PHP_INI_PATH"
    success "PHP upload limit increased to 10MB."

    # --- Composer ---
    sudo apt-get remove -y composer || true
    php${PHP_VERSION} -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    php${PHP_VERSION} composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm composer-setup.php
    success "✔ Composer activated with PHP ${PHP_VERSION}."

    # --- Enable services ---
    sudo systemctl enable --now php${PHP_VERSION}-fpm nginx mysql redis-server supervisor

    # --- Firewall ---
    sudo ufw allow 'OpenSSH'
    sudo ufw allow 'Nginx Full'

    # --- Download project ---
    step "⬇️ Downloading source code..."
    sudo rm -rf "$PROJECT_PATH"
    sudo git clone "$GITHUB_REPO" "$PROJECT_PATH"
    sudo chown -R ${WEB_USER}:${WEB_USER} "$PROJECT_PATH"
    cd "$PROJECT_PATH"

    # --- Create database ---
    sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`;"
    sudo mysql -e "CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';"
    sudo mysql -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';"
    sudo mysql -e "FLUSH PRIVILEGES;"

    # --- Configure .env ---
    sudo -u ${WEB_USER} cp .env.example .env
    sudo sed -i "s|DB_DATABASE=.*|DB_DATABASE=$DB_NAME|" .env
    sudo sed -i "s|DB_USERNAME=.*|DB_USERNAME=$DB_USER|" .env
    sudo sed -i "s|DB_PASSWORD=.*|DB_PASSWORD=$DB_PASS|" .env
    sudo sed -i "s|APP_URL=.*|APP_URL=https://$DOMAIN|" .env
    sudo sed -i "s|APP_ENV=.*|APP_ENV=production|" .env
    sudo sed -i "s|QUEUE_CONNECTION=.*|QUEUE_CONNECTION=redis|" .env
    # Store the SSL email so future `update` runs can retry issuing a certificate automatically
    echo "VPANEL_SSL_EMAIL=$ADMIN_EMAIL" | sudo tee -a .env >/dev/null

    # --- Install dependencies ---
    step "🧰 Installing Composer packages..."
    # Prepare a writable HOME/cache dir for www-data so Composer's cache works correctly
    sudo mkdir -p /var/www/.cache
    sudo chown -R ${WEB_USER}:${WEB_USER} /var/www/.cache
    sudo -u ${WEB_USER} HOME=/var/www composer install --no-dev --optimize-autoloader
    sudo -u ${WEB_USER} HOME=/var/www composer require morilog/jalali

    step "📦 Installing Node.js packages..."
    sudo -u ${WEB_USER} rm -rf node_modules package-lock.json
    sudo -u ${WEB_USER} npm cache clean --force

    NPM_CACHE_DIR="/var/www/.npm"
    sudo mkdir -p "$NPM_CACHE_DIR"
    sudo chown -R ${WEB_USER}:${WEB_USER} "$NPM_CACHE_DIR"
    sudo chown -R ${WEB_USER}:${WEB_USER} "$PROJECT_PATH"

    sudo -u ${WEB_USER} npm install --cache "$NPM_CACHE_DIR" --legacy-peer-deps
    sudo -u ${WEB_USER} npm run build

    sudo -u ${WEB_USER} php artisan key:generate
    sudo -u ${WEB_USER} php artisan migrate:fresh --seed --force --no-interaction
    sudo -u ${WEB_USER} php artisan storage:link

    # --- Configure Nginx ---
    step "🌐 Configuring Nginx with upload limits..."
    PHP_FPM_SOCK_PATH="/run/php/php${PHP_VERSION}-fpm.sock"

    sudo tee /etc/nginx/sites-available/vpanel >/dev/null <<EOF
server {
    listen 80;
    server_name $DOMAIN;
    root $PROJECT_PATH/public;

    client_max_body_size 10M;

    index index.php;
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
    location ~ \.php\$ {
        fastcgi_pass unix:$PHP_FPM_SOCK_PATH;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
EOF

    sudo ln -sf /etc/nginx/sites-available/vpanel /etc/nginx/sites-enabled/
    sudo rm -f /etc/nginx/sites-enabled/default
    sudo nginx -t && sudo systemctl restart nginx
    success "Nginx upload limit increased to 10MB."

    # --- Supervisor ---
    sudo tee /etc/supervisor/conf.d/vpanel-worker.conf >/dev/null <<EOF
[program:vpanel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_PATH/artisan queue:work redis --sleep=3 --tries=3
autostart=true
autorestart=true
user=${WEB_USER}
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/vpanel-worker.log
EOF

    sudo supervisorctl reread
    sudo supervisorctl update
    sudo supervisorctl start all

    # --- Cache ---
    sudo -u ${WEB_USER} php artisan config:cache
    sudo -u ${WEB_USER} php artisan route:cache
    sudo -u ${WEB_USER} php artisan view:cache

    # --- SSL (enabled by default, never blocks the install) ---
    step "🔒 Requesting SSL certificate for $DOMAIN..."
    SSL_ENABLED="no"
    if sudo certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$ADMIN_EMAIL"; then
        success "✔ SSL certificate issued successfully."
        SSL_ENABLED="yes"
    else
        error "⚠️ SSL setup failed (domain not reachable on port 80/443 yet)."
        error "The rest of the installation completed successfully — the site is available over HTTP."
        error "Once DNS/firewall is fixed, just run: sudo $0 update"
        error "(the update process will automatically retry issuing the SSL certificate)"
    fi

    SITE_URL="http://$DOMAIN"
    [ "$SSL_ENABLED" = "yes" ] && SITE_URL="https://$DOMAIN"

    # --- Save all credentials to a local file for the admin's records ---
    CREDS_FILE="/root/vpanel-install-info.txt"
    sudo tee "$CREDS_FILE" >/dev/null <<EOF
VPanel Installation Info — generated on $(date)
=================================================
Site URL:        $SITE_URL
Admin panel:     $SITE_URL/admin
Admin email:     admin@example.com
Admin password:  admin   (change this after first login!)

Database name:     $DB_NAME
Database user:     $DB_USER
Database password: $DB_PASS

Installed by: Iranli — www.iranli.com
EOF
    sudo chmod 600 "$CREDS_FILE"

    echo -e "${GREEN}=====================================================${NC}"
    success "✅ Installation completed successfully!"
    echo -e "🌐 Site:        $SITE_URL"
    echo -e "🔑 Admin panel: $SITE_URL/admin"
    echo
    echo -e "   - Login email:    ${YELLOW}admin@example.com${NC}"
    echo -e "   - Login password: ${YELLOW}admin${NC}"
    echo
    echo -e "   - DB name:        ${YELLOW}$DB_NAME${NC}"
    echo -e "   - DB user:        ${YELLOW}$DB_USER${NC}"
    echo -e "   - DB password:    ${YELLOW}$DB_PASS${NC}"
    echo
    echo -e "Full credentials also saved to: ${YELLOW}$CREDS_FILE${NC}"
    echo
    error "⚠️ IMPORTANT: Please change the admin password immediately after your first login!"
    echo -e "${GREEN}=====================================================${NC}"
    echo -e "${CYAN}Developed by Iranli — www.iranli.com${NC}"
}

# ==================================================================================
# ===                                 UPDATE                                    ===
# ==================================================================================
update_vpanel() {
    banner
    echo -e "${CYAN}--- Starting VPanel update process ---${NC}"

    sudo git config --global --add safe.directory "$PROJECT_PATH"

    if [ ! -d "$PROJECT_PATH" ]; then
        error "Error: Project folder ($PROJECT_PATH) not found. Please install VPanel first."
        exit 1
    fi

    cd "$PROJECT_PATH"

    if [ ! -f ".env" ]; then
        error "Error: .env file not found!"
        exit 1
    fi

    echo

    # --- Step 1: Prepare environment & enable maintenance mode ---
    step "Step 1/8: Preparing environment and enabling maintenance mode..."

    echo "Creating and setting permissions for the NPM cache folder..."
    sudo mkdir -p /var/www/.npm
    sudo chown -R ${WEB_USER}:${WEB_USER} /var/www/.npm

    sudo cp .env ".env.bak.$(date +%Y-%m-%d_%H-%M-%S)"
    echo "A backup of your .env file has been created in the same directory."

    sudo -u ${WEB_USER} php artisan down || true

    # --- Step 2: Pull latest code from GitHub ---
    step "Step 2/8: Fetching the latest changes from GitHub..."
    sudo git fetch origin
    sudo git reset --hard origin/main

    # --- Step 3: Fix file permissions ---
    step "Step 3/8: Resetting file permissions..."
    sudo chown -R ${WEB_USER}:${WEB_USER} .
    sudo chmod -R 775 storage bootstrap/cache

    # --- Step 4: Update PHP dependencies (Composer) ---
    step "Step 4/8: Updating PHP packages..."
    sudo mkdir -p /var/www/.cache
    sudo chown -R ${WEB_USER}:${WEB_USER} /var/www/.cache
    sudo -u ${WEB_USER} HOME=/var/www composer install --no-dev --optimize-autoloader

    # --- Step 5: Update frontend dependencies (NPM) ---
    step "Step 5/8: Updating Node.js packages and compiling assets..."
    sudo -u ${WEB_USER} HOME=/var/www npm install
    sudo -u ${WEB_USER} HOME=/var/www npm run build
    echo "JS/CSS assets compiled for production."

    # --- Step 6: Update database & restart services ---
    step "Step 6/8: Updating database and restarting services..."
    sudo -u ${WEB_USER} php artisan migrate --force
    sudo supervisorctl restart vpanel-worker:* || true
    echo "Queue worker services restarted successfully."

    # --- Step 7: Clear caches & disable maintenance mode ---
    step "Step 7/8: Clearing caches and bringing the site back online..."
    sudo -u ${WEB_USER} php artisan optimize:clear
    sudo -u ${WEB_USER} php artisan up

    # --- Step 8: Retry SSL if it isn't active yet ---
    DOMAIN=$(grep '^APP_URL=' .env | head -n1 | cut -d '=' -f2- | sed 's|https\?://||' | sed 's|/.*||')
    SSL_EMAIL=$(grep '^VPANEL_SSL_EMAIL=' .env | head -n1 | cut -d '=' -f2-)

    if [ -n "$DOMAIN" ] && [ ! -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]; then
        step "Step 8/8: No active SSL certificate found for $DOMAIN — retrying..."
        if [ -z "$SSL_EMAIL" ]; then
            error "⚠️ No saved SSL email found; skipping automatic SSL. Run manually:"
            error "   sudo certbot --nginx -d $DOMAIN --agree-tos -m your@email.com"
        elif sudo certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$SSL_EMAIL"; then
            success "✔ SSL certificate issued successfully for $DOMAIN."
        else
            error "⚠️ SSL still could not be issued (domain/firewall may not be ready yet)."
            error "The update itself completed successfully."
        fi
    else
        step "Step 8/8: SSL certificate already active for $DOMAIN — nothing to do."
    fi

    echo
    echo -e "${GREEN}=====================================================${NC}"
    success "✅ VPanel updated successfully!"
    echo -e "${GREEN}=====================================================${NC}"
    echo -e "${CYAN}Developed by Iranli — www.iranli.com${NC}"
}

# ==================================================================================
# ===                               UNINSTALL                                   ===
# ==================================================================================
uninstall_vpanel() {
    banner
    step "--- Starting full VPanel removal process ---"
    error "⚠️ Warning: This action is irreversible and will delete all project files, the database, and everything related to this script."
    echo

    # --- Read database and domain info from .env before anything is deleted ---
    ENV_FILE="$PROJECT_PATH/.env"
    if [ -f "$ENV_FILE" ]; then
        DB_NAME=$(grep '^DB_DATABASE=' "$ENV_FILE" | cut -d '=' -f2)
        DB_USER=$(grep '^DB_USERNAME=' "$ENV_FILE" | cut -d '=' -f2)
        DOMAIN=$(grep '^APP_URL=' "$ENV_FILE" | head -n1 | cut -d '=' -f2- | sed 's|https\?://||' | sed 's|/.*||')
    else
        error "⚠️ .env file not found. Database and SSL removal will be skipped."
        DB_NAME=""
        DB_USER=""
        DOMAIN=""
    fi

    read -p "Are you sure you want to completely remove the project and its configuration? (y/n): " CONFIRMATION
    if [[ "$CONFIRMATION" != "y" && "$CONFIRMATION" != "Y" ]]; then
        step "Operation cancelled."
        exit 0
    fi

    # --- Step 1: Stop services ---
    step "Step 1/8: Stopping VPanel and related services..."
    sudo systemctl is-active --quiet php${PHP_VERSION}-fpm && sudo systemctl stop php${PHP_VERSION}-fpm || true
    sudo systemctl is-active --quiet nginx && sudo systemctl stop nginx || true
    sudo systemctl is-active --quiet mysql && sudo systemctl stop mysql || true
    sudo systemctl is-active --quiet redis-server && sudo systemctl stop redis-server || true
    sudo supervisorctl status &>/dev/null && sudo supervisorctl stop all || true

    # --- Step 2: Remove Nginx and Supervisor configs ---
    step "Step 2/8: Removing configuration files..."
    sudo rm -f /etc/nginx/sites-available/vpanel
    sudo rm -f /etc/nginx/sites-enabled/vpanel
    sudo rm -f /etc/supervisor/conf.d/vpanel-worker.conf

    sudo supervisorctl reread &>/dev/null || true
    sudo supervisorctl update &>/dev/null || true

    # --- Step 3: Remove project files and script leftovers ---
    step "Step 3/8: Removing the project folder..."
    if [ -d "$PROJECT_PATH" ]; then
        sudo rm -rf "$PROJECT_PATH"
        success "Project folder removed successfully."
    else
        step "Project folder not found (it may have already been removed)."
    fi

    step "Removing cache/cred files created by this script..."
    sudo rm -rf /var/www/.npm /var/www/.cache
    sudo rm -f /root/vpanel-install-info.txt

    # --- Step 4: Remove database and database user ---
    if [ -n "$DB_NAME" ] && [ -n "$DB_USER" ]; then
        step "Step 4/8: Removing the database and its user..."
        sudo mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;" || true
        sudo mysql -e "DROP USER IF EXISTS '$DB_USER'@'localhost';" || true
        sudo mysql -e "FLUSH PRIVILEGES;" || true
        success "Database and user removed successfully."
    else
        error "Database name or user not found; database removal skipped."
    fi

    # --- Step 5: Remove PHP ---
    step "Step 5/8: Removing PHP ${PHP_VERSION} and its modules..."
    sudo apt-get remove -y php${PHP_VERSION}* || true
    sudo apt autoremove -y || true

    # --- Step 6: Remove Node.js, Composer and dependencies ---
    step "Step 6/8: Removing Node.js, Composer and project dependencies..."
    sudo apt-get remove -y nodejs npm || true
    sudo rm -f /usr/local/bin/composer || true

    # --- Step 7: Remove SSL certificate ---
    step "Step 7/8: Removing SSL certificate..."
    if [ -n "$DOMAIN" ]; then
        sudo certbot delete --cert-name "$DOMAIN" --non-interactive || echo "SSL certificate not found or could not be removed."
    else
        step "No domain found in .env — skipping SSL certificate removal."
    fi

    # --- Step 8: Restart core services ---
    step "Step 8/8: Restarting remaining core services..."
    sudo systemctl is-active --quiet nginx && sudo systemctl start nginx || true
    sudo systemctl is-active --quiet mysql && sudo systemctl start mysql || true
    sudo systemctl is-active --quiet redis-server && sudo systemctl start redis-server || true

    echo
    echo -e "${GREEN}=====================================================${NC}"
    success "✅ VPanel has been completely removed."
    success "Your server is now ready for a fresh installation."
    echo -e "${GREEN}=====================================================${NC}"
    echo -e "${CYAN}Developed by Iranli — www.iranli.com${NC}"
}

# ==================================================================================
# ===                                  MENU                                     ===
# ==================================================================================
main() {
    require_root

    ACTION="$1"

    if [ -z "$ACTION" ]; then
        banner
        echo "What would you like to do?"
        echo "  1) Install VPanel"
        echo "  2) Update VPanel"
        echo "  3) Uninstall VPanel"
        read -p "Select an option [1-3]: " CHOICE
        case "$CHOICE" in
            1) ACTION="install" ;;
            2) ACTION="update" ;;
            3) ACTION="uninstall" ;;
            *) error "Invalid option."; exit 1 ;;
        esac
    fi

    case "$ACTION" in
        install)   install_vpanel ;;
        update)    update_vpanel ;;
        uninstall) uninstall_vpanel ;;
        *)
            error "Usage: $0 [install|update|uninstall]"
            exit 1
            ;;
    esac
}

main "$@"
