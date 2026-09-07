# WormholeSystems

[![tests](https://github.com/WormholeSystems/WormholeSystems/actions/workflows/tests.yml/badge.svg)](https://github.com/WormholeSystems/WormholeSystems/actions/workflows/tests.yml)
[![linter](https://github.com/WormholeSystems/WormholeSystems/actions/workflows/lint.yml/badge.svg)](https://github.com/WormholeSystems/WormholeSystems/actions/workflows/lint.yml)
[![License](https://img.shields.io/github/license/WormholeSystems/WormholeSystems)](LICENSE)
[![Stack](https://img.shields.io/badge/self--host-wormholesystems--containers-blue)](https://github.com/WormholeSystems/wormholesystems-containers)
[![wsctl](https://img.shields.io/github/v/release/WormholeSystems/wormholesystems-cli?label=wsctl)](https://github.com/WormholeSystems/wormholesystems-cli)

Wormhole mapping and tracking for EVE Online — live at [wormhole.systems](https://wormhole.systems). Real-time chain maps, signatures, character tracking and killmail intel, built with Laravel 12, Inertia.js, Vue 3 and Tailwind CSS.

### Key Features & Alliance Auth Integration

- **Alliance Auth Single Sign-On (SSO):** Seamless pilot authentication through Alliance Auth's OpenID Connect (OIDC) provider.
- **Shared EVE Developer Application:** No need to create a second app on the CCP Developer Portal; shares Alliance Auth's existing EVE credentials.
- **Enforced ESI Scopes:** Automatically syncs and checks the 5 required location and waypoint tracking scopes directly through Alliance Auth.
- **Discord Auto-Verification:** When pilots link Discord in Alliance Auth, their Discord account is automatically linked and verified in Wormhole Systems without a separate Discord OAuth prompt.
- **Alliance Auth Sidebar Plugin:** Includes [`allianceauth-wormholesystems/`](allianceauth-wormholesystems/) for 1-click launch from the Alliance Auth sidebar.
- **Dynamic Reverb WebSockets:** Real-time map synchronization with runtime WebSocket configuration that adapts automatically to localhost and Cloudflare Tunnels (WSS/443).

## Self-hosting & Deployment

### Deployment Architecture & File Locations

When self-hosting Wormhole Systems with Alliance Auth, the stack is divided into two distinct parts that can live on the **same machine** (e.g. your home server) or on **separate servers**:

```text
/your-server-root/
├── allianceauth/                          # Your Alliance Auth installation (e.g. aa-docker)
│   ├── conf/local.py                      # Auth settings (OIDC scopes, Discord, etc.)
│   └── (allianceauth-wormholesystems)     # Plugin installed via pip into AA container/venv
│
└── wormholesystems/                       # This repository (clone of Thrainkrilleve/wormies)
    ├── docker-compose.cloudflare.yml      # Container stack (FrankenPHP, Reverb, MariaDB, Redis)
    ├── .env                               # Wormhole Systems config (OIDC credentials, DB, etc.)
    ├── allianceauth-wormholesystems/      # Plugin source code (ready to install into AA)
    └── docs/
        └── cloudflare-tunnel-setup.md     # Cloudflare Tunnel ingress rules & DNS guide
```

#### Where to put the files:

1. **Alliance Auth Server / Container:**
   - Alliance Auth only needs the **[`allianceauth-wormholesystems/`](allianceauth-wormholesystems/)** plugin.
   - You can install it directly from GitHub without cloning the rest of the mapper:
     ```bash
     pip install git+https://github.com/Thrainkrilleve/wormies.git#subdirectory=allianceauth-wormholesystems
     ```
   - Or if Alliance Auth is on the same machine, install it from the local path:
     ```bash
     pip install -e /path/to/wormies/allianceauth-wormholesystems
     ```

2. **Wormhole Systems Server:**
   - Clone the entire repository:
     ```bash
     git clone https://github.com/Thrainkrilleve/wormies.git /opt/wormholesystems
     cd /opt/wormholesystems
     cp .env.cloudflare.example .env
     ```
   - Follow the [Cloudflare Tunnel Setup Guide](docs/cloudflare-tunnel-setup.md) to configure your domains and start the stack:
     ```bash
     docker compose -f docker-compose.cloudflare.yml up -d
     ```

### Upstream Standalone Installer
To run an upstream standalone instance without Alliance Auth:
```bash
curl --proto '=https' --tlsv1.2 -sSf https://install.wormhole.systems | sh
```

---

## Alliance Auth Integration Setup

### 1. Configure Alliance Auth Plugin
In your Alliance Auth environment:
```bash
pip install -e ./allianceauth-wormholesystems
```
Add to your `local.py`:
```python
INSTALLED_APPS += [
    'allianceauth_wormholesystems',
]

WORMHOLESYSTEMS_URL = "https://wormhole.yourdomain.com"
```
Ensure `LOGIN_TOKEN_SCOPES` in `local.py` includes the required tracking scopes:
```python
LOGIN_TOKEN_SCOPES = [
    'publicData',
    'esi-location.read_location.v1',
    'esi-location.read_ship_type.v1',
    'esi-location.read_online.v1',
    'esi-ui.write_waypoint.v1',
]
```

### 2. Create the OIDC Application in Alliance Auth
In Alliance Auth Admin (`/admin/`):
- Go to **AllianceAuth OIDC** > **Applications** > **Add Application**.
- **Client Type:** `Confidential`
- **Authorization Grant Type:** `Authorization code`
- **Redirect URIs:** `https://wormhole.yourdomain.com/auth/allianceauth/callback`
- **Algorithm:** `RS256`
- **Skip Authorization:** `True` (recommended for seamless SSO)

### 3. Configure Wormhole Systems `.env`
In your Wormhole Systems `.env`:
```env
# Alliance Auth OIDC
ALLIANCEAUTH_ENABLED=true
ALLIANCEAUTH_BASE_URL="https://auth.yourdomain.com"
ALLIANCEAUTH_CLIENT_ID=your_client_id
ALLIANCEAUTH_CLIENT_SECRET=your_client_secret
ALLIANCEAUTH_CALLBACK="https://wormhole.yourdomain.com/auth/allianceauth/callback"
ALLIANCEAUTH_ONLY=true

# Shared CCP Developer Application credentials (from Alliance Auth)
EVE_CLIENT_ID=your_allianceauth_eve_client_id
EVE_CLIENT_SECRET=your_allianceauth_eve_client_secret
```

---

## Development setup

**Requirements:** PHP 8.4+, Composer, MySQL/MariaDB, Redis, Node.js + npm. We strongly recommend [Laravel Herd](https://herd.laravel.com/), which provides all of it pre-configured with automatic HTTPS.

```bash
git clone https://github.com/WormholeSystems/WormholeSystems.git
cd WormholeSystems

cp .env.example .env
composer install
npm install
php artisan key:generate
php artisan migrate

# EVE static data (~500MB download; seeding may need more memory)
php artisan sde:download
php artisan sde:prepare
php -d memory_limit=2G artisan db:seed
```

In Herd, make sure **MySQL** and **Redis** are running and create a database named `wormholesystems`. Herd serves the site at `https://wormholesystems.test` (derived from the folder name).

Key `.env` values for local development:

```env
APP_URL=https://wormholesystems.test
DB_DATABASE=wormholesystems
DB_USERNAME=root
DB_PASSWORD=

# CCP requires contact info on third-party apps — leaving this empty
# risks an EVE API ban. Format: "you@example.com | Your EVE Character"
CONTACT_EMAIL=

# From your EVE developer application (see below)
EVE_CLIENT_ID=
EVE_CLIENT_SECRET=

# Reverb via Herd's Broadcasting service
REVERB_APP_ID=1001
REVERB_APP_KEY=laravel-herd
REVERB_APP_SECRET=secret
REVERB_HOST="reverb.herd.test"
REVERB_PORT=443
REVERB_SCHEME=https
```

### Running it

```bash
npm run dev                 # frontend with hot reload
php artisan queue:work      # background jobs (killmails, locations, ...)
php artisan schedule:work   # scheduled tasks
```

Real-time features need **Reverb**: in Herd's Services panel, add "Reverb" under _Broadcasting_ and start it (not enabled by default; runs at `reverb.herd.test:443`).

## EVE Online Credentials & SSO

### With Alliance Auth (Zero Dev Portal Duplication)
You **do NOT need to create a second application** on the CCP Developer Portal! Wormhole Systems automatically shares your Alliance Auth EVE developer credentials:
1. Locate `ESI_SSO_CLIENT_ID` and `ESI_SSO_CLIENT_SECRET` in your Alliance Auth `local.py`.
2. Copy them directly into Wormhole Systems `.env`:
   ```env
   EVE_CLIENT_ID=your_allianceauth_client_id
   EVE_CLIENT_SECRET=your_allianceauth_client_secret
   ```
3. Pilots authenticate via Alliance Auth SSO. All required tracking scopes are validated during Alliance Auth login, and their ESI character tokens are automatically synced to Wormhole Systems.

### Standalone Setup (Without Alliance Auth)
Only if running standalone without Alliance Auth, create an app at [developers.eveonline.com](https://developers.eveonline.com/):
- **Connection type:** Authentication & API Access
- **Callback URL:** `https://yourdomain.com/eve/callback`
- **Scopes:**
    - `publicData`
    - `esi-location.read_location.v1` — character location
    - `esi-location.read_online.v1` — online status
    - `esi-location.read_ship_type.v1` — current ship
    - `esi-ui.write_waypoint.v1` — set autopilot waypoints

---

## Discord Setup & Auto-Verification

### With Alliance Auth (Auto-Verification)
You **do NOT need separate Discord OAuth** for pilots!
- When pilots link Discord in Alliance Auth (**Services** → **Discord** or `!auth`), Alliance Auth provides their Discord identity directly to Wormhole Systems on login.
- Wormhole Systems automatically binds and verifies their `DiscordAccount` so they can receive personal map alerts.
- To enable Discord bot deliveries (DM alerts and mentions) from Wormhole Systems, simply reuse your Alliance Auth bot credentials in Wormhole Systems `.env`:
  ```env
  DISCORD_APPLICATION_ID=your_discord_app_id
  DISCORD_CLIENT_ID=your_discord_app_id
  DISCORD_CLIENT_SECRET=your_discord_app_secret
  DISCORD_BOT_TOKEN=your_discord_bot_token
  ```

### Standalone Discord Setup (Without Alliance Auth)
If running standalone without Alliance Auth, create an application at the [Discord Developer Portal](https://discord.com/developers/applications):
1. Open **OAuth2**. Add `https://yourdomain.com/discord/callback` as a redirect URL.
2. Open **Bot**, create the bot token, and keep it secret.
3. Under **Installation**, enable Guild Install with `applications.commands` and `bot` scopes (View Channels, Send Messages, Embed Links).
4. Configure in `.env`:
```dotenv
DISCORD_APPLICATION_ID=           # Application ID
DISCORD_CLIENT_ID=                # Application ID
DISCORD_CLIENT_SECRET=            # OAuth2 client secret
DISCORD_BOT_TOKEN=                # Bot token
DISCORD_CALLBACK="${APP_URL}/discord/callback"
```

Register the commands globally for production:

```bash
php artisan discord:register-commands --global
```

For development, set `DISCORD_TEST_GUILD_ID` and omit `--global`. Guild commands update immediately, while global command changes can take time to propagate.

The bot is a long-running CLI process and must run separately from queue workers, Reverb and Octane:

```bash
php artisan discord:listen
```

Production deployments must supervise exactly one `discord:listen` process. The process exits cleanly on `SIGTERM`/`SIGQUIT` and participates in `php artisan reload` through `discord:restart`.

## Background services

- **Queue** — killmail ingest (zKillboard), character location/online updates, sovereignty data. `php artisan queue:failed` / `queue:retry all` for failures.
- **Scheduler** — server status and character updates (seconds/minutes cadence), signature cleanup, sovereignty and killmail backfills. `php artisan schedule:list` shows everything.
- **Reverb** — WebSockets for real-time map updates. Without Herd: `php artisan reverb:start`.
- **Discord bot** — slash commands and personal alerts. Run one supervised `php artisan discord:listen` process.

## Useful commands

```bash
php artisan test                       # test suite
vendor/bin/pint                        # code formatting
php artisan optimize:clear             # clear all caches
php artisan tinker                     # interactive shell
php artisan migrate:fresh --seed       # reset database (destructive)
php artisan app:listen-for-killmails   # live killmail stream
php artisan app:get-killmails-for-day 2026-01-15
php artisan discord:register-commands --global
php artisan discord:listen
```

## Troubleshooting

- **SDE seeding runs out of memory** → `php -d memory_limit=2G artisan db:seed`
- **No real-time updates** → Reverb running? Check the WebSocket connection in the browser console.
- **Queue jobs not processing** → Redis running? Worker active (`php artisan queue:work`)?
- **EVE SSO fails** → Client ID/Secret correct, callback URL matches the developer application exactly, scopes granted. Logs: `storage/logs/laravel.log`.
- **Discord linking fails** → Client ID/Secret and callback URL match the Discord application exactly.
- **Slash commands are missing** → Install the application with `applications.commands`, then register commands globally or against `DISCORD_TEST_GUILD_ID`.
- **Discord alerts are not delivered** → Bot process and queue worker running? Check channel permissions and the configured bot token.

## Contributing

Fork, branch, make your changes, then `php artisan test` and `vendor/bin/pint` before opening a pull request — CI runs both.

## Related repositories

- [wormholesystems-containers](https://github.com/WormholeSystems/wormholesystems-containers) — production docker stack
- [wormholesystems-cli](https://github.com/WormholeSystems/wormholesystems-cli) — `wsctl` setup and management tool

## License

This project is open-sourced software licensed under the [MIT license](LICENSE).
