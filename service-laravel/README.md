# Notification Service — Laravel

Реализация Notification Service на PHP 8.3 + Laravel 11 с идентичным API-контрактом относительно FastAPI-версии.

## 🚀 Быстрый старт

```bash
# Запустить все тесты (рекомендуемый способ)
docker compose -f infra/docker-compose.test.laravel.yml run --rm tests

# Поднять prod-стек (Laravel вместо FastAPI)
docker compose -f infra/docker-compose.yml \
               -f infra/docker-compose.laravel.override.yml up --build
```

После старта (~60 с):

| Сервис | URL |
|---|---|
| API | http://localhost:8000 |
| Swagger UI | http://localhost:8000/docs |
| RabbitMQ Management | http://localhost:15672 (guest / guest) |

## 🏗 Компонентная диаграмма

```
┌──────────────────────────────────────────────────────────────────────┐
│                         Docker Network                               │
│                                                                      │
│  ┌─────────────────────┐         ┌──────────────────────────────┐   │
│  │   Laravel (app)     │         │  Worker (artisan queue:work) │   │
│  │   port 8000         │         │                              │   │
│  │                     │         │  ┌──────────────────────┐   │   │
│  │  POST /notifications│──pub──► │  │ProcessNotificationJob│   │   │
│  │  GET  /subscribers  │         │  │  prefetch-count=1    │   │   │
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
│     │  (Laravel Eloquent)  │   │  ik:worker:{id} → "1"            │ │
│     │                      │   │  mock:*         → test controls  │ │
│     └──────────────────────┘   └──────────────────────────────────┘ │
└──────────────────────────────────────────────────────────────────────┘
```

## 📂 Слои приложения

```
HTTP Request
  → BulkSendRequest (валидация)
  → NotificationController
  → NotificationService          ← IdempotencyService (Redis, Level 1)
      → NotificationRepository   ← PostgreSQL (Eloquent)
      → NotificationPublisher    → RabbitMQ (AMQP-приоритет)

RabbitMQ Message
  → ProcessNotificationJob       ← IdempotencyService (Redis, Level 2)
      → ProviderFactory
          → SmsMockProvider / EmailMockProvider
      → NotificationRepository (markDelivered / markDropped)
```

## 📁 Структура проекта

```
service-laravel/
├── app/
│   ├── Console/
│   │   └── Commands/
│   │       └── RepublishStuckNotifications.php  # Восстановление «застрявших» уведомлений
│   ├── Contracts/
│   │   └── NotificationProvider.php             # Интерфейс провайдера
│   ├── Enums/
│   │   ├── NotificationChannel.php              # sms | email
│   │   ├── NotificationStatus.php               # queued | sent | delivered | dropped
│   │   └── NotificationType.php                 # transactional | marketing
│   ├── Exceptions/
│   │   ├── PermanentProviderException.php       # Постоянная ошибка → dropped
│   │   └── TemporaryProviderException.php       # Временная ошибка → retry
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── HealthController.php             # GET /health, GET /ready
│   │   │   ├── NotificationController.php       # POST /api/v1/notifications/bulk
│   │   │   └── SubscriberController.php         # GET /api/v1/subscribers/{id}/notifications
│   │   ├── Requests/
│   │   │   ├── BulkSendRequest.php
│   │   │   └── GetSubscriberNotificationsRequest.php
│   │   └── Resources/
│   │       └── NotificationResource.php
│   ├── Jobs/
│   │   └── ProcessNotificationJob.php           # Queue job: полный pipeline доставки
│   ├── Models/
│   │   └── Notification.php
│   ├── Providers/
│   │   ├── AppServiceProvider.php               # DI, парсинг DATABASE_URL / REDIS_URL
│   │   ├── RabbitMQTopologyProvider.php         # Объявление топологии при старте
│   │   └── Mocks/
│   │       ├── MockNotificationProvider.php     # Базовый класс mock-провайдеров
│   │       ├── SmsMockProvider.php
│   │       └── EmailMockProvider.php
│   ├── Repositories/
│   │   └── NotificationRepository.php
│   └── Services/
│       ├── IdempotencyService.php               # Redis-дедупликация (API + Worker)
│       ├── NotificationPublisher.php            # Публикация в RabbitMQ с AMQP-приоритетом
│       ├── NotificationService.php              # Оркестрация: persist + publish
│       └── ProviderFactory.php                  # Фабрика провайдеров с кэшем экземпляров
├── config/
│   ├── notifications.php                        # Все env-переменные сервиса
│   └── ...
├── database/
│   └── migrations/                              # Одна миграция: create_notifications_table
├── routes/
│   └── api.php                                  # API-маршруты
├── tests/
│   ├── Feature/                                 # Интеграционные тесты (Guzzle → живой app)
│   ├── Unit/                                    # Unit-тесты (IdempotencyService, MockProvider)
│   └── Support/
│       └── IntegrationTestCase.php              # Базовый класс с Guzzle + Predis
├── README.md                                    # Этот файл
└── Dockerfile
```

