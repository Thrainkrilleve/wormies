# Implementation Plan: All-in-One Setup (Wiki.js Pattern) & Repo/Doc Cleanup

Consolidate Wormhole Systems into a clean, drop-in companion service for Alliance Auth (following the Wiki.js deployment model). This eliminates the need for separate host folders, external `.env` files, or a separate Cloudflare docker-compose file.

## Architecture Vision (The "Wiki.js" Pattern)

In an Alliance Auth Docker environment (`aa-docker`), users want to add services seamlessly:
1. **Zero Host Source Mounts:** The container image contains the entire app, compiled assets, and configuration. Users do **not** need to clone the entire repository onto their host machine just to run Docker.
2. **Single `.env` File:** Wormhole Systems runs using the **same `.env` file** as Alliance Auth (`aa-docker/.env`). It automatically reuses `ESI_SSO_CLIENT_ID`, `ESI_SSO_CLIENT_SECRET`, and `ESI_USER_CONTACT_EMAIL` from Alliance Auth.
3. **Unified Compose & Routing:** Wormhole Systems services (`app`, `reverb`, `mysql`, workers) live directly in `aa-docker/docker-compose.yml` (or an override file). Cloudflare Tunnel or Nginx Proxy Manager simply routes to the container ports without needing a separate compose file.
4. **Direct Pip Install:** Root `setup.py` allows `pip install git+https://github.com/Thrainkrilleve/wormies.git` directly in `requirements.txt` without needing `#subdirectory=...`.

---

## Proposed Changes

### 1. Repository (`Thrainkrilleve/wormies`)

#### [NEW] [setup.py](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/setup.py)
- Create a root `setup.py` with `package_dir={'': 'allianceauth-wormholesystems'}` so `pip install git+https://github.com/Thrainkrilleve/wormies.git` works directly from the repo root.

#### [NEW] [docker/Dockerfile](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/docker/Dockerfile)
- Multi-stage FrankenPHP Dockerfile building the complete app self-contained.
- Bakes in `php.ini`, `entrypoint.sh`, dynamic reverb fix, and SDE universe commands so no host bind mounts are needed.

#### [NEW] [docker/entrypoint.sh](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/docker/entrypoint.sh)
- Automatic DB wait probe, migrations, storage permissions, and config caching.

#### [NEW] [docker/php.ini](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/docker/php.ini)
- PHP production settings (memory limit 8G for SDE, session handling).

#### [NEW] [.github/workflows/docker.yml](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/.github/workflows/docker.yml)
- GitHub Actions workflow to build and push `ghcr.io/thrainkrilleve/wormies:latest` on release/push to `main`.

#### [DELETE] [docker-compose.cloudflare.yml](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/docker-compose.cloudflare.yml) & [.env.cloudflare.example](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/.env.cloudflare.example)
- Delete redundant Cloudflare-specific compose and env files.

#### [NEW] [docker-compose.allianceauth.snippet.yml](file:///c:/Users/attho/OneDrive/Documents/Wormies/WormholeSystems/docker-compose.allianceauth.snippet.yml)
- A clean, ready-to-paste snippet for users adding Wormhole Systems to `aa-docker/docker-compose.yml`.

---

### 2. Local Stack Update (`c:\Users\attho\docker\aa-docker`)

#### [MODIFY] [aa-docker/.env](file:///c:/Users/attho/docker/aa-docker/.env)
- Append the minimal Wormhole Systems configuration block (`WS_APP_URL`, `WS_APP_KEY`, `WS_AUTH_CLIENT_ID`, `WS_AUTH_CLIENT_SECRET`, `WS_DB_PASSWORD`, `WS_REVERB_APP_KEY`, `WS_REVERB_APP_SECRET`).
- Delete reliance on the external `wormholesystems-containers/.env`.

#### [MODIFY] [aa-docker/docker-compose.yml](file:///c:/Users/attho/docker/aa-docker/docker-compose.yml)
- Point `env_file:` to `./.env`.
- Remove all host directory bind mounts (`C:/Users/attho/...`).
- Map environment variables using the unified `.env` values.
- Fix worker/reverb healthcheck definitions so they report healthy.

#### [EXECUTE] Build & Bake Local Docker Image
- Update `wormhole-systems:production` locally so all code fixes and `php.ini` are baked directly into the image.

---

### 3. Documentation (`README.md`)

Rewrite `README.md` completely with a clean, linear structure:

1. **Overview & Philosophy:**
   - Explain that Wormhole Systems runs as a companion service alongside Alliance Auth, requiring only:
     - Adding the plugin to Alliance Auth (`requirements.txt` & `local.py`).
     - Adding a few `WS_*` variables to your existing Alliance Auth `.env`.
     - Adding the service snippet to `docker-compose.yml`.
2. **Step-by-Step Installation (All-in-One):**
   - **Step 1: Install Plugin** — Add `git+https://github.com/Thrainkrilleve/wormies.git` to `conf/requirements.txt` and `local.py`.
   - **Step 2: Create OIDC App** — In Alliance Auth Admin (`/admin/`), create OIDC application and copy Client ID & Secret.
   - **Step 3: Update `.env`** — Paste the `WS_*` settings into your existing `.env`. (Reuses `ESI_SSO_CLIENT_ID`, `ESI_SSO_CLIENT_SECRET`, and `ESI_USER_CONTACT_EMAIL` automatically!)
   - **Step 4: Update `docker-compose.yml`** — Paste the snippet into `docker-compose.yml` and run `docker compose up -d`.
   - **Step 5: Cloudflare Tunnel / Proxy** — Route `wormhole.domain.com` (port 8090) and `ws.wormhole.domain.com` (port 8091).
   - **Step 6: Initialize SDE** — Run `docker compose exec wormholesystems_app php artisan sde:download && php artisan sde:seed`.
3. **Developer / Standalone Mode:**
   - Brief section explaining how to build from source in a subfolder (`wormhole-systems/`) for developers wanting to modify frontend/backend code.

---

## Verification Plan

### Automated / Command Checks
1. Validate `docker build` of `wormhole-systems:production` with baked-in code.
2. Run `docker compose config` in `c:\Users\attho\docker\aa-docker` to ensure no syntax errors or missing variables.
3. Restart containers with `docker compose up -d` in `c:\Users\attho\docker\aa-docker` and check `docker compose ps`.
4. Verify HTTP and WebSocket endpoints return 200/UP:
   `curl http://127.0.0.1:8090/up` and `curl http://127.0.0.1:8091/up`.

### Git & Remote
1. Commit all cleaned files and push to `origin main` on `Thrainkrilleve/wormies`.
2. Update git tag `v0.0.1`.
