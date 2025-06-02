#!/bin/bash
set -e # Exit immediately if a command exits with a non-zero status.

echo "Running linters and tests..."

echo "Running PHP CodeSniffer (phpcs)..."
if [ -f vendor/bin/phpcs ]; then
    vendor/bin/phpcs -s src/ tests/ > test_outputs/phpcs_output.txt 2>&1
    echo "PHPCS Output:"
    cat test_outputs/phpcs_output.txt
else
    echo "PHP CodeSniffer not found at vendor/bin/phpcs. Skipping."
fi

echo "Running PHPStan..."
if [ -f vendor/bin/phpstan ]; then
    if [ -f phpstan.neon ] || [ -f phpstan.neon.dist ]; then
        vendor/bin/phpstan analyse --memory-limit=2G > test_outputs/phpstan_output.txt 2>&1
    else
        # Default to level 5 if no config file. Adjust as needed.
        vendor/bin/phpstan analyse src/ tests/ --level=5 --memory-limit=2G > test_outputs/phpstan_output.txt 2>&1
    fi
    echo "PHPStan Output:"
    cat test_outputs/phpstan_output.txt
else
    echo "PHPStan not found at vendor/bin/phpstan. Skipping."
fi

echo "Running PHPUnit tests..."
if [ -f vendor/bin/phpunit ]; then
    XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-clover test_outputs/coverage/clover.xml --coverage-html test_outputs/coverage/html > test_outputs/phpunit_output.txt 2>&1
    echo "PHPUnit Output:"
    cat test_outputs/phpunit_output.txt
else
    echo "PHPUnit not found at vendor/bin/phpunit. Skipping."
fi

echo "Linters and tests completed."
