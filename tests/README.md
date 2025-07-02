# Breakdance Static Pages Test Suite

This directory contains comprehensive tests for the Breakdance Static Pages plugin.

## Test Structure

- `unit/` - Unit tests for individual classes and methods
- `integration/` - Integration tests for component interactions
- `fixtures/` - Test data and mock files
- `bootstrap.php` - Test environment setup
- `class-bsp-test-case.php` - Base test case class
- `trait-bsp-test-helpers.php` - Test helper methods

## Running Tests

### Prerequisites

1. WordPress test environment setup
2. PHPUnit installed
3. WordPress test database configured

### Basic Usage

```bash
# Run all tests
phpunit

# Run specific test suite
phpunit --testsuite=unit
phpunit --testsuite=integration

# Run specific test file
phpunit tests/unit/class-security-helper-test.php

# Run with coverage
phpunit --coverage-html coverage/
```

### Environment Variables

Set these environment variables for your test environment:

```bash
export WP_TESTS_DIR="/path/to/wordpress-tests-lib"
export WP_CORE_DIR="/path/to/wordpress"
export WP_TESTS_DOMAIN="example.org"
export WP_TESTS_EMAIL="admin@example.org"
export WP_TESTS_TITLE="Test Blog"
```

## Test Coverage

The test suite covers:

### Unit Tests
- **Security Helper** - All security validation and sanitization methods
- **Static Generator** - File generation, optimization, and management
- **File Lock Manager** - Concurrency control and lock management
- **Error Handler** - Error logging and notification systems
- **Queue Manager** - Background processing and job management
- **Stats Cache** - Performance metrics and caching

### Integration Tests
- **AJAX Handler** - All AJAX endpoints and security
- **Plugin Integration** - Activation, deactivation, and core functionality
- **Admin Interface** - Admin panel functionality
- **URL Rewriter** - Frontend static file serving
- **Health Check** - System monitoring and diagnostics

## Test Data

Tests use:
- Mock WordPress posts and pages
- Simulated HTTP requests for content capture
- Temporary files and directories
- Mock user sessions and permissions
- Fake cron events and schedules

## Best Practices

### Writing Tests
1. Extend `BSP_Test_Case` for all test classes
2. Use the `BSP_Test_Helpers` trait for common assertions
3. Clean up after tests (files, options, meta)
4. Mock external dependencies (HTTP requests, file system)
5. Test both success and failure scenarios

### Test Organization
1. One test class per source class
2. Group related tests in methods
3. Use descriptive test method names
4. Document complex test scenarios
5. Keep tests focused and atomic

### Mocking and Fixtures
1. Use WordPress factories for test data
2. Mock HTTP requests with `mock_http_request()`
3. Create test files in temporary directories
4. Reset singleton instances between tests
5. Clear cron events and scheduled tasks

## Continuous Integration

The test suite is designed to run in CI environments:

```yaml
# Example GitHub Actions workflow
name: Tests
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '7.4'
      - name: Install dependencies
        run: composer install
      - name: Setup WordPress
        run: bash bin/install-wp-tests.sh
      - name: Run tests
        run: phpunit
```

## Debugging Tests

### Common Issues
1. **Database connection errors** - Check WP_TESTS_DIR path
2. **Permission errors** - Ensure test directories are writable
3. **Memory limits** - Increase PHP memory for large test suites
4. **Timeout errors** - Mock external HTTP requests

### Debug Techniques
1. Use `var_dump()` and `error_log()` for debugging
2. Run single tests to isolate issues
3. Check test output with `--verbose` flag
4. Use `--stop-on-failure` to halt on first error

## Contributing

When adding new features:
1. Write tests first (TDD approach)
2. Achieve good test coverage (>80%)
3. Test edge cases and error conditions
4. Update this documentation
5. Ensure all tests pass before submitting

## Performance

Test performance considerations:
- Mock external services to avoid network delays
- Use transactions for database operations when possible
- Clean up test data efficiently
- Avoid unnecessary file system operations
- Use setUp/tearDown methods properly

## Security Testing

Security test coverage includes:
- Input sanitization and validation
- Nonce verification in AJAX requests
- File path traversal prevention
- Capability and permission checks
- SQL injection prevention
- XSS attack prevention