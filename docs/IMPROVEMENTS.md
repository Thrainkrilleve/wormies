# Self-hosted deployment review

Reviewed for a single host running `ghcr.io/thrainkrilleve/wormies:latest` as six containers (app, reverb, queue, scheduler, killmails, MariaDB) behind nginx and a Cloudflare tunnel, next to Alliance Auth. The image is built from `docker/Dockerfile` on top of `dunglas/frankenphp`.

Two production symptoms are different failures:

- Workers reported **unhealthy** because they inherited the base image healthcheck. `restart: unless-stopped` does not restart a container just because it is unhealthy, so that status does not by itself cause a crash loop.
- The earlier stack **restart loop** means the main process exited. Nothing in the repo records that night's error. The closest code path is the shared entrypoint: any command that exits non-zero is restarted until it succeeds.

Effort below is the size of the change (what has to be edited and what can go wrong), not a schedule.

## Implemented here

| Change | Where |
| --- | --- |
| Role-aware image healthcheck so queue, scheduler, and killmail containers are healthy without a compose override | `docker/healthcheck.sh`, `docker/Dockerfile` |
| Migrations run only in the web container | `docker/entrypoint.sh` |
| Public Reverb host/key/port/scheme read from config, which `php artisan optimize` actually caches | `config/reverb.php`, `resources/views/app.blade.php` |
| Build context excludes `.git`, `.env`, `vendor`, `tests`, and local storage | `.dockerignore` |
| Supercronic 0.2.38 download is checked against a SHA-256 | `docker/Dockerfile` |
| Standalone publish port is `REVERB_PUBLISH_PORT` so it no longer collides with in-network `REVERB_PORT` | `.env.example`, `docker-compose.yml` |
| Queue workers declare `--timeout=60 --memory=256 --max-jobs=1000` | `docker-compose.yml`, `docker-compose.allianceauth.snippet.yml` |
| Laravel session cookie defaults to `Secure` for the documented HTTPS deployment | `.env.example`, `docker-compose.allianceauth.snippet.yml` |
| json-file log rotation (10MB, 5 files) on the standalone stack | `docker-compose.yml` |
| Config fallbacks and the Alliance Auth feature test use `example.com`. A settings test uses a placeholder Discord application id | `config/services.php`, `app/Services/AllianceAuthService.php`, `app/Http/Controllers/ScopeController.php`, `tests/Feature/Auth/AllianceAuthTest.php`, `tests/Feature/SettingsTest.php` |

After this image is published, pull it and recreate the containers. Compose healthchecks on app and reverb still override the image check, which is what we want (they curl `/up`). Queue, scheduler, and killmails do not set a healthcheck in this repo, so they use the image. A deployment compose that already overrides those three with `pgrep` can keep the override; it wins over the image. It can also be removed once the new image is the one running. `WORMHOLES_ROLE` (`app`, `reverb`, `queue`, `scheduler`, `killmails`) forces the role. When it is unset, the script detects the process.

`SESSION_SECURE_COOKIE=true` is correct when pilots only use the HTTPS tunnel. A browser hitting the published HTTP port directly will not store the session cookie.

## High

### 1. Worker healthchecks probe a port only the web process opens

The FrankenPHP base image is `HEALTHCHECK CMD curl -f http://localhost:2019/metrics`, and it `EXPOSE`s 2019. Caddy's admin API listens there only while `frankenphp run` is the process. `queue`, `scheduler`, and `killmails` replace that command, so the check fails forever. App and reverb in this repo already override it (`docker-compose.yml`).

**Fixed** by `docker/healthcheck.sh`. App curls `http://127.0.0.1:80/up`. Reverb curls `http://127.0.0.1:8080/up` (Reverb 1.10 registers `GET /up`). The other roles require their own process. `pgrep` patterns are written so the healthcheck process does not match itself. `start-period` is 120s because the entrypoint waits on MySQL and then runs `optimize`.

Effort: done.

### 2. Every container migrates on startup