## ⚙️ Конфигурация

Все параметры сервиса в `config/notifications.php`. В коде (Services, Jobs, Providers) используется только `config()`, никогда `env()` — исключение только конструктор `ProcessNotificationJob`.

| Переменная | По умолчанию | Описание |
|---|---|---|
| `DATABASE_URL` | — | PostgreSQL DSN |
| `RABBITMQ_URL` | — | AMQP URL |
| `REDIS_URL` | — | Redis URL |
| `MAX_RETRIES` | `3` | Максимальное число попыток доставки |
| `RETRY_TTL_MS` | `30000` | TTL retry-задержки (мс) |
| `IDEMPOTENCY_TTL` | `86400` | TTL Redis-ключей идемпотентности (сек) |
| `MAIN_QUEUE` | `notifications` | Имя основной очереди |
| `RETRY_QUEUE` | `notifications.retry` | Имя retry-очереди |
| `DEAD_QUEUE` | `notifications.dead` | Имя dead-letter очереди |
| `DLX_EXCHANGE` | `notifications.dlx` | Dead-letter exchange |
| `DEAD_EXCHANGE` | `notifications.dead.exchange` | Fanout exchange для неисправимых сообщений |
| `APP_KEY` | — | Ключ шифрования Laravel |
| `APP_ENV` | `production` | Окружение |
| `LOG_CHANNEL` | `stderr` | Канал логирования |
| `LOG_LEVEL` | `info` | Уровень логирования |

## 🔄 Потоки данных

### 1. Входящий запрос на массовую рассылку

```
POST /api/v1/notifications/bulk
  │
  ├─► IdempotencyService::getApiResponse($key)
  │     Redis GET ik:api:{key}
  │     ── HIT  → 202, кэшированный ответ (без БД)
  │     ── MISS → продолжить
  │
  ├─► DB::transaction()
  │     NotificationRepository::createMany(...)
  │     INSERT INTO notifications ... (status='queued')
  │     idempotency_key = {caller_key}:{recipient_id}
  │     UNIQUE constraint — вторая линия защиты
  │   commit()
  │
  ├─► NotificationPublisher::publish(...) × N
  │     Queue::connection('rabbitmq')->pushRaw($payload, $queue, ['priority' => $p])
  │
  ├─► IdempotencyService::setApiResponse($key, $response)
  │     Redis SET ik:api:{key} {json} EX 86400
  │
  └─► HTTP 202 { batch_id, accepted, notifications[] }
```

### 2. Обработка сообщения воркером

```
RabbitMQ → ProcessNotificationJob::handle(...)
  │
  ├─ attempts() > MAX_RETRIES + 1 → защитный клапан → return
  │
  ├─ IdempotencyService::tryClaimWorker(notificationId)
  │    Redis SET ik:worker:{id} 1 NX EX 86400
  │    ── NX fail + attempts=1 → дубликат → return
  │
  ├─ NotificationRepository::tryClaimForProcessing(id)
  │    UPDATE SET status='sent', sent_at=now() WHERE status IN ('queued','sent')
  │    ── false → терминальный статус → return
  │
  ├─ ProviderFactory::get(channel)->send($notification)
  │    ── TemporaryProviderException:
  │         incrementRetryCount(id)
  │         releaseWorkerClaim(id)
  │         resetToQueued(id)
  │         throw → Laravel повторяет job (с backoff)
  │
  │    ── PermanentProviderException:
  │         markDropped(id, error)
  │         $this->fail($e) → прекратить попытки
  │
  └─ markDelivered(id)
     ACK (Laravel удаляет job из очереди)
```

