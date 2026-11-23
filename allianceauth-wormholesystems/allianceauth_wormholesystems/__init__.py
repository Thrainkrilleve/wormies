"""Wormhole Systems integration for Alliance Auth."""

from importlib.metadata import PackageNotFoundError, version

default_app_config = "allianceauth_wormholesystems.apps.WormholeSystemsConfig"

# Checkout without an installed distribution. A v* tag publish stamps this
# fallback. pip install of a tag uses the tag recorded in package metadata.
_FALLBACK_VERSION = "0.0.21"

try:
    __version__ = version("allianceauth-wormholesystems")
except PackageNotFoundError:
    __version__ = _FALLBACK_VERSION

__title__ = "Wormhole Systems"