`docker/entrypoint.sh` runs for every role (`ENTRYPOINT` plus a different `command`). It used to call `php artisan migrate --force` unconditionally, then `optimize`, with `set -e`. On a deploy, the app becomes healthy and reverb, both queue replicas, the scheduler, and the killmail listener all migrate at once. A migrations-table lock or a duplicate migration makes that process exit 1, and `restart: unless-stopped` brings it back into the same race. The queue command's `--max-time=3600` recreates that race every hour even when nothing else is deploying.

This is the best explanation in the code for a restart loop that left no application error. It is not a claim about the November outage; the log from that night is not in the repo. An OOM or a full disk (items 4 and 5) would also look like a restart loop.

**Fixed** so only a process whose first argument is `frankenphp` migrates. Workers still wait for MySQL and still cache config. Compose already waits for the app to be healthy before starting them, and the app does not pass its healthcheck until migrate has finished and `/up` answers.

Effort: done. Follow-up, not done: if `APP_KEY` is empty and `AA_SECRET_KEY` is empty, the entrypoint mints a new random key on every start of every container. The Alliance Auth snippet derives one stable key from `AA_SECRET_KEY`. A standalone `.env` with a blank `APP_KEY` does not. Refuse to boot in that case instead of generating a key. That change was left out because a stack that currently boots with a blank key would start crash-looping until a key is set.

### 3. The killmail listener does not resume, and a failed startup wedges it

`app/Console/Commands/Killmails/ListenForKillmails.php`:

- HTTP errors are caught and the loop continues, so a blip does not kill the process. That part is fine.
- `zKillboardCacheKey` only stores a restart flag. The sequence is never written. `getStartingSequence()` always fetches the latest id, so a restart skips every kill that arrived while it was down. The weekly `app:get-killmails-for-last-90-days` backfill is the only catch-up, and it is a full 90-day download (`routes/console.php`).
- If the latest-sequence request throws, the command returns `0`. The main loop treats a 404 as "caught up" and retries the same id forever (`catchup_delay_ms`, default 6s). The process stays up, so Docker will not restart it, and no further kills are ingested until someone recreates the container.

**Not changed.** Persist the last good sequence in the shared cache (Redis, once item 6 is done), retry the latest-sequence call instead of falling back to 0, and exit non-zero if it still cannot be fetched so Docker restarts the container. Add a feature test around the startup failure. Medium change: the listener, the cache enum, and tests. Do not start from 0.

### 4. The listener stores and queues the whole EVE kill stream

`ListenForKillmails::processKillmail()` keeps every kill whose solar system exists in the SDE, for `ZKILLBOARD_MAX_AGE_DAYS` (default 730, `config/services.php`). Each new row logs at info and dispatches `EvaluateKillmailAlertsJob`. That job does return immediately when no killmail alerts exist (`app/Jobs/MapAlerts/EvaluateKillmailAlertsJob.php`), but only after the row has been written and a queue job has been inserted. `app/Console/Commands/Killmails/PurgeOldKillmailsCommand.php` deletes by `time` once a day, so the table holds the full window.

On a MariaDB that also serves Alliance Auth, that is steady insert/delete traffic, a multi-gigabyte `killmails` table (JSON `data` and `zkb` on every row), and a `jobs` table that stays busy even when nobody has an alert. The info log line per kill, with no rotation, is a separate way to fill the disk. Standalone compose now caps json-file logs; the Alliance Auth snippet does not, because forcing `json-file` there would override a daemon log driver the rest of the stack might use. Add the same `max-size` / `max-file` options on that deploy if the daemon is still the unlimited json-file default.

**Not changed.** Gate the job on "any active killmail alert" with a short cache. Default the retention to a few days unless a map actually needs history. Log at debug, or only log kills in wormhole space. Large change if retention is cut on a database that already has the 730-day table: the next purge becomes one very large delete. Batch it.

### 5. `memory_limit = 8G` on a shared host

