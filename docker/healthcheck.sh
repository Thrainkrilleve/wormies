#!/bin/sh
# Role-aware healthcheck for the shared image.
#
# dunglas/frankenphp ships HEALTHCHECK curl -f http://localhost:2019/metrics.
# Only the web process opens the Caddy admin port, so queue, scheduler, and
# killmail containers that reuse the image stay unhealthy unless compose
# overrides the check. Set WORMHOLES_ROLE to app, reverb, queue, scheduler,
# killmails, or discord-access to skip process detection.
set -eu

role="${WORMHOLES_ROLE:-}"

if [ -z "$role" ]; then
    if pgrep -f '[a]rtisan queue:work' >/dev/null 2>&1; then
        role=queue
    elif pgrep -f '[a]rtisan reverb:start' >/dev/null 2>&1; then
        role=reverb
    elif pgrep -f '[a]rtisan app:listen-for-killmails' >/dev/null 2>&1; then
        role=killmails
    elif pgrep -f '[a]rtisan discord:access-listen' >/dev/null 2>&1; then
        role=discord-access
    elif pgrep -f '[s]upercronic' >/dev/null 2>&1; then
        role=scheduler
    elif pgrep -x frankenphp >/dev/null 2>&1 || pgrep -f '[f]rankenphp run' >/dev/null 2>&1; then
        role=app
    else
        echo "healthcheck: no app, reverb, queue, scheduler, killmail, or discord access process" >&2
        exit 1
    fi
fi

case "$role" in
    app)
        curl --fail --silent --show-error --max-time 4 http://127.0.0.1:80/up >/dev/null
        ;;
    reverb)
        curl --fail --silent --show-error --max-time 4 http://127.0.0.1:8080/up >/dev/null
        ;;
    queue)
        pgrep -f '[a]rtisan queue:work' >/dev/null 2>&1
        ;;
    scheduler)
        pgrep -f '[s]upercronic' >/dev/null 2>&1
        ;;
    killmails)
        pgrep -f '[a]rtisan app:listen-for-killmails' >/dev/null 2>&1
        ;;
    discord-access)
        pgrep -f '[a]rtisan discord:access-listen' >/dev/null 2>&1
        ;;
    *)
        echo "healthcheck: unknown role: $role" >&2
        exit 1
        ;;
esac
