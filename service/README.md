# Notification Service — FastAPI

Реализация Notification Service на Python 3.12 + FastAPI с нативным asyncio на всех уровнях стека.

## 🚀 Быстрый старт

```bash
# Запустить полный стек (API + воркер + инфраструктура)
docker compose -f infra/docker-compose.yml up --build

# Запустить тесты
docker compose -f infra/docker-compose.test.yml run --rm tests
```

После старта (~30 с):

| Сервис | URL |
|---|---|
| API | http://localhost:8000 |
| Swagger UI | http://localhost:8000/docs |
| RabbitMQ Management | http://localhost:15672 (guest / guest) |

### Makefile

```bash
make up        # поднять стек (с пересборкой образов)
make up-d      # то же, в фоне
make down      # остановить и удалить volumes
make test      # запустить тесты в Docker
make test-fast # тесты без пересборки
make logs      # логи всех сервисов
make lint      # ruff + mypy
```

## 🏗 Компонентная диаграмма

```
┌──────────────────────────────────────────────────────────────────────┐
│                         Docker Network                               │
│                                                                      │
│  ┌─────────────────────┐         ┌──────────────────────────────┐   │
│  │   FastAPI (app)     │         │    Worker (app.worker)       │   │
│  │   port 8000         │         │                              │   │
│  │                     │         │  ┌──────────────────────┐   │   │
│  │  POST /notifications│──pub──► │  │ NotificationConsumer │   │   │
│  │  GET  /subscribers  │         │  │  prefetch_count=1    │   │   │
│  │  GET  /health       │         │  └──────────┬───────────┘   │   │
│  │  GET  /ready        │         │             │               │   │
│  └────────┬────────────┘         │    ┌────────┴──────────┐    │   │
│           │                      │    │  ProviderFactory  │    │   │
│           │                      │    │  SmsMockProvider  │    │   │
│           │                      │    │  EmailMockProvider│    │   │
│           │                      │    └───────────────────┘    │   │
│           │                      └──────────────────────────────┘   │
│           │                                                          │
│     ┌─────▼──────────────────────────────────────────────────────┐  │
│     │                      RabbitMQ 3.13                         │  │
│     │                                                             │  │
│     │  [notifications]          x-max-priority: 10               │  │
│     │   transactional → p=10    x-dead-letter-exchange: dlx      │  │
│     │   marketing    → p=1                                        │  │
│     │                                                             │  │
│     │  [notifications.retry]    x-message-ttl: 30000 ms          │  │
│     │                           x-dead-letter → [notifications]  │  │
│     │                                                             │  │
│     │  [notifications.dead]     финальный DLQ                    │  │
│     └────────────────────────────────────────────────────────────┘  │
│                                                                      │
│     ┌──────────────────────┐   ┌──────────────────────────────────┐ │
│     │     PostgreSQL 16    │   │           Redis 7                │ │
│     │                      │   │                                  │ │
│     │  notifications table │   │  ik:api:{key}   → JSON response  │ │
│     │  (SaEnum-валидация)  │   │  ik:worker:{id} → "1"            │ │
│     │                      │   │  mock:*         → test controls  │ │
│     └──────────────────────┘   └──────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────┘
```

## 📂 Слои приложения

```
HTTP Request
  → FastAPI Route (api/routes/)
  → NotificationService (services/)          ← IdempotencyService (Redis, Level 1)
      → NotificationRepository (repositories/) ← PostgreSQL (asyncpg)
      → NotificationPublisher (messaging/)    → RabbitMQ (aio-pika, с приоритетом)

RabbitMQ Message
  → NotificationConsumer (messaging/)        ← IdempotencyService (Redis, Level 2)
      → ProviderFactory (providers/)
          → SmsMockProvider / EmailMockProvider
      → NotificationRepository (mark_delivered / mark_dropped)
```

## 📁 Структура проекта

