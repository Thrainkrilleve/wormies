from django.apps import AppConfig


class WormholeSystemsConfig(AppConfig):
    name = "allianceauth_wormholesystems"
    label = "wormholesystems"
    verbose_name = "Wormhole Systems"

    def ready(self):
        from .public_views import allow_machine_endpoint

        allow_machine_endpoint()
