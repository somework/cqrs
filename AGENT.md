# Agent instructions

Read [CLAUDE.md](CLAUDE.md) for the architecture and `.claude/rules/` for the conventions of each area.

Before you finish a change:

1. Add or update PHPUnit tests for the behaviour you changed (unit tests next to the class, kernel tests in `tests/Functional/` when wiring is involved).
2. Run the checks CI runs and make them pass:
   ```bash
   vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --allow-risky=yes
   vendor/bin/phpstan analyse --configuration=phpstan.neon.dist
   vendor/bin/phpunit
   ```
3. Record user-visible changes in `CHANGELOG.md` under `[Unreleased]`, and behaviour changes in `UPGRADE.md`.
