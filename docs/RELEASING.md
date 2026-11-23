# Releasing

Pushing a `v*` tag is the release. The tag is the version. Austin pushes tags. An agent does not create or move them.

The publish workflow accepts the tag only when it is semver (`vX.Y.Z`) and the tagged commit is on `main`. It then writes that version, without the leading `v`, into `__version__` in `allianceauth-wormholesystems/allianceauth_wormholesystems/__init__.py` before the image is built. Both `setup.py` files read that line. A push to `main` that is not a tag, and a manual run, leave the committed version as it is.

1. Merge the changes to `main`. The committed `__version__` for this release is `0.0.21`.
2. Tag that commit `v0.0.21` and push the tag. Do not tag a commit that is not on `main`.
3. Pin Alliance Auth to the tag in `conf/requirements.txt` (`git+https://github.com/Thrainkrilleve/wormies.git@v0.0.21`).
4. Rebuild the Alliance Auth image (`docker compose build` for that service) so the pin is installed.
5. Set the Wormhole Systems compose image tag to `v0.0.21`.
6. `docker compose pull` and `docker compose up -d`.

An unpinned git install follows the default branch and can move without a published tag.
