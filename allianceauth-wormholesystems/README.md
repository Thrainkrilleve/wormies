# Wormhole Systems for Alliance Auth

This app integrates [Wormhole Systems](https://wormhole.systems) into **Alliance Auth**:
- Adds a **Wormhole Systems** item directly in the Alliance Auth sidebar navigation.
- Provides 1-click **Single Sign-On (SSO)** via the Alliance Auth OIDC Provider.
- Enables permission gating so only pilots with `wormholesystems.access_wormholesystems` can see and access the mapper.

---

## Installation into Alliance Auth

### 1. Install the Package
Copy the `allianceauth-wormholesystems` directory into your Alliance Auth environment or install via pip inside your Alliance Auth container:

```bash
pip install -e /path/to/allianceauth-wormholesystems
```

### 2. Configure `local.py`
In your Alliance Auth `local.py` (e.g. `myauth/settings/local.py`):

```python
# Add to INSTALLED_APPS:
INSTALLED_APPS += [
    'allianceauth_wormholesystems',
]

# Configure the Wormhole Systems URL:
WORMHOLESYSTEMS_URL = "https://wormhole.r3v-w.space"

# Optional customizations:
# WORMHOLESYSTEMS_MENU_TEXT = "Wormhole Systems"
# WORMHOLESYSTEMS_MENU_ICON = "fas fa-compass"
```

### 3. Run Migrations
Run the migrations to create the app permissions:

```bash
python manage.py migrate
```

### 4. Create the OIDC Application in Alliance Auth Admin
Go to Alliance Auth Admin (`https://auth.yourdomain.com/admin/`):
1. Navigate to **AllianceAuth OIDC** > **Applications** (or **Django OAuth Toolkit** > **Applications**).
2. Click **Add Application**:
   - **Name:** `Wormhole Systems`
   - **Client Type:** `Confidential`
   - **Authorization Grant Type:** `Authorization code`
   - **Redirect URIs:** `https://wormhole.r3v-w.space/auth/allianceauth/callback`
   - **Algorithm:** `RS256` (or `HS256` depending on your OIDC setup)
3. Copy the generated **Client ID** and **Client Secret**. You will set these in Wormhole Systems `.env`:
   - `ALLIANCEAUTH_CLIENT_ID`
   - `ALLIANCEAUTH_CLIENT_SECRET`

### 5. Grant Permissions to Users / Groups
In Alliance Auth Admin:
- Go to **Authentication and Authorization** > **Groups** (or **States**).
- Grant the permission `wormholesystems | General | Can access Wormhole Systems` to the appropriate groups or member states (e.g. `Member`, `Wormhole Division`).
- Pilots with this permission will see the "Wormhole Systems" link in their sidebar and can seamlessly launch the mapper.
