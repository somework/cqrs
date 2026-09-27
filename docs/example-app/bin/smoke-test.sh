#!/usr/bin/env bash
# Smoke test for the example application: installs the dependencies (the bundle comes from
# this repository through a Composer path repository), creates a fresh SQLite database, runs the
# demo, relays the events it stored in the outbox, checks that their handlers ran, and runs the
# bundle's diagnostic commands. Any failing step fails the script.
#
# Usage: bin/smoke-test.sh [extra "composer install" arguments]
#   e.g. bin/smoke-test.sh --prefer-source
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

install_args=(--no-interaction --no-progress)
case " $* " in
    *" --prefer-source "* | *" --prefer-dist "* | *" --prefer-install"*) ;;
    *) install_args+=(--prefer-dist) ;;
esac

echo "==> composer install ${install_args[*]} $*"
composer install "${install_args[@]}" "$@"

echo "==> fresh database: php bin/console doctrine:schema:create"
rm -f var/data.db
php bin/console doctrine:schema:create

echo "==> php bin/console somework:cqrs:outbox:setup"
php bin/console somework:cqrs:outbox:setup

echo "==> php bin/console app:demo"
php bin/console app:demo

echo "==> php bin/console somework:cqrs:outbox:relay"
relay=$(php bin/console somework:cqrs:outbox:relay)
echo "$relay"
grep -q 'Relayed 3 message(s)' <<<"$relay"

echo "==> php bin/console app:activity"
activity=$(php bin/console app:activity)
echo "$activity"
grep -q 'TaskCreated handled: "Write the documentation" (task-1)' <<<"$activity"
grep -q 'TaskCompleted handled: task-1' <<<"$activity"

echo "==> php bin/console somework:cqrs:outbox:relay (nothing left)"
php bin/console somework:cqrs:outbox:relay | grep -q 'No outbox messages are due'

echo "==> php bin/console somework:cqrs:list"
php bin/console somework:cqrs:list

echo "==> php bin/console somework:cqrs:health"
php bin/console somework:cqrs:health
