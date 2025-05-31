# Jules Quick Start Guide

This guide is for you, Jules, to get up and running quickly in a new session. For a comprehensive understanding of the project, including its features, overall architecture, and how to create custom resources and tools, please refer to the main `README.md` file.

## Initial Setup

Before starting any work, ensure the environment is prepared by running:

```bash
bash jules_setup.sh
```

This script installs necessary PHP versions, Composer, and project dependencies. You only need to ensure it's run once per session.

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
*(Note: This will use the `phpunit.xml.dist` configuration.)*

## Pre-Commit Check

Before committing any code changes, always run the full test suite to ensure everything is passing:

```bash
bash run_tests.sh
```

This script runs PHPcs, PHPStan, and PHPUnit. Make sure all tests pass before submitting.
