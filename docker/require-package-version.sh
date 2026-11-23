#!/bin/sh
# A v* tag is the release version. Check that it is semver and that the
# tagged commit is on origin/main, then stamp the fallback used when git
# metadata is absent. pip install of the tag reads the tag via setuptools-scm.
# Non-tag builds do not run this script and keep the committed fallback.
set -eu

tag="${1:-}"
root="${2:-}"
if [ -z "$tag" ]; then
    echo "usage: require-package-version.sh vX.Y.Z [root]" >&2
    exit 1
fi

case "$tag" in
    v*) version="${tag#v}" ;;
    *)
        echo "tag ${tag} must start with v" >&2
        exit 1
        ;;
esac

if ! printf '%s\n' "$version" | grep -Eq '^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$'; then
    echo "tag ${tag} is not valid semver" >&2
    exit 1
fi

if [ -z "$root" ]; then
    root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
fi

init="${root}/allianceauth-wormholesystems/allianceauth_wormholesystems/__init__.py"
if [ ! -f "$init" ]; then
    echo "could not read the version fallback from ${init}" >&2
    exit 1
fi

if git -C "$root" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    if ! git -C "$root" rev-parse --verify origin/main >/dev/null 2>&1; then
        echo "origin/main is not available; cannot confirm ${tag} is on main" >&2
        exit 1
    fi
    if ! git -C "$root" merge-base --is-ancestor HEAD origin/main; then
        echo "tag ${tag} does not point at a commit on main" >&2
        exit 1
    fi
fi

stamp_assignment() {
    file="$1"
    key="$2"
    if [ ! -f "$file" ]; then
        return 0
    fi
    tmp=$(mktemp)
    sed "s/^${key} *= *\"[^\"]*\"/${key} = \"${version}\"/" "$file" > "$tmp"
    if ! grep -Fxq "${key} = \"${version}\"" "$tmp"; then
        rm -f "$tmp"
        echo "could not write ${key} in ${file}" >&2
        exit 1
    fi
    mv "$tmp" "$file"
}

stamp_assignment "$init" "_FALLBACK_VERSION"
stamp_assignment "${root}/pyproject.toml" "fallback_version"
stamp_assignment "${root}/allianceauth-wormholesystems/pyproject.toml" "fallback_version"
echo "stamped version fallback ${version} from tag ${tag}"
