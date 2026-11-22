# Releasing the Alliance Auth package

Alliance Auth shows the installed distribution version. That value is `__version__` in `allianceauth-wormholesystems/allianceauth_wormholesystems/__init__.py`. Both `setup.py` files read it. Do not write a second version string.

1. Set `__version__` to the release, for example `0.0.15`. Update the expected version in `tests/Unit/PackageVersionTest.php` to the same string.
2. Merge that commit to `main`.
3. Tag that merge commit `v` plus the same version (`v0.0.15`) and push the tag. Do not push the tag before the version bump is on the commit you tag.

A pushed `v*` tag runs the image publish workflow. The workflow fails when the tag, without the leading `v`, is not exactly `__version__` on that commit.

After the tag exists, pin the Alliance Auth install to it (`@v0.0.15` on the existing git URL). An unpinned git install follows the default branch and can move without a version change in the environment.
