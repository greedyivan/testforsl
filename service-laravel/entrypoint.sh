#!/usr/bin/env bash
set -euo pipefail

MAX_ATTEMPTS=15
attempt=1

echo "[entrypoint] Running migrations..."
until php artisan migrate --force 2>&1; do
    echo "[entrypoint] Migration attempt $attempt failed, retrying in 3 s..."
    attempt=$((attempt + 1))
    if [ "$attempt" -gt "$MAX_ATTEMPTS" ]; then
        echo "[entrypoint] Migrations failed after $MAX_ATTEMPTS attempts. Aborting."
        exit 1
    fi
    sleep 3
done
echo "[entrypoint] Migrations complete."

echo "[entrypoint] Generating Swagger docs..."
php artisan l5-swagger:generate --all 2>/dev/null || true

case "${1:-serve}" in
    serve)
        # Восстанавливаем уведомления, застрявшие в статусе 'queued' после возможного
        # краша между коммитом транзакции и публикацией в RabbitMQ (publish-after-commit gap).
        echo "[entrypoint] Republishing stuck notifications..."
        php artisan notifications:republish-stuck --older-than=60 || true

        echo "[entrypoint] Starting API server on :8000"
        exec php artisan serve --host=0.0.0.0 --port=8000
        ;;
    *)
        echo "[entrypoint] Starting: php artisan $*"
        exec php artisan "$@"
        ;;
esac
