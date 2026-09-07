# Wormhole Systems for Alliance Auth

This app integrates [Wormhole Systems](https://wormhole.systems) into **Alliance Auth**:
- Adds a **Wormhole Systems** item directly in the Alliance Auth sidebar navigation.
- Provides 1-click **Single Sign-On (SSO)** via the Alliance Auth OIDC Provider.
- Enables permission gating so only pilots with `wormholesystems.access_wormholesystems` can see and access the mapper.

---

## Installation into Alliance Auth

### 1. Install the Package
Add to your `conf/requirements.txt`:

```text
git+https://github.com/Thrainkrilleve/wormies.git
```

Or install via pip inside your Alliance Auth environment:

```bash
pip install git+https://github.com/Thrainkrilleve/wormies.git
```

### 2. Configure `local.py`
In your Alliance Auth `local.py` (e.g. `conf/local.py`):

```python
# Add to INSTALLED_APPS:
INSTALLED_APPS += [
    'allianceauth_wormholesystems',
]

# Configure the Wormhole Systems URL:
WORMHOLESYSTEMS_URL = "https://wormhole.yourdomain.com"
```

### 3. Run Migrations
Run migrations to register the app permissions:

```bash
python manage.py migrate
```

### 4. Create the OIDC Application in Alliance Auth Admin
Go to Alliance Auth Admin (`https://auth.yourdomain.com/admin/`):
1. Navigate to **AllianceAuth OIDC** > **Applications** > **Add Application**.
2. Fill in the fields:
   - **Name:** `Wormhole Systems`
   - **Client Type:** `Confidential`
   - **Authorization Grant Type:** `Authorization code`
   - **Redirect URIs:** `https://wormhole.yourdomain.com/auth/allianceauth/callback`
   - **Algorithm:** `RS256`
   - **Skip Authorization:** `True`
3. Copy the generated **Client ID** and **Client Secret** and add them to your Alliance Auth `.env`:
   - `WS_CLIENT_ID`
   - `WS_CLIENT_SECRET`

### 5. Grant Permissions to Users / Groups
In Alliance Auth Admin:
- Go to **Authentication and Authorization** > **Groups** (or **States**).
- Grant the permission `wormholesystems | General | Can access Wormhole Systems` to the appropriate groups or member states (e.g. `Member`, `Wormhole Division`).
- Pilots with this permission will see the "Wormhole Systems" link in their sidebar.