```
service/
├── app/
│   ├── main.py                         # FastAPI-приложение, lifespan, /health, /ready
│   ├── worker.py                       # Точка входа воркера (python -m app.worker)
│   ├── config.py                       # pydantic-settings: Settings с валидацией
│   ├── api/
│   │   ├── dependencies.py             # FastAPI Depends: get_db, get_notification_service
│   │   └── routes/
│   │       ├── notifications.py        # POST /api/v1/notifications/bulk
│   │       └── subscribers.py         # GET /api/v1/subscribers/{id}/notifications
│   ├── cache/
│   │   ├── client.py                   # create_redis_client()
│   │   └── constants.py               # RedisKeys — все ключи в одном месте
│   ├── db/
│   │   ├── session.py                 # DatabaseManager, get_db_manager()
│   │   └── migrations/                # Alembic (одна миграция 001_initial)
│   ├── messaging/
│   │   ├── connection.py              # declare_topology(), get_robust_connection()
│   │   ├── publisher.py               # NotificationPublisher, MessagePriority
│   │   ├── consumer.py                # NotificationConsumer: полный pipeline доставки
│   │   └── schemas.py                 # QueueMessage (Pydantic)
│   ├── models/
│   │   └── notification.py            # Notification ORM + StrEnum: Channel/Type/Status
│   ├── providers/
│   │   ├── base.py                    # BaseNotificationProvider, исключения
│   │   ├── factory.py                 # ProviderFactory — lookup-table
│   │   ├── mock_base.py               # MockNotificationProvider, MockBehavior
│   │   ├── sms_mock.py                # SmsMockProvider
│   │   └── email_mock.py              # EmailMockProvider
│   ├── repositories/
│   │   └── notification_repository.py # CRUD + атомарные переходы статусов
│   └── services/
│       ├── notification_service.py    # Оркестрация: idempotency + persist + publish
│       ├── idempotency_service.py     # Двухуровневая дедупликация через Redis
│       └── republish_stuck.py        # Повторная публикация застрявших уведомлений
├── tests/
│   ├── integration/                   # Тесты с реальными сервисами (pytest + httpx)
│   │   ├── conftest.py
│   │   ├── test_notification_flow.py  # Полный flow: API → RabbitMQ → БД → провайдер
│   │   └── test_priority.py           # Приоритизация transactional > marketing
│   └── unit/
│       ├── test_idempotency.py
│       └── test_provider_mocks.py
├── Dockerfile
├── entrypoint.sh                     # alembic upgrade head с retry-логикой
├── pyproject.toml                    # ruff (py312) + mypy (strict-lite)
├── pytest.ini
├── alembic.ini
└── requirements.txt
```

## ⚙️ Конфигурация

Все параметры — через переменные окружения (pydantic-settings, `app/config.py`).

| Переменная | По умолчанию | Описание |
|---|---|---|
| `DATABASE_URL` | — | PostgreSQL DSN (`postgresql+asyncpg://...`) |
| `RABBITMQ_URL` | — | AMQP URL (`amqp://...`) |
| `REDIS_URL` | — | Redis URL (`redis://...`) |
| `MAX_RETRIES` | `3` | Максимальное число попыток доставки |
| `RETRY_TTL_MS` | `30000` | TTL retry-задержки (мс) |
| `IDEMPOTENCY_TTL` | `86400` | TTL Redis-ключей идемпотентности (сек) |
| `REPUBLISH_INTERVAL_SECONDS` | `60` | Интервал проверки застрявших уведомлений |
| `DEBUG` | `false` | SQLAlchemy echo + debug-логи |

## 🔄 Потоки данных

### 1. Входящий запрос на массовую рассылку

```
POST /api/v1/notifications/bulk
  │
  ├─► IdempotencyService.get_api_response(key)
  │     Redis GET ik:api:{key}
  │     ── HIT  → 202, кэшированный ответ (без БД)
  │     ── MISS → продолжить
  │
  ├─► session.begin()
  │     NotificationRepository.create_many(...)
  │     INSERT INTO notifications ... (status='QUEUED')
  │     idempotency_key = {caller_key}:{recipient_id}
  │     UNIQUE constraint — вторая линия защиты
  │   session.commit()
  │
  ├─► NotificationPublisher.publish(...) × N
  │     aio_pika.Message(priority=10|1, delivery_mode=PERSISTENT)
  │     default_exchange.publish(routing_key="notifications")
  │
  ├─► IdempotencyService.set_api_response(key, response)
  │     Redis SET ik:api:{key} {json} EX 86400
  │
  └─► HTTP 202 { batch_id, accepted, notifications[] }
```

