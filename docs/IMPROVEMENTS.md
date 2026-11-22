# Self-hosted deployment review

Reviewed for a single host running `ghcr.io/thrainkrilleve/wormies:latest` as six containers (app, reverb, queue, scheduler, killmails, MariaDB) behind nginx and a Cloudflare tunnel, next to Alliance Auth.

The first pass (worker healthcheck, migrations only in the web container, Reverb client config, `.dockerignore`, Supercronic checksum, publish port split, queue worker limits, secure session cookie, log rotation, `example.com` fallbacks) is on `main`. This follow-up does the items that were left as recommendations, and fixes character tracking plus the access bugs found in the same pass.

## Tracking

Character location could lag, or stop until something else reset it, while the queue still showed `UpdateCharacterLocation`, `UpdateCharacterOnlineStatus`, and `DispatchCharacterStatusEvents` completing with no errors.

Three things stacked:

1. **Lost broadcasts.** `DispatchCharacterStatusEvents` read every row with `event_queued_at` set, then cleared *every* such row. A location write that committed between those two queries was erased and never sent. The database had the new system. The map did not, until the next change or a full reload.
2. **Refresh-token races deleted the ESI token.** EVE refresh tokens are single-use. Location and online jobs ran at the same time, each with its own overlap lock, and both refreshed. The loser got `invalid_grant`. The ESI client then deleted the token on that failure, and also on any 401/403. The job caught the failure and returned success. With the scopes gone, the location command marked the character offline and polling stopped until the pilot connected the character again.
3. **Idle users were forced offline, and tracking shared the killmail queue.** Online checks only ran when the website user had been active in the last 10 minutes, and an online character was marked offline without asking ESI once that window passed. The map ping is easy for a background tab to delay. Separately, every location job waited behind the killmail firehose on the default queue.

What changed:

- ESI calls for one character take a lock, and the token is refreshed once inside it. The ESI client's `delete()` is ignored unless the refresh token is actually revoked (`invalid_grant`). A 420 sets a backoff that expires on its own.
- Event dispatch locks the rows it is about to clear, so a newer write is kept. A heartbeat queues another broadcast at most once a minute when the location did not change, and the user channel reloads `auth` after a websocket reconnect.
- Characters who are online, or who were online in the last six hours, keep being checked even if the website session is idle. Missing scopes still take a character offline, and that change is broadcast.
- Tracking jobs use the `tracking` queue. Workers consume `tracking` before `default`.
- Failures are logged (throttled per character) instead of being swallowed.

`location_last_checked_at` is written on every attempt, including a failed one, so a stall is visible in the row.

## Killmails

- The listener stores the last R2Z2 sequence in the shared cache and resumes from it. A restart no longer jumps to "latest" and skips the gap.
- If there is no cached sequence and the latest-id request still fails after retries, the process exits non-zero. It does not start at sequence 0. Docker will restart it. Fetch errors back off up to 60 seconds and then retry the same sequence. A 404 still means caught up.
- Default retention is 3 days (`ZKILLBOARD_MAX_AGE_DAYS`). `app:purge-old-killmails` deletes in batches of 500 and runs hourly.
- `EvaluateKillmailAlertsJob` is dispatched only when an active killmail alert exists (cached for 30 seconds, cleared when an alert is saved or deleted). Wormhole kills are still logged at info. Everything else is debug.

The first purge after this image ships will delete the old 730-day table in batches. Set `ZKILLBOARD_MAX_AGE_DAYS` before that if a map needs a longer window.

## Runtime and deploy

