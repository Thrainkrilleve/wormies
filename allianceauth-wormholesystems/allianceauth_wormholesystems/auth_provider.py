from allianceauth_oidc.auth_provider import AllianceAuthOAuth2Validator


class WormholeSystemsOAuth2Validator(AllianceAuthOAuth2Validator):
    oidc_claim_scope = dict(AllianceAuthOAuth2Validator.oidc_claim_scope)
    oidc_claim_scope.update({
        "character_id": "profile",
        "character_name": "profile",
        "characters": "profile",
        "discord": "profile",
    })

    def get_additional_claims(self):
        claims = super().get_additional_claims()

        def get_main_character_id(request):
            profile = getattr(request.user, "profile", None)
            char = getattr(profile, "main_character", None)
            return char.character_id if char else None

        def get_main_character_name(request):
            profile = getattr(request.user, "profile", None)
            char = getattr(profile, "main_character", None)
            return char.character_name if char else None

        def get_characters(request):
            user = getattr(request, "user", None)
            if not user or not user.is_authenticated:
                return []
            chars_by_id = {}
            for token in user.token_set.prefetch_related("scopes").order_by("-created"):
                cid = token.character_id
                scopes = list(token.scopes.values_list("name", flat=True))
                if cid not in chars_by_id or len(scopes) > len(chars_by_id[cid]["scopes"]):
                    chars_by_id[cid] = {
                        "character_id": token.character_id,
                        "character_name": token.character_name,
                        "character_owner_hash": token.character_owner_hash,
                        "access_token": token.access_token,
                        "refresh_token": token.refresh_token,
                        "expires_in": 1200,
                        "scopes": scopes,
                    }
            return list(chars_by_id.values())

        def get_discord_account(request):
            user = getattr(request, "user", None)
            if not user or not user.is_authenticated:
                return None

            # 1. Try built-in Alliance Auth Discord service module
            try:
                from allianceauth.services.modules.discord.models import DiscordUser
                du = DiscordUser.objects.filter(user=user).first()
                if du and getattr(du, "uid", None):
                    return {
                        "id": str(du.uid),
                        "username": getattr(du, "username", "") or str(du.uid),
                        "display_name": getattr(du, "nickname", None) or getattr(du, "username", None),
                        "avatar": getattr(du, "avatar", None),
                    }
            except Exception:
                pass

            # 2. Try community aadiscordbot
            try:
                from aadiscordbot.models import DiscordUser as AADiscordUser
                adu = AADiscordUser.objects.filter(user=user).first()
                if adu and getattr(adu, "uid", None):
                    return {
                        "id": str(adu.uid),
                        "username": getattr(adu, "username", "") or str(adu.uid),
                        "display_name": getattr(adu, "nickname", None) or getattr(adu, "username", None),
                        "avatar": getattr(adu, "avatar", None),
                    }
            except Exception:
                pass

            return None

        claims["character_id"] = get_main_character_id
        claims["character_name"] = get_main_character_name
        claims["characters"] = get_characters
        claims["discord"] = get_discord_account
        return claims
