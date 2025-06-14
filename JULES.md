# Jules Quick Start Guide

This guide is for you, Jules, to get up and running quickly in a new session. For a comprehensive understanding of the project, including its features, overall architecture, and how to create custom resources and tools, please refer to the main `README.md` file.

## Initial Setup

**Important: At the beginning of each new work session, you *must* run the setup script first.** This ensures the environment is correctly initialized.

To prepare the environment, execute:

```bash
bash jules_setup.sh
```

This script installs necessary PHP versions, Composer, project dependencies, and Xdebug (to enable code coverage generation). Running this at the start of your session will prevent common issues.

## Development Workflow

During development, it's often more efficient to run linters and tests individually to get targeted feedback.

### PHP_CodeSniffer (Coding Standards)

To check for coding standard violations in `src/` and `tests/`:

```bash
vendor/bin/phpcs src/ tests/
```
*(Note: A `phpcs.xml` file exists and will be used automatically.)*

### PHPStan (Static Analysis)

To perform static analysis on `src/` and `tests/` (defaulting to level 5):

```bash
vendor/bin/phpstan analyse src/ tests/ --level=5 --memory-limit=2G
```

### PHPUnit (Unit Tests)

To run the unit test suite:

```bash
vendor/bin/phpunit
```
*(Note: This will use the `phpunit.xml.dist` configuration. If you need code coverage reports when running manually like this, ensure Xdebug is enabled in your PHP CLI configuration. However, for most cases, using `bash run_tests.sh` is recommended as it handles this and provides a consolidated output.)*

### Unit Testing Obligation

**Crucially, any new feature development, bug fix, or refactoring work you perform *must* be accompanied by new or updated unit tests.** Ensure that your tests thoroughly cover the changes made and that all tests pass before considering a task complete. This is a non-negotiable aspect of maintaining code quality and stability, unless an exception is explicitly discussed and agreed upon with the developer for specific cases where a unit test may not be practical or beneficial. In such scenarios, you should seek confirmation before proceeding without a test.

## Pre-Commit Check

Before committing any code changes, always run the full test suite to ensure everything is passing:

```bash
bash run_tests.sh
```

This script runs PHPCS, PHPStan, and PHPUnit.
*   It will now generate code coverage reports in `test_outputs/coverage/html` (viewable in a browser) and `test_outputs/coverage/clover.xml`.
*   Crucially, the script is configured to exit immediately with an error if PHPCS, PHPStan, or PHPUnit report any issues or test failures.

Make sure all checks pass and review coverage if applicable before submitting.
