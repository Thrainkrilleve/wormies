# Releasing

Pushing a `v*` tag is the release. The tag is the version. Austin pushes tags. An agent does not create or move them.

`pip install git+https://github.com/Thrainkrilleve/wormies.git@vX.Y.Z` reports `X.Y.Z`. setuptools-scm reads that tag while the package is built, and `__version__` comes from the installed metadata (`importlib.metadata`). A checkout that is not installed, and a build with no git metadata, uses the committed fallback `0.0.21`.

The publish workflow accepts the tag only when it is semver (`vX.Y.Z`) and the tagged commit is on `main`. It then stamps that fallback, in `__init__.py` and in both `pyproject.toml` files, before the image is built. A push to `main` that is not a tag, and a manual run, leave the committed fallback as it is.

1. Merge the changes to `main`.
2. Tag that commit `vX.Y.Z` and push the tag. Do not tag a commit that is not on `main`.
3. Pin Alliance Auth to the tag in `conf/requirements.txt` (`git+https://github.com/Thrainkrilleve/wormies.git@vX.Y.Z`).
4. Rebuild the Alliance Auth image (`docker compose build` for that service) so the pin is installed.
5. Set the Wormhole Systems compose image tag to `vX.Y.Z`.
6. `docker compose pull` and `docker compose up -d`.

An unpinned git install follows the default branch. Its version follows the latest `vX.Y.Z` tag reachable from that commit, not the fallback, when git metadata is present.
