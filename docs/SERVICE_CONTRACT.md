# Service Contract

Единый API-контракт, которому соответствуют обе реализации сервиса (FastAPI и Laravel).

## 🌐 Базовый URL

| Реализация | URL |
|---|---|
| FastAPI (prod) | `http://localhost:8000` |
| Laravel (prod) | `http://localhost:8000` |
| Laravel (test) | `http://localhost:8001` |

Swagger UI доступен по адресу `/docs`. JSON-спека OpenAPI — `/api-docs.json` (Laravel) или `/openapi.json` (FastAPI).

## 📋 Эндпоинты

### POST /api/v1/notifications/bulk

Принимает запрос на массовую рассылку уведомлений. Обработка асинхронная — ответ 202 возвращается немедленно.

**Request:**

```json
{
  "channel": "sms",
  "type": "transactional",
  "recipient_ids": ["user-1", "user-2", "user-3"],
  "message": "Ваш код подтверждения: 1234",
  "idempotency_key": "batch-2024-001"
}
```

| Поле | Тип | Обязательное | Ограничения |
|---|---|---|---|
| `channel` | string | да | `sms` или `email` |
| `type` | string | да | `transactional` или `marketing` |
| `recipient_ids` | array of string | да | 1–1000 элементов, уникальные |
| `message` | string | да | 1–4096 символов |
| `idempotency_key` | string | да | 1–255 символов; повторный запрос с тем же ключом возвращает исходный ответ |

**Response 202 Accepted:**

```json
{
  "batch_id": "550e8400-e29b-41d4-a716-446655440000",
  "accepted": 3,
  "notifications": [
    {
      "notification_id": "...",
      "recipient_id": "user-1",
      "status": "queued"
    }
  ]
}
```

**Response 422 Unprocessable Entity** — ошибка валидации.

---

### GET /api/v1/subscribers/{subscriberId}/notifications

Возвращает историю уведомлений конкретного подписчика с опциональной фильтрацией.

**Path параметры:**

| Параметр | Тип | Описание |
|---|---|---|
| `subscriberId` | string | Идентификатор получателя |

**Query параметры:**

| Параметр | Тип | Обязательное | Значения |
|---|---|---|---|
| `status` | string | нет | `queued`, `sent`, `delivered`, `dropped` |
| `channel` | string | нет | `sms`, `email` |
| `limit` | int | нет | 1–200, по умолчанию 50 |
| `offset` | int | нет | ≥0, по умолчанию 0 |

**Response 200 OK:**

```json
{
  "subscriber_id": "user-1",
  "total": 2,
  "notifications": [
    {
      "notification_id": "...",
      "batch_id": "...",
      "channel": "sms",
      "type": "transactional",
      "message": "Ваш код: 1234",
      "status": "delivered",
      "retry_count": 0,
      "error_message": null,
      "created_at": "2024-01-15T10:00:00Z",
      "sent_at": "2024-01-15T10:00:01Z",
      "delivered_at": "2024-01-15T10:00:02Z"
    }
  ]
}
```

---

### GET /health

Liveness probe. Всегда возвращает 200.

**Response 200 OK:**

```json
{"status": "ok"}
```

---

### GET /ready

Readiness probe. Проверяет доступность зависимостей.

**Response 200 OK** — все зависимости доступны.

**Response 503 Service Unavailable** — одна или несколько зависимостей недоступны.

Ответ всегда содержит поле `checks` с результатом каждой проверки (PostgreSQL, Redis, RabbitMQ).

```json
{
  "status": "ok",
  "checks": {
    "postgres": "ok",
    "redis": "ok",
    "rabbitmq": "ok"
  }
}
```

---

### GET /docs

Swagger UI (HTML). Интерактивная документация API.

### GET /api-docs.json

OpenAPI-спека в формате JSON (Laravel). FastAPI использует `/openapi.json`.

---

## 📊 Статусы уведомлений

| Статус | Описание |
|---|---|
| `queued` | Принято в очередь, ожидает обработки |
| `sent` | Передано провайдеру (в процессе доставки) |
| `delivered` | Подтверждено провайдером |
| `dropped` | Ошибка доставки (постоянная ошибка или исчерпание попыток) |

Переходы: `queued` → `sent` → `delivered` или `dropped`.

---

## 💻 Примеры curl-запросов

### Отправить транзакционное SMS

