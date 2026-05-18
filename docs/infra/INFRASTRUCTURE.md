# Инфраструктура Notification Service

Описание Docker Compose файлов, переменных окружения и команд запуска для обоих сервисов.

## 🗂 Обзор Compose-файлов

| Файл | Назначение |
|---|---|
| `infra/docker-compose.yml` | Основной стек: инфраструктура + FastAPI-сервис |
| `infra/docker-compose.laravel.override.yml` | Override для замены FastAPI→Laravel в основном стеке |
| `infra/docker-compose.test.yml` | Тестовое окружение для FastAPI (изолированная БД) |
| `infra/docker-compose.test.laravel.yml` | Тестовое окружение для Laravel (изолированная БД) |

### Принцип работы override

Основной стек (`docker-compose.yml`) содержит инфраструктурные сервисы (PostgreSQL, RabbitMQ, Redis) и FastAPI-реализацию. Laravel-реализация подключается через overlay-файл, который переопределяет только сервисы `app` и `worker`:

```bash
# FastAPI (по умолчанию)
docker compose -f infra/docker-compose.yml up

# Laravel (overlay)
docker compose -f infra/docker-compose.yml -f infra/docker-compose.laravel.override.yml up
```

### Изоляция тестовых окружений

Тестовые compose-файлы используют:
- Отдельную БД (`notifications_test` вместо `notifications`)
- Redis DB index 1 (`redis://redis:6379/1` вместо `/0`)
- Короткий `RETRY_TTL_MS=5000` (5 с вместо 30 с) для быстрого прохождения retry-тестов
- Короткий `IDEMPOTENCY_TTL=300` (5 мин вместо 24 ч)

## 🔧 Переменные окружения

### Общие для обоих сервисов

| Переменная | Prod-значение | Test-значение | Описание |
|---|---|---|---|
| `DATABASE_URL` | `postgresql+asyncpg://postgres:postgres@postgres:5432/notifications` | `...notifications_test` | PostgreSQL DSN |
| `RABBITMQ_URL` | `amqp://guest:guest@rabbitmq:5672/` | то же | AMQP URL |
| `REDIS_URL` | `redis://redis:6379/0` | `redis://redis:6379/1` | Redis URL (разные DB-индексы) |
| `MAX_RETRIES` | `3` | `3` | Максимальное число попыток доставки |
| `RETRY_TTL_MS` | `30000` | `5000` | TTL retry-очереди (мс) |
| `IDEMPOTENCY_TTL` | `86400` | `300` | TTL ключей идемпотентности в Redis (сек) |
| `MAIN_QUEUE` | `notifications` | `notifications` | Имя основной очереди |
| `RETRY_QUEUE` | `notifications.retry` | `notifications.retry` | Имя retry-очереди |
| `DEAD_QUEUE` | `notifications.dead` | `notifications.dead` | Имя dead-letter очереди |
| `DLX_EXCHANGE` | `notifications.dlx` | `notifications.dlx` | Dead-letter exchange |
| `DEAD_EXCHANGE` | `notifications.dead.exchange` | `notifications.dead.exchange` | Fanout exchange для poison-сообщений |

### Только для Laravel

| Переменная | Значение | Описание |
|---|---|---|
| `APP_KEY` | `base64:AAA...` | Ключ шифрования Laravel |
| `APP_ENV` | `production` / `testing` | Окружение |
| `APP_DEBUG` | `false` / `true` | Режим отладки |
| `APP_URL` | `http://localhost:8000` | Базовый URL |
| `LOG_CHANNEL` | `stderr` | Канал логирования |
| `LOG_LEVEL` | `info` / `debug` | Уровень логирования |

## 🚀 Команды запуска

### Продакшн-окружение

```bash
# FastAPI
docker compose -f infra/docker-compose.yml up --build

# Laravel
docker compose -f infra/docker-compose.yml \
               -f infra/docker-compose.laravel.override.yml up --build
```

### Тестирование

```bash
# FastAPI — запустить все тесты (pytest)
docker compose -f infra/docker-compose.test.yml run --rm tests

# Laravel — запустить все тесты (PHPUnit)
docker compose -f infra/docker-compose.test.laravel.yml run --rm tests

# Laravel — только unit-тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --testsuite=unit --testdox

# Laravel — только feature-тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --testsuite=feature --testdox
```

### Makefile (быстрые алиасы)

```bash
make up          # FastAPI prod (с пересборкой)
make up-d        # FastAPI prod (в фоне)
make down        # остановить и удалить volumes
make test        # FastAPI тесты в Docker
make test-fast   # FastAPI тесты без пересборки
make logs        # логи всех сервисов
make shell       # bash внутри app-контейнера
make lint        # ruff + mypy (FastAPI)
```

## 🌐 Сервисы и порты

### Продакшн

| Сервис | Порт | URL |
|---|---|---|
| API (FastAPI или Laravel) | 8000 | http://localhost:8000 |
| Swagger UI | 8000 | http://localhost:8000/docs |
| RabbitMQ Management UI | 15672 | http://localhost:15672 (guest/guest) |
| PostgreSQL | 5432 | — |
| Redis | 6379 | — |

### Тестовое окружение (Laravel)

| Сервис | Порт | URL |
|---|---|---|
| API (Laravel test) | 8001 | http://localhost:8001 |
| Swagger UI (Laravel test) | 8001 | http://localhost:8001/docs |

## 🏥 Healthcheck

Все инфраструктурные сервисы имеют healthcheck. Приложение стартует только после их готовности (`condition: service_healthy`).

| Сервис | Healthcheck |
|---|---|
| PostgreSQL | `pg_isready -U postgres -d notifications` |
| RabbitMQ | `rabbitmq-diagnostics ping` |
| Redis | `redis-cli ping` |
| App (FastAPI/Laravel) | `curl -sf http://localhost:8000/health` |

## Связанные документы

- [README.md](../../README.md) — общий обзор и быстрый старт
- [docs/SERVICE_CONTRACT.md](../SERVICE_CONTRACT.md) — контракт между инфраструктурой и сервисами
- [docs/COMPARISON.md](../COMPARISON.md) — сравнение двух реализаций