`docker/php.ini` sets `memory_limit = 8G`, `max_execution_time = 300`, and 64MB uploads. The limit is a ceiling, not a reservation, but one request or command can grow until the kernel kills a process. `GenerateStaticDataCommand` is both a Docker build step (`docker/Dockerfile`) and a daily scheduled command (`routes/console.php`). It loads SDE tables in memory and writes `resources/static`, which is baked into the image and is not the storage volume. The daily run repeats a build task inside the scheduler container and the result disappears on recreate.

**Not changed.** Drop the daily schedule. Lower `memory_limit` to something the host can survive (512M is enough for request handling; measure the static-data command before deciding it needs more, and keep that higher limit on the build, not on every worker). Small diff, but the wrong number will fatal a real request, so confirm peak usage first.

### 6. The Alliance Auth stack does not actually use Redis for cache or queues

`config/cache.php` defaults to `database`. `config/queue.php` defaults to `database`. `.env.example` sets `CACHE_STORE=redis` but leaves `QUEUE_CONNECTION=database`. `docker-compose.allianceauth.snippet.yml` sets `REDIS_HOST` and never sets either driver, so a stack that only has the Alliance Auth env file uses MySQL for cache locks, `ShouldBeUnique`, `withoutOverlapping`, and the queue.

That matches item 4: every kill is a database job, and two workers poll `jobs`. The `jobs` migration (`database/migrations/0001_01_01_000002_create_jobs_table.php`) indexes `queue` only. Laravel's claim query also filters `reserved_at` and `available_at`.

**Not changed.** Set `CACHE_STORE=redis` and, after the database queue is drained, `QUEUE_CONNECTION=redis`. Switching the queue driver first strands jobs already in MySQL. Add the composite jobs index only if the database driver stays. Small config change, one-time operational step.

## Medium

### 7. Browser Reverb settings were read with `env()` after config is cached

`docker/entrypoint.sh` runs `php artisan optimize`. Laravel then does not load `.env`. `resources/views/app.blade.php` called `env('VITE_REVERB_*')` with a fallback of the page host, port 443 on HTTPS, and the page scheme. Container environment variables can still be visible through `getenv`, so a correctly injected `VITE_REVERB_HOST` may already work. The fallback is still the wrong host: the readme puts the site and the websocket server on different names, and a missing variable connects the map to the page host.

