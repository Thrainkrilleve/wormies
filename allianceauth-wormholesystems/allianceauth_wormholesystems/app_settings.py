from django.conf import settings

# Base URL of the Wormhole Systems deployment
WORMHOLESYSTEMS_URL = getattr(
    settings,
    "WORMHOLESYSTEMS_URL",
    "https://wormhole.r3v-w.space",
)

# Icon class for the sidebar menu item (FontAwesome)
WORMHOLESYSTEMS_MENU_ICON = getattr(
    settings,
    "WORMHOLESYSTEMS_MENU_ICON",
    "fas fa-compass",
)

# Text displayed in the sidebar menu item
WORMHOLESYSTEMS_MENU_TEXT = getattr(
    settings,
    "WORMHOLESYSTEMS_MENU_TEXT",
    "Wormhole Systems",
)

# Order in the sidebar menu (default 1050 for community apps)
WORMHOLESYSTEMS_MENU_ORDER = getattr(
    settings,
    "WORMHOLESYSTEMS_MENU_ORDER",
    1050,
)
