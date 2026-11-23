"""Let one machine route skip Alliance Auth's login decoration."""

from django.conf import settings

# urls.app_name. allianceauth.urls compares this, not the Python package name.
PUBLIC_APP_NAME = "wormholesystems"

# pattern.lookup_str. A scheme or path does not match.
CHARACTER_TOKEN_VIEW = "allianceauth_wormholesystems.views.character_token"


def allow_machine_endpoint() -> None:
    """Alliance Auth ignores excluded_views unless this name is already allowed.

    urls_from_apps copies APPS_WITH_PUBLIC_VIEWS before it calls url hooks, so
    this has to run when auth_hooks is imported, not inside the hook itself.
    """
    configured = getattr(settings, "APPS_WITH_PUBLIC_VIEWS", [])
    if isinstance(configured, str):
        names = [configured]
    else:
        names = list(configured or [])

    if PUBLIC_APP_NAME in names:
        return

    names.append(PUBLIC_APP_NAME)
    settings.APPS_WITH_PUBLIC_VIEWS = names
