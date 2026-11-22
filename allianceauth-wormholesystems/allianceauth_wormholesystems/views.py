import base64
import json
import logging

from django.contrib.auth.decorators import login_required, permission_required
from django.contrib.auth.hashers import check_password, identify_hasher
from django.http import JsonResponse, HttpResponseRedirect
from django.shortcuts import redirect
from django.utils import timezone
from django.views.decorators.csrf import csrf_exempt
from django.views.decorators.http import require_POST
from hmac import compare_digest

from esi.decorators import token_required

from . import app_settings

logger = logging.getLogger(__name__)

WORMHOLESYSTEMS_SCOPES = [
    "publicData",
    "esi-location.read_location.v1",
    "esi-location.read_ship_type.v1",
    "esi-location.read_online.v1",
    "esi-ui.write_waypoint.v1",
]


@login_required
@permission_required("wormholesystems.access_wormholesystems", raise_exception=True)
@token_required(scopes=WORMHOLESYSTEMS_SCOPES)
def launch(request, token):
    """
    Redirects the authenticated pilot directly to Wormhole Systems OAuth endpoint.
    Guarantees that the pilot has granted all required ESI tracking scopes before launching.
    """
    # add_to_account=1 forces Wormhole Systems to run its OIDC round-trip even when
    # the pilot already has an active session there. Without it, AllianceAuthController::redirect()
    # short-circuits straight to the dashboard and never re-syncs the newly granted ESI scopes.
    target_url = f"{app_settings.WORMHOLESYSTEMS_URL.rstrip('/')}/auth/allianceauth?add_to_account=1"
    return HttpResponseRedirect(target_url)


@login_required
@permission_required("wormholesystems.access_wormholesystems", raise_exception=True)
def user_info_api(request):
    """
    Optional helper API endpoint providing the current pilot's main character,
    character affiliations, and assigned groups.
    """
    user = request.user
    profile = getattr(user, "profile", None)
    main_char = getattr(profile, "main_character", None)

    groups = list(user.groups.values_list("name", flat=True))
    if profile and getattr(profile, "state", None):
        groups.append(profile.state.name)

    data = {
        "user_id": user.pk,
        "username": user.username,
        "main_character": {
            "character_id": main_char.character_id if main_char else None,
            "character_name": main_char.character_name if main_char else None,
            "corporation_id": main_char.corporation_id if main_char else None,
            "corporation_name": main_char.corporation_name if main_char else None,
            "alliance_id": main_char.alliance_id if main_char else None,
            "alliance_name": main_char.alliance_name if main_char else None,
        } if main_char else None,
        "groups": groups,
    }
    return JsonResponse(data)


def _wormies_oauth_application(request):
    """The confidential OAuth client registered for this Wormhole Systems install."""
    header = request.META.get("HTTP_AUTHORIZATION", "")
    if not header.startswith("Basic "):
        return None

    try:
        decoded = base64.b64decode(header.split(" ", 1)[1].strip()).decode("utf-8")
        client_id, client_secret = decoded.split(":", 1)
    except (ValueError, UnicodeDecodeError):
        return None

    try:
        from oauth2_provider.models import get_application_model
    except ImportError:
        logger.warning("oauth2_provider is not installed; refusing a character token request")
        return None

    Application = get_application_model()
    application = Application.objects.filter(client_id=client_id).first()
    if application is None or not _client_secret_matches(application.client_secret or "", client_secret):
        return None

    base = app_settings.WORMHOLESYSTEMS_URL or ""
    callback = f"{base.rstrip('/')}/auth/allianceauth/callback"
    if hasattr(application, "redirect_uri_allowed"):
        allowed = application.redirect_uri_allowed(callback)
    else:
        allowed = callback in (getattr(application, "redirect_uris", "") or "")

    if not allowed:
        return None

    return application


def _client_secret_matches(stored, provided):
    if not stored or not provided:
        return False

    try:
        identify_hasher(stored)
    except ValueError:
        if len(stored) != len(provided):
            return False
        return compare_digest(stored, provided)

    return check_password(provided, stored)


def _request_json(request):
    if not request.body:
        return request.POST

    try:
        payload = json.loads(request.body.decode("utf-8"))
    except (UnicodeDecodeError, json.JSONDecodeError):
        return request.POST

    return payload if isinstance(payload, dict) else request.POST


def _fresh_access_token(token):
    """Ask django-esi for a current access token, refreshing with AA's ESI app."""
    if hasattr(token, "valid_access_token"):
        return token.valid_access_token()

    expired = getattr(token, "expired", None)
    if expired is None and getattr(token, "expires", None) is not None:
        expired = token.expires <= timezone.now()

    if expired and hasattr(token, "refresh"):
        token.refresh()

    return token.access_token


@csrf_exempt
@require_POST
def character_token(request):
    """
    Current EVE access token for a character owned by a Wormhole Systems user.

    Authenticated with the Wormhole Systems OAuth client (HTTP Basic). django-esi
    refreshes the token with Alliance Auth's ESI application. The refresh token
    is not included.
    """
    if _wormies_oauth_application(request) is None:
        return JsonResponse({"error": "unauthorized"}, status=401)

    payload = _request_json(request)
    character_id = payload.get("character_id")
    try:
        character_id = int(character_id)
    except (TypeError, ValueError):
        return JsonResponse({"error": "character_id is required"}, status=422)

    from esi.models import Token

    candidates = []
    tokens = Token.objects.filter(character_id=character_id).prefetch_related("scopes", "user")
    for token in tokens:
        user = getattr(token, "user", None)
        if user is None or not user.has_perm("wormholesystems.access_wormholesystems"):
            continue

        scope_names = set(token.scopes.values_list("name", flat=True))
        if not _WANTED_SCOPES.issubset(scope_names):
            continue

        candidates.append(token)

    if not candidates:
        return JsonResponse({"error": "no_token"}, status=404)

    token = max(candidates, key=lambda candidate: candidate.pk)

    try:
        access_token = _fresh_access_token(token)
    except Exception as exc:
        logger.warning(
            "Could not refresh the ESI token for character %s (%s)",
            character_id,
            type(exc).__name__,
        )
        return JsonResponse({"error": "refresh_failed"}, status=502)

    if not access_token:
        return JsonResponse({"error": "refresh_failed"}, status=502)

    token.refresh_from_db()
    expires = getattr(token, "expires", None)
    if expires is not None:
        expires_in = max(0, int((expires - timezone.now()).total_seconds()))
    else:
        expires_in = 1200

    return JsonResponse({
        "character_id": token.character_id,
        "character_owner_hash": token.character_owner_hash,
        "access_token": access_token,
        "expires_in": expires_in,
        "scopes": list(token.scopes.values_list("name", flat=True)),
    })