**Fixed.** `config/reverb.php` `client` captures `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, and `VITE_REVERB_SCHEME` at optimize time. The view reads `config('reverb.client.*')` and keeps the same fallbacks. `tests/Feature/ReverbClientConfigTest.php` covers the rendered page. In-cluster `REVERB_HOST` / `REVERB_PORT` are unchanged (the app publishes to `reverb:8080`).

Effort: done.

### 8. Image build context could include secrets

There was no `.dockerignore`. `COPY . /app` in the builder would ship `.git`, a local `.env`, `vendor`, and `storage` if someone built on a machine that had them. GitHub's checkout does not include `.env`, but a local build would bake it into a layer.

**Fixed** in `.dockerignore`. Supercronic's download in the same Dockerfile now checks SHA-256 for the four v0.2.38 binaries.

Effort: done.

### 9. Standalone `REVERB_PORT` was defined twice

`.env.example` set `REVERB_PORT=8091` for the published port and later `REVERB_PORT=8080` for the container. Compose interpolates the same file for `"${REVERB_PORT}:8080"`, and the later value wins, so the host port became 8080. Inside the container, 8080 is what Reverb actually binds (`--port=8080`). The Alliance Auth snippet already splits these (`WS_REVERB_PORT` vs `REVERB_PORT=8080`) and did not need a change.

**Fixed** with `REVERB_PUBLISH_PORT` (default 8091). An existing standalone `.env` that relied on the accidental 8080 publish should set `REVERB_PUBLISH_PORT` to the port the tunnel already uses.

Effort: done.

### 10. Queue workers had no memory or timeout cap

The compose command was `queue:work --sleep=3 --tries=3 --max-time=3600`. Laravel's own defaults are timeout 60 and memory 128, and `retry_after` on the database driver is 90 (`config/queue.php`). Those defaults were implicit, and nothing recycled a worker by job count. `UpdateCharacterLocation` has no `$timeout` or `$tries`. A stuck ESI call holds a worker until the worker timeout, then the job is retried; that is safe only while timeout stays below `retry_after`.

**Fixed** in both compose files: `--timeout=60 --memory=256 --max-jobs=1000`, still under the 90s `retry_after`. Per-job timeouts for the long ESI and killmail backfill commands are still worth adding if any of them are queued rather than run by the scheduler. The 90-day backfill is a scheduled process, not a queue job, so the worker flags do not bound it.

Effort: done for the worker command. Per-job timeouts are a small follow-up in the job classes.

### 11. Session cookie is not forced onto HTTPS unless configured

`config/session.php` uses `SESSION_SECURE_COOKIE`, which was unset, so the cookie was sent on plain HTTP too. `docker/php.ini` also sets `session.cookie_secure = Off`. Laravel's session driver does not use PHP's native session cookie, so the ini value is not what the browser gets. There is no `Content-Security-Policy`, `X-Frame-Options`, or `Referrer-Policy` anywhere under `app/` or `bootstrap/app.php`.

**Partly fixed:** `.env.example` and the Alliance Auth snippet set `SESSION_SECURE_COOKIE=true`.

**Not done:** security headers. The root view has inline scripts (theme and `window.__reverb`), so a strict CSP needs nonces or hashes and a pass through the map UI. Medium, and easy to break the client. `trustProxies(at: '*')` in `bootstrap/app.php` is appropriate behind the tunnel and wrong if port 8090 is reachable on the LAN: any client there can spoof `X-Forwarded-*`. Prefer publishing `127.0.0.1:8090:80` and `127.0.0.1:8091:8080` when cloudflared shares the host.

### 12. Map page still lazy-loads connection signatures

`app/Scopes/WithVisibleSolarsystems.php` eager-loads placed systems (with signature counts and `details`) and connections. `app/Http/Resources/MapConnectionResource.php` always reads `$this->signatures`, and that relation is not in the `with()`. One query per connection on every map open. `app/Http/Resources/MapResource.php` also reads `mapOwner.accessible` without eager loading (two queries, not an N+1). `app/Http/Middleware/HandleInertiaRequests.php` runs `getMissingScopes()` (characters, missing tracking scopes, corporation, alliance, faction) on every authenticated request, including partial Inertia visits.

`app/Features/MapKillmailsFeature.php` does eager-load ship and victim orgs. The query is `whereIn(solarsystem_id) order by id desc limit 50`. `solarsystem_id` is indexed by the foreign key (`database/migrations/2025_07_06_174305_create_killmails_table.php`); a `(solarsystem_id, id)` index would serve the sort. Confirm with `EXPLAIN` before adding it. `server_statuses` has no index on `created_at` (`database/migrations/2025_08_08_141052_create_server_statuses_table.php`); the table is one row a minute, so this is minor.

Effort: small. Add `mapConnections.signatures` and `mapOwner.accessible` to the existing eager load, and defer `missing_scopes` the way `pinned_maps` is already deferred. One migration if the killmail `EXPLAIN` shows a filesort.

### 13. Image CI publishes `latest` without a gate

`.github/workflows/docker.yml` builds and pushes on every push to `main`, with tag actions, no provenance, and no dependency on the test workflow. `.github/workflows/lint.yml` has `contents: write` for an auto-commit step that is commented out. Neither workflow runs `composer audit` or `npm audit`.

`npm audit --package-lock-only` reported 33 advisories (1 critical, 12 high, 19 moderate, 1 low). The critical finding is `shell-quote` via `concurrently`, a dev-script dependency. Most of the highs are the same kind of toolchain (`vite` dev-server / `launch-editor`, `vitest`, `happy-dom`, `postcss`, `brace-expansion`). `@vue/server-renderer` has a high XSS advisory that matters if Inertia SSR is turned on; the Docker command is `frankenphp`, not the SSR server. PHP advisories were not checked here: `composer` is not installed in this environment, and CI does not run `composer audit` either.

Effort: medium. Run `composer audit` in the existing test workflow. Treat npm audit as a triage list, not a blind fail, until dev-only findings are separated from the built assets. Pin the Docker actions and build the image only after tests pass. Do not block publishes on the current dev-toolchain noise without reading each advisory.

### 14. Reverb accepts any browser origin

`config/reverb.php` sets `allowed_origins` to `*`. Private channels still authorize: `routes/channels.php` checks `view` on the map and that `User.{id}` is the current user. `SameSite=lax` keeps the session cookie off a cross-site websocket, so this is not an open map. It is still wider than a private install needs.

Effort: small. Set the allowed origin from `APP_URL`.

### 15. No rate limiting

Nothing under `app/` registers a rate limiter. `routes/api.php` serves `sovereignties` with no auth (cached public EVE data, `Cache-Control` of one day, so low risk) and the map API with Sanctum and no throttle. Login and OAuth callbacks are unthrottled.

Effort: small. Add `throttle` on `login`, the OAuth callbacks, and `auth:sanctum`.

## Low

### Scheduler sub-minute tasks already run

`routes/console.php` schedules character locations every five seconds and online checks every thirty seconds. Laravel 13's `schedule:run` keeps running repeatable events until the end of the minute (`ScheduleRunCommand::repeatEvents` in framework 13.19). The supercronic file (`* * * * *` in `docker/Dockerfile`) is the right cadence. `-overlapping` has to stay: `schedule:run` does not exit until the minute ends, and without the flag supercronic would skip the next minute.

The overlap at the minute boundary can start a second `schedule:run`. Commands that use `withoutOverlapping` are protected. `GetKillmailsForLast90DaysCommand` is weekly, `runInBackground`, and has no overlap lock. A backfill that runs longer than a week would start a second copy. Add `withoutOverlapping` with a long expiry when that command is next touched.

### Redis and MariaDB credentials

Redis has no password and no published port. MariaDB's root password defaults to the application password (`docker-compose.yml` and the snippet). Both are reachable by anything else on the compose network. Fine on a network that is only this stack; weak next to the rest of an Alliance Auth compose project. Separate the root password and set `REDIS_PASSWORD` if other containers share the network. Small.

### Caddy admin port

2019 is localhost-only in Caddy's default config and is not published by compose. The new healthcheck does not need it. Turning the admin API off is optional once nothing in the image depends on `frankenphp reload`.

### Unused zKillboard identifier

`config/services.php` `zkillboard.identifier` is never read and has a hardcoded default. Delete the key. Trivial.

### Production image includes tinker

`laravel/tinker` is in `require`, so it is in the runtime image. `knuckleswtf/scribe` is too; nothing in `app/` references it at runtime, but confirm the documentation pages before moving it to `require-dev`. Low.

### Native session ini

`docker/php.ini` `session.cookie_secure = Off` does not affect Laravel's cookie. Set it to `On` only if some other PHP session is in use. `upload_max_filesize` / `post_max_size` of 64M is larger than the map UI needs.

### `chown -R` on every boot

The entrypoint recursively chowns `storage` and `bootstrap/cache` before serving. On the named volume that cost grows with logs and cache files. Harmless at current size. Worth remembering if boot time becomes the healthcheck start period.

## Suggested order

1. Publish this image and recreate the six containers. Confirm queue, scheduler, and killmails stay healthy for a few minutes without a compose healthcheck, and that a map still receives live updates (Reverb client config).
2. Set a stable `APP_KEY` (or keep deriving it from `AA_SECRET_KEY`). Do not leave it blank.
3. Point cache at Redis. Drain the database queue, then point the queue at Redis.
4. Stop storing the full kill stream: persist the listener sequence, do not fall back to sequence 0, and skip the alert job when no alert exists. Lower retention only with a batched purge.
5. Remove the daily `generate:static-data` schedule and lower `memory_limit` after checking the static-data command's peak.
6. Eager-load connection signatures, add the security headers, and bind the published ports to localhost.
