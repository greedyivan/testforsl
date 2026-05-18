#!/bin/bash
set -e

echo "[entrypoint] Запуск миграций Alembic…"

# При одновременном старте нескольких контейнеров возникает гонка при создании
# таблицы alembic_version. Retry-логика устраняет эту проблему без
# архитектурных изменений.
MAX_ATTEMPTS=5
for attempt in $(seq 1 $MAX_ATTEMPTS); do
    if alembic upgrade head; then
        break
    fi
    if [ "$attempt" -eq "$MAX_ATTEMPTS" ]; then
        echo "[entrypoint] Миграция не выполнена после $MAX_ATTEMPTS попыток, аварийное завершение."
        exit 1
    fi
    echo "[entrypoint] Попытка миграции $attempt не удалась (гонка при запуске?), повтор через ${attempt}с…"
    sleep "$attempt"
done

echo "[entrypoint] Миграции выполнены."
exec "$@"
