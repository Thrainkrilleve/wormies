# Cloudflare Tunnel & Alliance Auth Integration Guide

This guide walks you through deploying **Wormhole Systems** alongside your existing **Alliance Auth** Docker stack on your home server using **Cloudflare Tunnels**.

---

## Architecture Overview

```
                      Internet / Alliance Pilots
                                   │
                                   ▼
                        Cloudflare Edge Network
                   (SSL Certificates & DDoS Protection)
                                   │
                                   ▼  Cloudflare Tunnel (cloudflared)
                   Your Home Server (Docker Host)
      ┌────────────────────────────────────────────────────────┐
      │                                                        │
      │  https://auth.r3v-w.space   ──► Alliance Auth (:8000)  │
      │  https://wormhole.r3v-w.space ──► Wormhole Web (:8090) │
      │  wss://ws.wormhole.r3v-w.space ──► Reverb WS   (:8091) │
      │                                                        │
      └────────────────────────────────────────────────────────┘
```

---

## Step 1: Configure Cloudflare Tunnel (`cloudflared`)

In your Cloudflare Zero Trust Dashboard (or your local `config.yml` on your server), add the following Public Hostnames to your tunnel:

### 1. Main Web Application
* **Public Hostname:** `wormhole.r3v-w.space`
* **Service Type:** `HTTP`
* **URL:** `localhost:8090` (or `http://wormholesystems-app:80` if cloudflared is on the same Docker network)

### 2. Real-time Map WebSockets (Reverb)
* **Public Hostname:** `ws.wormhole.r3v-w.space`
* **Service Type:** `HTTP`
* **URL:** `localhost:8091` (or `http://wormholesystems-reverb:8080`)
* **Additional Settings (under HTTP Settings):**
  * Enable **No TLS Verify** (if applicable)
  * Ensure **WebSockets** is enabled in your Cloudflare dashboard (Network settings for the domain).

---

## Step 2: Configure Alliance Auth OIDC Provider

In your Alliance Auth Django administration panel (`https://auth.r3v-w.space/admin/`):

1. Navigate to **AllianceAuth OIDC** > **Applications** (or **Django OAuth Toolkit** > **Applications**).
2. Click **Add Application**:
   * **Name:** `Wormhole Systems`
   * **Client Type:** `Confidential`
   * **Authorization Grant Type:** `Authorization code`
   * **Redirect URIs:** `https://wormhole.r3v-w.space/auth/allianceauth/callback`
   * **Skip Authorization:** (Optional: check this if you want 100% silent instant login without the pilot having to click "Authorize" on their first visit).
   * **Algorithm:** `RS256` (or `HS256` depending on your OIDC setup)
3. Save the application and note the generated **Client ID** and **Client Secret**.

---

## Step 3: Install the Alliance Auth Sidebar App

We built `allianceauth-wormholesystems` to render the navigation link in the Alliance Auth sidebar and gate access by permission.

1. Copy the `allianceauth-wormholesystems` folder to your server where Alliance Auth runs.
2. Inside your Alliance Auth container (or virtualenv), install the package:
   ```bash
   pip install -e /path/to/allianceauth-wormholesystems
   ```
3. In your Alliance Auth `local.py` (e.g. `myauth/settings/local.py`), add:
   ```python
   INSTALLED_APPS += [
       'allianceauth_wormholesystems',
   ]

   WORMHOLESYSTEMS_URL = "https://wormhole.r3v-w.space"
   ```
4. Run migrations:
   ```bash
   python manage.py migrate
   ```
5. In Alliance Auth Admin, go to **Groups** or **States** and assign the permission:
   * `wormholesystems | General | Can access Wormhole Systems`
   to your alliance member groups (e.g. `Member`, `Wormhole Division`).

---

## Step 4: Configure and Launch Wormhole Systems

On your server inside `wormholesystems-containers/`:

1. Copy the tailored Cloudflare environment file:
   ```bash
   cp .env.cloudflare.example .env
   ```
2. Copy the MySQL environment file:
   ```bash
   cp dockerfiles/mysql/.env.example dockerfiles/mysql/.env
   ```
   *Make sure the DB password in `dockerfiles/mysql/.env` matches `DB_PASSWORD` in `.env`.*
3. Open `.env` and fill in:
   * `ALLIANCEAUTH_CLIENT_ID`: (from Step 2)
   * `ALLIANCEAUTH_CLIENT_SECRET`: (from Step 2)
   * `CONTACT_EMAIL`: (your EVE email/character)
   * `REVERB_APP_KEY` & `REVERB_APP_SECRET`: (generate random strings with `openssl rand -hex 16`)
4. Build and start the container stack:
   ```bash
   docker compose -f docker-compose.cloudflare.yml build
   docker compose -f docker-compose.cloudflare.yml up -d
   ```
5. Initialize the application key and database:
   ```bash
   # Generate APP_KEY
   docker compose -f docker-compose.cloudflare.yml exec app php artisan key:generate

   # Print the generated key and paste it into APP_KEY in host .env:
   docker compose -f docker-compose.cloudflare.yml exec app grep APP_KEY .env

   # Download EVE Static Data Export (SDE) and run migrations (~500MB)
   docker compose -f docker-compose.cloudflare.yml exec app php artisan sde:download
   docker compose -f docker-compose.cloudflare.yml exec app php artisan migrate --seed

   # Cache configurations
   docker compose -f docker-compose.cloudflare.yml exec app php artisan optimize:clear
   docker compose -f docker-compose.cloudflare.yml exec app php artisan optimize
   ```

---

## Step 5: Test the Integration

1. Log into your **Alliance Auth** dashboard (`https://auth.r3v-w.space`).
2. In the left navigation menu, click **Wormhole Systems** (with the compass icon).
3. Alliance Auth will launch `https://wormhole.r3v-w.space/auth/allianceauth`, authenticate your pilot via OIDC, and take you straight to the wormhole map dashboard!
