#!/bin/bash
set -e # Exit immediately if a command exits with a non-zero status.

echo "Starting VM Setup Script..."

# Update package lists
echo "Updating package lists..."
sudo apt-get update

# Install PHP 8.1 and extensions
echo "Installing PHP 8.1 and extensions..."
sudo apt-get install -y php8.1 php8.1-cli php8.1-common php8.1-json php8.1-curl php8.1-mbstring unzip

# Install Composer
echo "Installing Composer..."
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
# Add hash verification for security - obtain hash from https://composer.github.io/installer.sig
#EXPECTED_SIGNATURE=$(php -r "echo file_get_contents('https://composer.github.io/installer.sig');")
#ACTUAL_SIGNATURE=$(php -r "echo hash_file('sha384', 'composer-setup.php');")
#if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]
#then
#    >&2 echo 'ERROR: Invalid installer signature'
#    rm composer-setup.php
#    exit 1
#fi
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
php -r "unlink('composer-setup.php');"
echo "Composer installed."

# Install project dependencies (including dev)
echo "Installing project dependencies with Composer..."
composer install --no-interaction --no-ansi
echo "Composer dependencies installed."

# Run linters and tests
echo "Running linters and tests..."

echo "Running PHP CodeSniffer (phpcs)..."
if [ -f vendor/bin/phpcs ]; then
    vendor/bin/phpcs
else
    echo "PHP CodeSniffer not found at vendor/bin/phpcs. Skipping."
fi

echo "Running PHPStan..."
if [ -f vendor/bin/phpstan ]; then
    # It's good practice for PHPStan to have a config file (phpstan.neon or phpstan.neon.dist)
    # Assuming a basic run if no config is present.
    # Adjust level and paths as necessary for the project.
    if [ -f phpstan.neon ] || [ -f phpstan.neon.dist ]; then
        vendor/bin/phpstan analyse --memory-limit=2G
    else
        vendor/bin/phpstan analyse src tests --level=5 --memory-limit=2G # Sensible defaults
    fi
else
    echo "PHPStan not found at vendor/bin/phpstan. Skipping."
fi

echo "Running PHPUnit tests..."
if [ -f vendor/bin/phpunit ]; then
    vendor/bin/phpunit
else
    echo "PHPUnit not found at vendor/bin/phpunit. Skipping."
fi

echo "VM Setup Script completed successfully."