- `docker/php.ini` `memory_limit` is 512M. The entrypoint writes `zz-memory.ini`: 256M for reverb and killmails, 512M for app, queue, and scheduler. The image build still runs `generate:static-data` with a 1G limit. The daily schedule for that command is gone. Static data is produced at build time.
- Standalone compose publishes `127.0.0.1:${APP_PORT:-8090}:80` and `127.0.0.1:${REVERB_PUBLISH_PORT:-8091}:8080`.
- Queue workers: `php artisan queue:work --queue=tracking,default ...`. Recreate the queue containers or tracking jobs will sit forever.
- Alliance Auth snippet sets `CACHE_STORE=redis` and `QUEUE_CONNECTION=redis`. **Drain the database queue first** on a stack that already has jobs in MySQL. From a container that can still read that table: `php artisan queue:work database --stop-when-empty`. Confirm `jobs` is empty, then recreate the workers. Switching the driver does not move rows.
- `.env.example` uses `QUEUE_CONNECTION=redis` with the same drain note. Standalone compose already runs Redis.
- Security headers (including a CSP with a nonce for the inline boot scripts) are on web responses. Reverb `allowed_origins` comes from `APP_URL`, plus any comma-separated `REVERB_ALLOWED_ORIGINS`. Login, EVE SSO, and Alliance Auth callbacks are throttled, and the Sanctum map API is throttled.
- The Docker workflow runs the test workflow and `composer audit --locked --no-dev`, and only then publishes `latest`.

After this image is published, pull it and recreate the containers. A deployment compose that overrides queue, scheduler, or killmails with `pgrep` can drop that override. The image healthcheck is enough. `SESSION_SECURE_COOKIE=true` is correct for the HTTPS tunnel. A browser that hits the published HTTP port directly will not store the session cookie. Do not leave `APP_KEY` blank. The entrypoint still mints a random key when both `APP_KEY` and `AA_SECRET_KEY` are empty. That was left alone because a stack that currently boots that way would crash-loop until a key is set.

`composer audit --locked --no-dev` runs before the image is published. The packages it used to flag (Laravel, Guzzle, Symfony, CommonMark, Flysystem, phpseclib, react/http) are bumped in this follow-up. `npm audit` has no remaining high or critical findings. Moderate findings that need a breaking upgrade were left alone.

## Access

Checked EVE SSO and Alliance Auth (state is verified on the Alliance Auth callback; Socialite handles the EVE state), token storage, map policies, signature and connection writes, the Sanctum map API, Reverb `Map.{id}` and `User.{id}`, and CSRF on the web group.

Fixed:

- ESI access and refresh tokens are encrypted at rest. A migration encrypts rows that are still plaintext. Reads go through the cast, so callers still see the token.
- Map access matches character, corporation, and alliance by **type and id**. An id-only check would treat a character whose id equals a corporation id as that corporation.
- A signature update cannot point `map_connection_id` at a connection on another map. That write also copied mass and lifetime onto the other connection.
- Share links (`/share/{token}`) put the token in the session and redirect to the map without it. An older `?share_token=` link still works once: the token is stored and the browser is redirected to the same URL with that parameter removed. API and other clients can send `X-Map-Share-Token` instead of a query parameter. Comparison is `hash_equals`.
- Personal access tokens take `maps:read` and `maps:write`. The map API enforces the matching ability. A first-party session is unchanged. Tokens that already have `*` keep full access.
- Webhook URLs use the encrypted cast. A migration encrypts rows that are still plaintext.
- `TRUSTED_PROXIES` replaces trusting every address. The default is loopback and the private ranges Docker uses (`127.0.0.1`, `::1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`). Set it to the proxy address, or to `*`, if a public proxy in front of the app must set `X-Forwarded-*`.
- The map ping requires `view` on that map.
- Alliance Auth's denied-callback log no longer includes the query string (it contained the authorization code).
- The lint workflow no longer has `contents: write`. The auto-commit step that needed it is commented out.

Left as a smaller follow-up:

- Eager-loading `mapConnections.signatures` (and `mapOwner.accessible`) is still an open query improvement. It is not an access bug.
- There is no global admin role above map owner. That is current product behavior, not a hole.

## Still open, low

- Redis has no password and no published port. MariaDB's root password defaults to the application password. Fine on a network that is only this stack. Separate them if other containers share the network.
- `GetKillmailsForLast90DaysCommand` now has `withoutOverlapping` for a week, so a long backfill does not start a second copy.
- `config/services.php` no longer has the unused `zkillboard.identifier`.
- `laravel/tinker` is still in `require`. `knuckleswtf/scribe` is too. Confirm the documentation pages before moving Scribe to `require-dev`.
- The entrypoint still `chown -R`s `storage` on every boot.
- Per-job timeouts are set on the tracking jobs (`$timeout = 25`, under the 90 second `retry_after`). The long ESI and killmail backfill commands are still scheduler processes, not queue jobs.
