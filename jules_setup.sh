# Function to print status messages
print_status() {
    echo "[INFO] $1"
}

print_success() {
    echo "[SUCCESS] $1"
}

print_error() {
    echo "[ERROR] $1"
}

# Function to check if command succeeded
check_status() {
    if [ $? -eq 0 ]; then
        print_success "$1"
    else
        print_error "$1 failed"
        exit 1
    fi
}

echo "Setting up PHP Development Environment"
echo "======================================"
echo

# Set non-interactive mode
export DEBIAN_FRONTEND=noninteractive

# Add PHP repository
print_status "Adding Ondrej PHP repository"
sudo -E python3.12 /usr/bin/add-apt-repository ppa:ondrej/php -y > /dev/null 2>&1
check_status "Added PHP repository"

# Install PHP and extensions
print_status "Installing PHP 8.1 and extensions"
sudo -E apt-get install -y php8.1 php8.1-cli php8.1-common php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip php8.1-xdebug unzip > /dev/null 2>&1
check_status "Installed PHP 8.1 and extensions"

# Download and install Composer
print_status "Installing Composer"
curl -s https://getcomposer.org/installer > composer-setup.php
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer --quiet
rm composer-setup.php
check_status "Installed Composer"

# Install project dependencies
print_status "Installing project dependencies"
composer install --no-interaction --no-ansi --quiet
check_status "Installed project dependencies"

print_success "Setup complete"
