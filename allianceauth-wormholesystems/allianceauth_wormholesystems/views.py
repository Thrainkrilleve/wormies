from django.contrib.auth.decorators import login_required, permission_required
from django.http import JsonResponse, HttpResponseRedirect
from django.shortcuts import redirect

from esi.decorators import token_required

from . import app_settings

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
    target_url = f"{app_settings.WORMHOLESYSTEMS_URL.rstrip('/')}/auth/allianceauth"
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
