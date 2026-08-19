#!/bin/sh
# Runtime bootstrap for the production app container.
#
# Every step must either succeed or abort the container. A half-booted app
# that silently runs without a config cache, or against an unmigrated
# database, is worse than one that refuses to start and shows up in `ps`.
set -eu

cd /var/www/html

# Filesystem-only clears first: these never touch the database, so they are
# safe on a virgin schema. They also drop any config cache left over from a
# previous boot, which would otherwise pin stale credentials during migrate.
echo "[entrypoint] Clearing stale compiled caches..."
php artisan config:clear
php artisan view:clear
php artisan event:clear
php artisan clear-compiled

echo "[entrypoint] Running database migrations..."
php artisan migrate --force --no-interaction

# Only now is the `cache` table guaranteed to exist (CACHE_STORE=database).
# Flushing it on boot drops settings cached by the previous release.
echo "[entrypoint] Flushing application cache..."
php artisan cache:clear

echo "[entrypoint] Creating storage symlink..."
php artisan storage:link --force

# Config must be cached at runtime, not at build time, because .env is
# bind-mounted. Failure is fatal: an uncached config means every request
# re-parses .env and env() calls inside config files stop being frozen.
echo "[entrypoint] Caching config, views and events..."
php artisan config:cache
php artisan view:cache
php artisan event:cache

# route:cache is intentionally skipped: routes/api.php uses closure routes,
# which cannot be serialized. Move them into controllers to enable it.

echo "[entrypoint] Starting supervisor..."
exec "$@"
