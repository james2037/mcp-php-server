# Jules Environment Setup and Testing

This document outlines how to set up the necessary environment and run tests for this project within the Jules AI assistant.

## Initial Setup

The `jules_setup.sh` script is responsible for preparing the VM environment. It performs the following key actions:
1.  Installs necessary Ubuntu packages (`software-properties-common`, `python3-apt`).
2.  Adds the `ppa:ondrej/php` PPA to provide various PHP versions.
3.  Installs PHP 8.1 and required extensions (`php8.1-cli`, `php8.1-common`, `php8.1-curl`, `php8.1-mbstring`, `php8.1-xml`, `php8.1-zip`, `unzip`).
4.  Installs Composer (PHP dependency manager).
5.  Installs project dependencies using `composer install` (including dev dependencies).

**To configure Jules to use this setup script:**
1.  In the Jules UI for this repository, navigate to the "Configuration" section.
2.  In the "Initial Setup" (or similarly named) text area, enter the following command:
    ```bash
    bash jules_setup.sh
    ```
3.  Save the configuration. Jules will now run this script each time it prepares a VM for a task.

## Running Linters and Tests

The `run_tests.sh` script is used to execute the project's linters and automated tests. It performs the following actions:
1.  Runs PHP_CodeSniffer (`phpcs`) on the `src/` and `tests/` directories to check coding standards.
2.  Runs PHPStan on the `src/` and `tests/` directories for static analysis (defaulting to level 5 if no `phpstan.neon` config file is found).
3.  Runs PHPUnit to execute the test suite.

**To instruct Jules to run tests (e.g., after making changes):**
You can ask Jules to execute this script by referring to it, for example:
"Please run the tests using `bash run_tests.sh`."
Or, if Jules offers a specific interface or command for running tests that can be configured, this script would be the target.

```
