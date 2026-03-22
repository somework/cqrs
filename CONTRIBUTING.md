# Contributing

Thank you for considering contributing to the CQRS Bundle! This guide will help you get started.

## Prerequisites

- **PHP 8.2+**
- **Composer 2.x**
- **Symfony 7.2+** (installed as a dependency via Composer)

## Setup

```bash
git clone https://github.com/somework/cqrs.git
cd cqrs
composer install
```

## Quality Checks

The project provides Composer scripts for all quality checks:

```bash
# Run all checks (code style + static analysis + tests)
composer test

# Code style check (dry-run)
composer cs-check

# Code style fix
composer fix

# Static analysis (PHPStan)
composer phpstan

# Unit and integration tests (PHPUnit)
composer phpunit
```

All three checks must pass before submitting a pull request.

## Coding Standards

- **Code style:** PSR-12 via [PHP-CS-Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer). Run `composer fix` to auto-format.
- **Static analysis:** PHPStan level 8. Run `composer phpstan` to check.
- **Messages:** Commands, queries, and events are immutable DTOs -- `final class` with `public readonly` properties and no methods beyond the constructor.
- **Tests:** Mirror the `src/` directory structure under `tests/`. Fixtures live in `tests/Fixture/`.

## Pull Request Process

1. **Fork** the repository and create a feature branch from `main`.
2. **Write tests first** (TDD) -- the test should fail before you write the implementation.
3. **Implement** the minimum code to make the tests pass.
4. **Run all checks** with `composer test` and ensure they pass.
5. **Submit a PR** against `main` with a clear description of the change.

## Commit Messages

Follow the [Conventional Commits](https://www.conventionalcommits.org/) format:

- `feat:` -- new feature or functionality
- `fix:` -- bug fix
- `refactor:` -- code change that neither fixes a bug nor adds a feature
- `docs:` -- documentation only
- `test:` -- adding or updating tests
- `chore:` -- maintenance, dependencies, CI configuration

## Reporting Issues

- **Bugs:** Use the [Bug Report](https://github.com/somework/cqrs/issues/new?template=bug_report.yml) template.
- **Feature requests:** Use the [Feature Request](https://github.com/somework/cqrs/issues/new?template=feature_request.yml) template.
- **Security vulnerabilities:** See [SECURITY.md](SECURITY.md) for responsible disclosure instructions.