```bash
curl -X POST http://localhost:8000/api/v1/notifications/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "sms",
    "type": "transactional",
    "recipient_ids": ["user-001", "user-002"],
    "message": "Ваш код: 7890",
    "idempotency_key": "tx-sms-2024-001"
  }'
```

### Отправить маркетинговый Email

```bash
curl -X POST http://localhost:8000/api/v1/notifications/bulk \
  -H "Content-Type: application/json" \
  -d '{
    "channel": "email",
    "type": "marketing",
    "recipient_ids": ["user-100", "user-101", "user-102"],
    "message": "Специальное предложение только сегодня!",
    "idempotency_key": "marketing-email-2024-01-15"
  }'
```

### Проверить историю уведомлений подписчика

```bash
curl "http://localhost:8000/api/v1/subscribers/user-001/notifications"
```

### Фильтрация по статусу и каналу

```bash
curl "http://localhost:8000/api/v1/subscribers/user-001/notifications?status=delivered&channel=sms"
```

### Проверка готовности

```bash
curl http://localhost:8000/health
curl http://localhost:8000/ready
```

---

## 🔗 Топология RabbitMQ

Сервис объявляет топологию при старте (идемпотентно):

| Объект | Тип | Ключевые параметры |
|---|---|---|
| `notifications` | Queue | `x-max-priority=10`, `x-dead-letter-exchange=notifications.dlx` |
| `notifications.dlx` | Exchange (direct) | durable |
| `notifications.retry` | Queue | `x-message-ttl=RETRY_TTL_MS`, DLX → default exchange, routing-key = `notifications` |
| `notifications.dead.exchange` | Exchange (fanout) | durable |
| `notifications.dead` | Queue | bound to fanout |

Приоритеты: `transactional` → 10, `marketing` → 1. Worker использует `prefetch_count=1`.

---

## 🗄 Схема таблицы notifications

```sql
notifications (
  id              UUID PRIMARY KEY,
  batch_id        UUID NOT NULL,
  recipient_id    VARCHAR(255) NOT NULL,
  channel         VARCHAR(10) NOT NULL,      -- 'sms' | 'email'
  type            VARCHAR(20) NOT NULL,      -- 'transactional' | 'marketing'
  message         TEXT NOT NULL,
  status          VARCHAR(20) NOT NULL,      -- 'queued'|'sent'|'delivered'|'dropped'
  idempotency_key VARCHAR(255) UNIQUE,       -- {caller_key}:{recipient_id}
  retry_count     INT NOT NULL DEFAULT 0,
  error_message   TEXT,
  created_at      TIMESTAMPTZ NOT NULL,
  sent_at         TIMESTAMPTZ,
  delivered_at    TIMESTAMPTZ,
  updated_at      TIMESTAMPTZ NOT NULL
)

-- Индексы
CREATE INDEX ix_notifications_batch                        ON notifications(batch_id);
CREATE INDEX ix_notifications_status                       ON notifications(status);
CREATE INDEX ix_notifications_recipient_created            ON notifications(recipient_id, created_at);
CREATE INDEX ix_notifications_recipient_status_created     ON notifications(recipient_id, status, created_at);
CREATE INDEX ix_notifications_recipient_channel_created    ON notifications(recipient_id, channel, created_at);
```

---

## ⚙️ Контракт инфраструктуры

Любая реализация должна:

| Требование | Значение |
|---|---|
| Dockerfile | В корне директории сервиса |
| Liveness | `GET /health` → 200 |
| Readiness | `GET /ready` → 200 / 503 |
| Порт | 8000 |
| Переменные | `DATABASE_URL`, `RABBITMQ_URL`, `REDIS_URL`, `MAX_RETRIES`, `RETRY_TTL_MS`, `IDEMPOTENCY_TTL` |
| Миграции | Выполняются самостоятельно при старте, до приёма трафика |

## Связанные документы

- [README.md](../README.md) — общий обзор проекта
- [docs/COMPARISON.md](COMPARISON.md) — сравнение реализаций
- [docs/infra/INFRASTRUCTURE.md](infra/INFRASTRUCTURE.md) — инфраструктура
- [service/docs/ARCHITECTURE.md](../service/docs/ARCHITECTURE.md) — архитектура FastAPI
- [service-laravel/README.md](../service-laravel/README.md) — руководство по Laravel
