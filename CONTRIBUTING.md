# Contributing to AuditStash Plugin

Thank you for considering contributing to the AuditStash plugin! We welcome contributions from the community.

## How to Contribute

1. **Fork the repository** on GitHub
2. **Create a feature branch** from `master` for your changes
3. **Add tests** for any new functionality
4. **Ensure all tests pass** by running `composer test`
5. **Follow coding standards** by running `composer cs-check` (and `composer cs-fix` to auto-fix)
6. **Run static analysis** with `composer stan` to ensure code quality
7. **Submit a pull request** with a clear description of your changes

## Development Setup

```bash
composer install
bin/cake migrations migrate -p AuditStash
```

## Running Tests

```bash
composer test          # Run all tests
composer cs-check      # Check coding standards
composer cs-fix        # Auto-fix coding standards
composer stan          # Run PHPStan static analysis
```

## Coding Standards

This project follows the PSR2R coding standards. Please ensure your code complies with these standards before submitting a pull request.

## Pull Request Guidelines

- Write clear, descriptive commit messages
- Keep pull requests focused on a single feature or bug fix
- Update documentation if you're changing functionality
- Add or update tests as needed
- Ensure CI checks pass

## Questions?

If you have questions about contributing, feel free to open an issue for discussion.
