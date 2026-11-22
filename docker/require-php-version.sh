#!/bin/sh
# Fail the image build or CI when PHP is older than the patched release.
set -eu

min="${1:-8.4.28}"

if php -r "exit(version_compare(PHP_VERSION, '${min}', '<') ? 1 : 0);"; then
    exit 0
fi

echo "PHP $(php -r 'echo PHP_VERSION;') is below ${min}" >&2
exit 1
