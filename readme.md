# Wormhole Systems (Alliance Auth Edition)

Wormhole mapping and tracking for EVE Online, integrated directly with **Alliance Auth**. Features real-time chain maps, signature tracking, character location tracking, killmail intel, and Discord auto-verification.

Like Wiki.js, Mumble, or Grafana, Wormhole Systems runs as a Docker companion service directly within your existing Alliance Auth stack. You do **not** need a separate server, a separate `.env` file, or separate Cloudflare tunnel containers.

---

## Features & Alliance Auth Integration

- **Single Sign-On (SSO):** Pilots authenticate seamlessly through Alliance Auth's OpenID Connect (OIDC) provider.
- **Shared CCP Application:** Automatically reuses your existing Alliance Auth EVE developer credentials (`ESI_SSO_CLIENT_ID` / `ESI_SSO_CLIENT_SECRET`). No need to register a second app on the CCP Developer Portal.
- **Discord Auto-Verification:** When pilots link Discord in Alliance Auth, their Discord account is automatically verified in Wormhole Systems for personal map alerts without a second OAuth prompt.
- **Sidebar Launch:** Adds a 1-click launch link directly into the Alliance Auth sidebar.
- **Dynamic Reverb WebSockets:** Real-time map updates that automatically adapt to your domain and Cloudflare Tunnel.
- **Zero-Config Secrets:** Encryption keys, database passwords, and WebSocket keys are automatically derived from your existing Alliance Auth environment.

---

## Setup Guide (In 6 Simple Steps)

### Step 1: Install Plugin into Alliance Auth

In your Alliance Auth setup (e.g. `aa-docker`):

1. run `pip install git+https://github.com/Thrainkrilleve/wormies.git` inside your Alliance Auth environment

2. Add the package to your `conf/requirements.txt`:
   ```text
   allianceauth-wormholesystems @ git+https://github.com/Thrainkrilleve/wormies.git
   ```
  

2. Add to your `conf/local.py`:
   ```python
   INSTALLED_APPS += [
       'allianceauth_wormholesystems',
   ]

   WORMHOLESYSTEMS_URL = "https://wormhole.yourdomain.com"
   ```

3. Ensure `LOGIN_TOKEN_SCOPES` in `conf/local.py` includes the required tracking scopes:
   ```python
   LOGIN_TOKEN_SCOPES = [
       'publicData',
       'esi-location.read_location.v1',
       'esi-location.read_ship_type.v1',
       'esi-location.read_online.v1',
       'esi-ui.write_waypoint.v1',
   ]
   ```

---

### Step 2: Create the OIDC Application in Alliance Auth

1. Log into Alliance Auth Admin (`https://auth.yourdomain.com/admin/`).
2. Navigate to **AllianceAuth OIDC** &rarr; **Applications** &rarr; **Add Application**.
3. Fill in the fields:
   - **Client Type:** `Confidential`
   - **Authorization Grant Type:** `Authorization code`
   - **Redirect URIs:** `https://wormhole.yourdomain.com/auth/allianceauth/callback`
   - **Algorithm:** `RS256`
   - **Skip Authorization:** `True` (recommended for seamless SSO)
4. Click **Save** and note the generated **Client ID** and **Client Secret**.

---

### Step 3: Add 2 Lines to your existing Alliance Auth `.env`

You do **not** need to invent new passwords or random keys. Open your existing Alliance Auth `.env` file (e.g. `aa-docker/.env`) and simply add:

```env
# --- Wormhole Systems ---
WS_CLIENT_ID=your_client_id_from_step_2
WS_CLIENT_SECRET=your_client_secret_from_step_2
```

> **Automatic Magic:** All other parameters (`AA_DB_PASSWORD`, `AA_SECRET_KEY`, `DOMAIN`, `ESI_SSO_CLIENT_ID`, `ESI_SSO_CLIENT_SECRET`, and `ESI_USER_CONTACT_EMAIL`) are pulled **automatically** from your existing Alliance Auth configuration!

---

### Step 4: Add Services to your `docker-compose.yml`

In your existing Alliance Auth `docker-compose.yml`:
1. Paste `x-wormholesystems-base:` at the top of the file (above `services:`).
2. Paste the `wormholesystems_*` services directly inside your existing `services:` block (do **not** add a second `services:` header!).
3. Add `ws-mysql-data:` and `ws-laravel-storage:` under your existing `volumes:` block.

*(See [**`docker-compose.allianceauth.snippet.yml`**](docker-compose.allianceauth.snippet.yml) for the exact snippet)*

Start the containers:
```bash
docker compose up -d
```

---

### Step 5: Configure Cloudflare Tunnel Dashboard (If you use cloudflare)

In your **Cloudflare Zero Trust Dashboard** &rarr; **Networks** &rarr; **Tunnels** &rarr; your tunnel &rarr; **Public Hostnames**:

1. **Web Application:**
   - **Subdomain:** `wormhole`
   - **Domain:** `yourdomain.com`
   - **Service Type:** `HTTP`
   - **URL:** `localhost:8090` (or `wormholesystems-app:80` if cloudflared is on the same docker network)

2. **WebSockets (Reverb):**
   - **Subdomain:** `ws.wormhole`
   - **Domain:** `yourdomain.com`
   - **Service Type:** `HTTP`
   - **URL:** `localhost:8091` (or `wormholesystems-reverb:8080` if cloudflared is on the same docker network)
   - Under **Additional application settings** &rarr; **HTTP Settings**: ensure standard WebSocket proxying is active.

---

### Step 6: Initialize EVE Universe Static Data (One-Time)

Once the container is running, seed the EVE universe data:

```bash
docker compose exec wormholesystems-app php artisan sde:download
docker compose exec wormholesystems-app php artisan sde:seed
```

That's it! Pilots can now click the **Wormhole Systems** link in your Alliance Auth sidebar to log straight into the mapper.

---

## Standalone Deployment (Without Alliance Auth)

If you wish to deploy Wormhole Systems standalone on its own server without Alliance Auth:

1. Clone this repository:
   ```bash
   git clone https://github.com/Thrainkrilleve/wormies.git /opt/wormholesystems
   cd /opt/wormholesystems
   ```
2. Copy the example environment file:
   ```bash
   cp .env.example .env
   nano .env
   ```
3. Start the stack:
   ```bash
   docker compose up -d
   ```
4. Seed universe data:
   ```bash
   docker compose exec app php artisan sde:download
   docker compose exec app php artisan sde:seed
   ```

---

## Useful Commands

```bash
docker compose exec wormholesystems-app php artisan optimize:clear   # Clear all application cache
docker compose exec wormholesystems-app php artisan tinker           # Interactive PHP shell
docker compose logs -f wormholesystems-app                          # View application logs
docker compose logs -f wormholesystems-reverb                       # View WebSocket logs
```

---

## License

Open-sourced software licensed under the [MIT license](LICENSE).
