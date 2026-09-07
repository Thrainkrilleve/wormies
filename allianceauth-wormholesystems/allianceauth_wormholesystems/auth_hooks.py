from allianceauth import hooks
from allianceauth.menu.hooks import MenuItemHook
from allianceauth.services.hooks import UrlHook

from . import app_settings, urls


class WormholeSystemsMenuItem(MenuItemHook):
    """Auth Hook for rendering a side menu item in Alliance Auth."""

    def __init__(self):
        super().__init__(
            text=app_settings.WORMHOLESYSTEMS_MENU_TEXT,
            classes=app_settings.WORMHOLESYSTEMS_MENU_ICON,
            url_name="wormholesystems:launch",
            order=app_settings.WORMHOLESYSTEMS_MENU_ORDER,
            navactive=["wormholesystems:"],
        )

    def render(self, request):
        if request.user.has_perm("wormholesystems.access_wormholesystems"):
            return super().render(request)
        return ""


@hooks.register("menu_item_hook")
def register_menu_item():
    return WormholeSystemsMenuItem()


@hooks.register("url_hook")
def register_url():
    return UrlHook(urls, "wormholesystems", r"^wormholesystems/")
