from django.db import models


class General(models.Model):
    """Meta model for managing Wormhole Systems permissions."""

    class Meta:
        managed = False
        default_permissions = ()
        permissions = (
            ("access_wormholesystems", "Can access Wormhole Systems"),
            ("manage_wormholesystems", "Can manage Wormhole Systems configuration"),
        )
