#!/usr/bin/env bash
# Smoke test for the example application: installs the dependencies (the bundle comes from
# this repository through a Composer path repository), runs the demo and the bundle's
# diagnostic commands. Any failing step fails the script.
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

echo "==> php bin/console app:demo"
php bin/console app:demo

echo "==> php bin/console somework:cqrs:list"
php bin/console somework:cqrs:list

echo "==> php bin/console somework:cqrs:health"
php bin/console somework:cqrs:health