### 3. Retry-цикл (DLX)

```
notifications [fail] → notifications.dlx → notifications.retry
                                                x-message-ttl: 30 000 мс

notifications.retry [TTL] → default exchange → notifications
                                                 (с исходным priority)
```

После исчерпания `$tries` вызывается `failed()`, уведомление переходит в `dropped`.

## 🔑 Redis-ключи

Все ключи централизованы в `app/Services/IdempotencyService.php`.

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

### Двухуровневая идемпотентность

- **Level 1 (API):** Redis SET EX на `ik:api:{idempotency_key}` — предотвращает повторные запросы клиента
- **Level 2 (Worker):** Redis SET NX EX на `ik:worker:{notification_id}` — защищает от at-least-once redelivery брокера
- Вторичная защита: атомарный `UPDATE ... WHERE status IN ('queued','sent')` в БД

### Retry-механизм

Laravel native Queue: `$tries = MAX_RETRIES + 1`, метод `backoff()` возвращает задержку из конфига.

При `TemporaryProviderException`:
1. Инкремент `retry_count` в БД
2. Освобождение Redis-claim (`releaseWorkerClaim`) для следующей попытки
3. Сброс статуса в `queued`
4. Проброс исключения — Laravel повторяет job

При `PermanentProviderException` или исчерпании попыток: `markDropped` (идемпотентно).

### Приоритеты

- `transactional` → priority 10, `marketing` → priority 1
- Очередь объявлена с `x-max-priority=10`
- Воркер потребляет с `--prefetch-count=1` для соблюдения приоритизации

### Публикация после коммита

`DB::transaction()` → коммит → публикация в RabbitMQ. Artisan-команда `RepublishStuckNotifications` восстанавливает уведомления, зависшие в `queued` из-за краша между коммитом и публикацией.

### Топология RabbitMQ

Объявляется в `RabbitMQTopologyProvider` при старте (идемпотентно):
- `notifications` — основная очередь с `x-max-priority=10` и DLX
- `notifications.dlx` — dead-letter exchange
- `notifications.retry` — TTL-очередь для повторных попыток
- `notifications.dead.exchange` + `notifications.dead` — для неисправимых сообщений

### Атомарный Lua-скрипт в MockProvider

Декремент `fail_count` в mock-провайдере выполняется через Lua-скрипт для атомарности — защищает от race condition при нескольких воркерах. При отсутствии ключа `fail_count` поведение «сбоить всегда» сохраняется бесконечно.

## 📊 Модель данных

```sql
CREATE TABLE notifications (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    batch_id        UUID         NOT NULL,
    recipient_id    VARCHAR(255) NOT NULL,
    channel         VARCHAR(10)  NOT NULL,   -- 'sms' | 'email'
    type            VARCHAR(20)  NOT NULL,   -- 'transactional' | 'marketing'
    message         TEXT         NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'queued',
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
                  │ tryClaimForProcessing()
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

42 теста (Feature + Unit). Тесты интеграционные — bootstrap без booted Laravel app, Guzzle-клиент к живому app-контейнеру.

```bash
# Запустить все тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm tests

# Только unit-тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --testsuite=unit --testdox

# Только feature-тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --testsuite=feature --testdox

# С подробным выводом
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --testdox --verbose
```

## ⚠️ Ограничения

- `php artisan serve` — development-сервер, не для production. В production использовать FrankenPHP или php-fpm + nginx
- Mock-провайдеры имитируют реальные SMS/Email шлюзы. Для production реализовать реальные провайдеры в `app/Providers/`
- Синхронные клиенты (Predis, php-amqplib, Eloquent) — нет нативного asyncio; компенсируется моделью отдельных процессов

## 🔗 Связанные документы

| Документ | Описание |
|---|---|
| [../README.md](../README.md) | Общий обзор проекта |
| [../docs/SERVICE_CONTRACT.md](../docs/SERVICE_CONTRACT.md) | API-контракт |
| [../docs/COMPARISON.md](../docs/COMPARISON.md) | Сравнение с FastAPI |
| [../docs/infra/INFRASTRUCTURE.md](../docs/infra/INFRASTRUCTURE.md) | Инфраструктура |