### 2. Обработка сообщения воркером

```
RabbitMQ → NotificationConsumer._handle_message(message)
  │
  ├─ QueueMessage.model_validate_json(body)
  ├─ _get_retry_count(headers["x-death"])
  │    ── >= MAX_RETRIES → mark_dropped, ACK
  │
  ├─ IdempotencyService.try_claim_worker(notification_id)
  │    Redis SET ik:worker:{id} 1 NX EX 86400
  │    ── NX fail + retry_count=0 → дубликат → ACK
  │
  ├─ NotificationRepository.try_claim_for_processing(id)
  │    UPDATE SET status='SENT', sent_at=now() WHERE status IN ('QUEUED','SENT')
  │    ── NULL → терминальный статус → ACK
  │
  ├─ ProviderFactory.get(channel).send(payload)
  │    ── TemporaryProviderError:
  │         increment_retry_count(id)
  │         NACK(requeue=False) → DLX → retry-очередь (TTL) → main
  │
  │    ── PermanentProviderError:
  │         mark_dropped(id, error)
  │         ACK
  │
  └─ mark_delivered(id)
     ACK
```

### 3. Retry-цикл (DLX)

```
notifications [NACK] → notifications.dlx → notifications.retry
                                                x-message-ttl: 30 000 мс

notifications.retry [TTL] → default exchange → notifications
                                                 (с исходным priority)
```

После `MAX_RETRIES` смертей воркер переводит уведомление в `DROPPED` и отправляет ACK.

## 🔑 Redis-ключи

Все ключи централизованы в `app/cache/constants.py`.

| Ключ | Тип | TTL | Назначение |
|---|---|---|---|
| `ik:api:{idempotency_key}` | String (JSON) | 24 ч | Кэш ответа API-уровня |
| `ik:worker:{notification_id}` | String | 24 ч | Dedup на уровне воркера |
| `mock:sms:behavior:{recipient_id}` | String | — | Поведение SMS-заглушки (тесты) |
| `mock:sms:fail_count:{recipient_id}` | Integer | — | Счётчик оставшихся сбоев |
| `mock:sms:calls` | List | — | Лог вызовов SMS-провайдера |
| `mock:sms:call_count:{notification_id}` | Integer | — | Кол-во вызовов |
| `mock:email:*` | — | — | Аналогично для Email |

## 🔑 Ключевые архитектурные решения

### Нативный asyncio на всех уровнях

Все I/O-операции полностью асинхронные: asyncpg, aio-pika, redis.asyncio. Worker — отдельный asyncio event-loop (`python -m app.worker`).

### Двухуровневая идемпотентность

- **Level 1 (API):** Redis SET EX на `ik:api:{idempotency_key}` — предотвращает повторные запросы клиента
- **Level 2 (Worker):** Redis SET NX EX на `ik:worker:{notification_id}` — защита от at-least-once redelivery брокера
- Вторичная защита: атомарный `UPDATE ... WHERE status IN ('QUEUED','SENT')` в БД

### Публикация после коммита транзакции

`session.commit()` → публикация в RabbitMQ. Воркер никогда не получит `notification_id`, которого нет в БД.

### prefetch_count = 1

Критично для корректной приоритизации: каждый следующий ACK заставляет брокера выдать самое высокоприоритетное из оставшихся сообщений.

### Republish-loop

Worker запускает `_republish_loop` как фоновую asyncio-задачу. Каждые `REPUBLISH_INTERVAL_SECONDS` находит уведомления в статусе `QUEUED` старше 60 секунд и повторно публикует их в RabbitMQ. Дублирование исключается двухуровневой идемпотентностью.

### SaEnum(native_enum=False) — хранение имён, а не значений

Поля `channel`, `type`, `status` хранятся через `SaEnum(..., native_enum=False)` как VARCHAR без PostgreSQL ENUM-типа.

