#!/bin/sh
# Fail a v* tag push unless the tag is v plus __version__.
set -eu

tag="${1:-}"
if [ -z "$tag" ]; then
    echo "usage: require-package-version.sh vX.Y.Z" >&2
    exit 1
fi

case "$tag" in
    v*) version="${tag#v}" ;;
    *)
        echo "tag ${tag} must start with v" >&2
        exit 1
        ;;
esac

root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
init="${root}/allianceauth-wormholesystems/allianceauth_wormholesystems/__init__.py"
pkg=$(sed -n 's/^__version__ *= *"\([^"]*\)".*/\1/p' "$init")
if [ -z "$pkg" ]; then
    echo "could not read __version__ from ${init}" >&2
    exit 1
fi

if [ "$pkg" != "$version" ]; then
    echo "tag ${tag} does not match package version ${pkg}" >&2
    exit 1
fi

echo "tag ${tag} matches package version ${pkg}"
