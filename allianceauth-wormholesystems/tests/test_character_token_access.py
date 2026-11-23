"""The character-token route must answer without an Alliance Auth session.

Alliance Auth decorates every plugin URL with login_required and
main_character_required. excluded_views is a set of qualified view names, and
urls_from_apps only passes that set through when the URL app name is already in
APPS_WITH_PUBLIC_VIEWS. These tests drive that same decoration.
"""

import base64
import sys
import types
import unittest
from pathlib import Path

PACKAGE_ROOT = Path(__file__).resolve().parents[1]
if str(PACKAGE_ROOT) not in sys.path:
    sys.path.insert(0, str(PACKAGE_ROOT))


def _stub_url_hook() -> None:
    """UrlHook's real module imports Alliance Auth models. The constructor does not."""
    from django.urls import include, re_path

    services = types.ModuleType("allianceauth.services")
    hooks = types.ModuleType("allianceauth.services.hooks")

    class UrlHook:
        def __init__(self, urls, namespace, base_url, excluded_views=None):
            self.include_pattern = re_path(base_url, include(urls, namespace=namespace))
            self.excluded_views = set(excluded_views or [])

    hooks.UrlHook = UrlHook
    sys.modules["allianceauth.services"] = services
    sys.modules["allianceauth.services.hooks"] = hooks


def _stub_esi() -> None:
    esi = types.ModuleType("esi")
    decorators = types.ModuleType("esi.decorators")

    def token_required(*_args, **_kwargs):
        def decorate(view):
            return view

        return decorate

    decorators.token_required = token_required
    esi.decorators = decorators
    sys.modules["esi"] = esi
    sys.modules["esi.decorators"] = decorators


def _install_token(scopes: list[str]) -> None:
    class Scopes:
        def values_list(self, _name, flat=False):
            return list(scopes)

    class Owner:
        def has_perm(self, perm):
            return perm == "wormholesystems.access_wormholesystems"

    class TokenRow:
        pk = 7
        character_id = 123
        character_owner_hash = "owner-hash"
        access_token = "fresh-access-token"
        expires = None
        scopes = Scopes()
        user = Owner()

        def refresh_from_db(self):
            return None

    class Query:
        def prefetch_related(self, *_args):
            return [TokenRow()]

    class Manager:
        def filter(self, **_kwargs):
            return Query()

    class Token:
        objects = Manager()

    models = types.ModuleType("esi.models")
    models.Token = Token
    sys.modules["esi.models"] = models
    sys.modules["esi"].models = models


class CharacterTokenAccessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        super().setUpClass()
        import django
        from django.conf import settings

        if not settings.configured:
            settings.configure(
                SECRET_KEY="test-secret",
                DEBUG=True,
                ALLOWED_HOSTS=["testserver", "auth.example.com"],
                ROOT_URLCONF="character_token_test_urls",
                APPS_WITH_PUBLIC_VIEWS=[],
                WORMHOLESYSTEMS_URL="https://wormhole.example.com",
                LOGIN_URL="/account/login/",
                DATABASES={
                    "default": {
                        "ENGINE": "django.db.backends.sqlite3",
                        "NAME": ":memory:",
                    }
                },
                INSTALLED_APPS=[
                    "django.contrib.auth",
                    "django.contrib.contenttypes",
                    "django.contrib.sessions",
                    "django.contrib.messages",
                ],
                MIDDLEWARE=[
                    "django.contrib.sessions.middleware.SessionMiddleware",
                    "django.middleware.common.CommonMiddleware",
                    "django.middleware.csrf.CsrfViewMiddleware",
                    "django.contrib.auth.middleware.AuthenticationMiddleware",
                    "django.contrib.messages.middleware.MessageMiddleware",
                ],
                TEMPLATES=[{
                    "BACKEND": "django.template.backends.django.DjangoTemplates",
                    "APP_DIRS": True,
                }],
                USE_TZ=True,
            )
            django.setup()

        _stub_esi()
        _stub_url_hook()

        from allianceauth.authentication.decorators import (
            decorate_url_patterns,
            main_character_required,
        )
        from allianceauth_wormholesystems.auth_hooks import register_url
        from allianceauth_wormholesystems.public_views import CHARACTER_TOKEN_VIEW
        from allianceauth_wormholesystems.views import WORMHOLESYSTEMS_SCOPES

        cls.decorate_url_patterns = staticmethod(decorate_url_patterns)
        cls.main_character_required = staticmethod(main_character_required)
        cls.character_token_view = CHARACTER_TOKEN_VIEW
        cls.hook = register_url()
        _install_token(list(WORMHOLESYSTEMS_SCOPES))

    def _client(self, excluded_views, enforce_csrf_checks):
        from django.test import Client
        from django.urls import clear_url_caches, path

        module = types.ModuleType("character_token_test_urls")
        module.urlpatterns = [
            path(
                "",
                self.decorate_url_patterns(
                    [self.hook.include_pattern],
                    self.main_character_required,
                    excluded_views,
                ),
            )
        ]
        sys.modules["character_token_test_urls"] = module
        clear_url_caches()
        return Client(enforce_csrf_checks=enforce_csrf_checks)

    def _post(self, client, secret):
        token = base64.b64encode(f"client:{secret}".encode()).decode()
        return client.post(
            "/wormholesystems/api/character-token/",
            data='{"character_id": 123}',
            content_type="application/json",
            HTTP_AUTHORIZATION=f"Basic {token}",
        )

    def test_hook_excludes_the_qualified_view_name(self):
        from django.conf import settings

        self.assertEqual(self.hook.include_pattern.app_name, "wormholesystems")
        self.assertIn("wormholesystems", settings.APPS_WITH_PUBLIC_VIEWS)
        self.assertEqual(self.hook.excluded_views, {self.character_token_view})

    def test_valid_basic_auth_returns_token_json_without_a_session(self):
        from unittest.mock import patch

        client = self._client(self.hook.excluded_views, enforce_csrf_checks=True)

        def application(request):
            expected = "Basic " + base64.b64encode(b"client:secret").decode()
            if request.META.get("HTTP_AUTHORIZATION") == expected:
                return object()
            return None

        with patch(
            "allianceauth_wormholesystems.views._wormies_oauth_application",
            side_effect=application,
        ):
            response = self._post(client, "secret")

        self.assertEqual(response.status_code, 200, response.content)
        body = response.json()
        self.assertEqual(body["access_token"], "fresh-access-token")
        self.assertEqual(body["character_id"], 123)
        self.assertEqual(body["expires_in"], 1200)
        self.assertNotIn("refresh_token", body)
        self.assertFalse(response.has_header("Location"))

    def test_invalid_basic_auth_is_401_not_a_login_redirect(self):
        from unittest.mock import patch

        client = self._client(self.hook.excluded_views, enforce_csrf_checks=True)
        with patch(
            "allianceauth_wormholesystems.views._wormies_oauth_application",
            return_value=None,
        ):
            response = self._post(client, "wrong")

        self.assertEqual(response.status_code, 401, response.content)
        self.assertNotEqual(response.status_code, 302)
        self.assertFalse(response.has_header("Location"))
        self.assertEqual(response.json()["error"], "unauthorized")

    def test_without_the_exclusion_the_same_post_is_a_login_redirect(self):
        client = self._client(None, enforce_csrf_checks=False)
        response = self._post(client, "secret")

        self.assertEqual(response.status_code, 302, response.content)
        self.assertIn("/account/login/", response["Location"])
        self.assertIn("next=/wormholesystems/api/character-token/", response["Location"])


if __name__ == "__main__":
    unittest.main()
