#!/bin/bash
set -e # Exit immediately if a command exits with a non-zero status.

echo "Starting VM Setup Script..."

# Update package lists
echo "Updating package lists..."
sudo apt-get update

# Install software-properties-common (for add-apt-repository)
echo "Installing software-properties-common..."
sudo apt-get install -y software-properties-common

# Install/Reinstall python3-apt (for add-apt-repository dependency apt_pkg)
echo "Ensuring python3-apt is correctly installed (reinstalling)..."
sudo apt-get install --reinstall -y python3-apt

# Add PHP PPA
echo "Adding PHP PPA (ppa:ondrej/php)..."
if [ -f /usr/bin/python3.12 ] && /usr/bin/python3.12 -c "import apt_pkg" &>/dev/null; then
    echo "Attempting to use /usr/bin/python3.12 for add-apt-repository..."
    sudo /usr/bin/python3.12 /usr/bin/add-apt-repository ppa:ondrej/php -y
elif [ -f /usr/bin/python3.10 ] && /usr/bin/python3.10 -c "import apt_pkg" &>/dev/null; then
    echo "Attempting to use /usr/bin/python3.10 for add-apt-repository..."
    sudo /usr/bin/python3.10 /usr/bin/add-apt-repository ppa:ondrej/php -y
elif [ -f /usr/bin/python3.8 ] && /usr/bin/python3.8 -c "import apt_pkg" &>/dev/null; then
    echo "Attempting to use /usr/bin/python3.8 for add-apt-repository..."
    sudo /usr/bin/python3.8 /usr/bin/add-apt-repository ppa:ondrej/php -y
else
    echo "WARNING: Specific Python version (3.12, 3.10, 3.8) with apt_pkg not found or apt_pkg import failed. Falling back to default add-apt-repository."
    echo "If this fails, the Python environment for add-apt-repository might be misconfigured."
    sudo add-apt-repository ppa:ondrej/php -y
fi

# Update package lists again after adding PPA
echo "Updating package lists after adding PPA..."
sudo apt-get update

# Install PHP 8.1 and extensions
echo "Installing PHP 8.1 and extensions..."
sudo apt-get install -y php8.1 php8.1-cli php8.1-common php8.1-curl php8.1-mbstring php8.1-xml php8.1-zip php8.1-xdebug unzip
# Added php8.1-xml and php8.1-zip as they are common Composer requirements
# Added php8.1-xdebug for code coverage

# Install Composer
echo "Installing Composer..."
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"
echo "Composer installed."

# Install project dependencies (including dev)
echo "Installing project dependencies with Composer..."
composer install --no-interaction --no-ansi
echo "Composer dependencies installed."
echo "VM Setup Script completed successfully."
