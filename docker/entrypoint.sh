#!/bin/sh
set -e

echo "Setting up Laravel storage directories..."
mkdir -p /app/storage/framework/sessions /app/storage/framework/views /app/storage/framework/cache /app/storage/logs /app/bootstrap/cache
chown -R www-data:www-data /app/storage /app/bootstrap/cache
chmod -R 775 /app/storage /app/bootstrap/cache

# Auto-generate or derive APP_KEY if not explicitly provided
if [ -z "$APP_KEY" ]; then
    if [ -n "$AA_SECRET_KEY" ]; then
        echo "Auto-deriving APP_KEY from AA_SECRET_KEY..."
        export APP_KEY="base64:$(php -r 'echo base64_encode(hash("sha256", getenv("AA_SECRET_KEY"), true));')"
    else
        echo "Auto-generating APP_KEY..."
        export APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
    fi
fi

echo "Waiting for database to be ready..."
max_retries=30
count=0
until php -r 'try { $dsn = "mysql:host=" . getenv("DB_HOST") . ";port=" . (getenv("DB_PORT") ?: 3306) . ";charset=utf8mb4"; new PDO($dsn, getenv("DB_USERNAME"), getenv("DB_PASSWORD"), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); } catch (Throwable $e) { exit(1); } exit(0);'; do
	count=$((count+1))
	if [ "$count" -ge "$max_retries" ]; then
		echo "Database not available after $max_retries attempts, exiting."
		exit 1
	fi
	echo "Waiting for DB ($count/$max_retries)..."
	sleep 2
done

echo "Running Laravel migrations..."
php artisan migrate --force

echo "Clearing and caching Laravel configurations..."
php artisan optimize:clear
php artisan optimize

if [ "$1" = "frankenphp" ] && [ -n "$DISCORD_APPLICATION_ID" ]; then
	echo "Registering Discord slash commands globally..."
	php artisan discord:register-commands --global || true
	php artisan discord:restart || true
fi

echo "Starting application..."
exec docker-php-entrypoint "$@"