> **⚠️ Важно для ручных операций с БД и миграций:**
> `SaEnum(native_enum=False)` хранит **имена** Python-членов enum в UPPERCASE, а **не** их строковые значения:
>
> | Python | Хранится в PostgreSQL | JSON API |
> |---|---|---|
> | `NotificationChannel.SMS` | `'SMS'` | `'sms'` |
> | `NotificationType.MARKETING` | `'MARKETING'` | `'marketing'` |
> | `NotificationStatus.QUEUED` | `'QUEUED'` | `'queued'` |
>
> При raw INSERT/UPDATE через `psql`, `asyncpg` или тестовые фикстуры используйте **UPPERCASE**.
> JSON API при этом возвращает lowercase-значения через Pydantic-сериализацию (`StrEnum`).

### ProviderFactory — lookup-table

Провайдеры хранятся в словаре `{channel: provider}`. Добавление нового канала — создание подкласса и регистрация в фабрике без изменения Consumer (Open/Closed Principle).

## 📊 Модель данных

```sql
CREATE TABLE notifications (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    batch_id        UUID         NOT NULL,
    recipient_id    VARCHAR(255) NOT NULL,
    channel         VARCHAR(10)  NOT NULL,   -- 'SMS' | 'EMAIL' (SaEnum имена, не значения)
    type            VARCHAR(20)  NOT NULL,   -- 'TRANSACTIONAL' | 'MARKETING'
    message         TEXT         NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'QUEUED',
    idempotency_key VARCHAR(255),            -- {caller_key}:{recipient_id}
    retry_count     INT          NOT NULL DEFAULT 0,
    error_message   TEXT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT now(),
    sent_at         TIMESTAMPTZ,
    delivered_at    TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_notifications_idempotency_key
    ON notifications (idempotency_key) NULLS NOT DISTINCT
    WHERE idempotency_key IS NOT NULL;
CREATE INDEX ix_notifications_recipient_created           ON notifications(recipient_id, created_at);
CREATE INDEX ix_notifications_batch                       ON notifications(batch_id);
CREATE INDEX ix_notifications_status                      ON notifications(status);
CREATE INDEX ix_notifications_recipient_status_created    ON notifications(recipient_id, status, created_at);
CREATE INDEX ix_notifications_recipient_channel_created   ON notifications(recipient_id, channel, created_at);
```

### Переходы статусов

```
             ┌──────────┐
  (создание) │  queued  │
             └────┬─────┘
                  │ try_claim_for_processing()
             ┌────▼─────┐
             │   sent   │ ◄── retry не меняет статус
             └────┬─────┘
          ┌───────┴────────┐
     (успех)           (ошибка)
          │                │
    ┌─────▼──────┐   ┌─────▼──────┐
    │ delivered  │   │  dropped   │
    └────────────┘   └────────────┘
```

## 🧪 Тесты

43 теста (integration + unit), запускаются в Docker-окружении с реальными сервисами.

```bash
# Все тесты
docker compose -f infra/docker-compose.test.yml run --rm tests

# Только unit-тесты (без Docker)
cd service && pytest tests/unit/ -v

# Линтинг и типы
cd service && ruff check . && mypy .
```

## ⚠️ Ограничения

- Mock-провайдеры имитируют реальные SMS/Email шлюзы. Для production реализовать реальные провайдеры в `app/providers/`
- Воркер — одиночный asyncio event-loop; горизонтальное масштабирование через запуск нескольких экземпляров worker-контейнера (идемпотентность гарантирует корректность)

## 🔗 Связанные документы

| Документ | Описание |
|---|---|
| [../README.md](../README.md) | Общий обзор проекта |
| [../docs/SERVICE_CONTRACT.md](../docs/SERVICE_CONTRACT.md) | API-контракт |
| [../docs/COMPARISON.md](../docs/COMPARISON.md) | Сравнение с Laravel-реализацией |
| [../docs/infra/INFRASTRUCTURE.md](../docs/infra/INFRASTRUCTURE.md) | Инфраструктура |
