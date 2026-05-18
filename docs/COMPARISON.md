# Сравнение реализаций: FastAPI vs Laravel

Этот документ сравнивает две реализации Notification Service по требованиям из технического задания.

## 📋 Таблица соответствия требованиям

| Требование | FastAPI | Laravel | Примечание |
|---|---|---|---|
| **Функциональные** | | | |
| API массовой рассылки (SMS/Email) | ✅ Реализовано | ✅ Реализовано | `POST /api/v1/notifications/bulk` — идентичный интерфейс |
| Передача канала, текста, списка получателей | ✅ Реализовано | ✅ Реализовано | Поля `channel`, `message`, `recipient_ids` |
| Приоритизация: transactional обгоняет marketing | ✅ Реализовано | ✅ Реализовано | RabbitMQ `x-max-priority=10`; transactional=10, marketing=1; `prefetch_count=1` |
| Статус «в очереди» (queued) | ✅ Реализовано | ✅ Реализовано | Начальный статус при создании уведомления |
| Статус «отправлено» (sent) | ✅ Реализовано | ✅ Реализовано | Атомарный `UPDATE ... WHERE status IN ('queued','sent')` |
| Статус «доставлено» (delivered) | ✅ Реализовано | ✅ Реализовано | Выставляется после успешного вызова провайдера |
| Статус «отброшено» (dropped) | ✅ Реализовано | ✅ Реализовано | Выставляется при постоянной ошибке или исчерпании попыток |
| API истории уведомлений подписчика | ✅ Реализовано | ✅ Реализовано | `GET /api/v1/subscribers/{id}/notifications` |
| Фильтрация по статусу и каналу | ✅ Реализовано | ✅ Реализовано | Query-параметры `status`, `channel` |
| Пагинация (`limit`, `offset`) | ✅ Реализовано | ✅ Реализовано | `limit` (1–200, по умолчанию 50), `offset` (≥0); `total` — полный count до пагинации |
| **Нефункциональные** | | | |
| Персистентность очереди (брокер сообщений) | ✅ Реализовано | ✅ Реализовано | RabbitMQ: `durable=True`, `delivery_mode=PERSISTENT` |
| At-least-once доставка | ✅ Реализовано | ✅ Реализовано | ACK только после успешного вызова провайдера + записи в БД |
| Exactly-once на уровне бизнес-логики | ✅ Реализовано | ✅ Реализовано | Двухуровневая идемпотентность (Redis + атомарный UPDATE в БД) |
| Retry при временных сбоях | ✅ Реализовано | ✅ Реализовано | FastAPI: NACK→DLX→retry-очередь (TTL); Laravel: native Queue retry с backoff |
| Дедупликация (Idempotency) | ✅ Реализовано | ✅ Реализовано | API-уровень: Redis SET NX по `idempotency_key`; Worker-уровень: Redis SET NX по `notification_id` |
| Интеграционные тесты | ✅ Реализовано | ✅ Реализовано | FastAPI: 43 pytest-теста; Laravel: 42 PHPUnit-теста (Feature + Unit) |
| Тесты всей цепочки (очередь→БД→провайдер) | ✅ Реализовано | ✅ Реализовано | Оба сервиса тестируют полный flow с реальными Docker-сервисами |
| Docker-образ | ✅ Реализовано | ✅ Реализовано | Каждый сервис имеет свой `Dockerfile` |
| Запуск одной командой docker-compose | ✅ Реализовано | ✅ Реализовано | FastAPI: `docker compose -f infra/docker-compose.yml up`; Laravel: с override-файлом |
| PostgreSQL в качестве БД | ✅ Реализовано | ✅ Реализовано | postgres:16-alpine |
| RabbitMQ в качестве брокера | ✅ Реализовано | ✅ Реализовано | rabbitmq:3.13-management-alpine |
| Redis для дедупликации | ✅ Реализовано | ✅ Реализовано | redis:7-alpine |
| Mock-провайдеры (SMS и Email) | ✅ Реализовано | ✅ Реализовано | Поведение управляется через Redis-ключи (совместимые между реализациями) |
| **Дополнительные / бонусные** | | | |
| Swagger / OpenAPI-документация | ✅ Реализовано | ✅ Реализовано | FastAPI: автогенерация; Laravel: L5-Swagger + `GET /docs`, `GET /api-docs.json` |
| Liveness probe (`GET /health`) | ✅ Реализовано | ✅ Реализовано | Всегда возвращает 200 |
| Readiness probe (`GET /ready`) | ✅ Реализовано | ✅ Реализовано | FastAPI: проверяет Redis + RabbitMQ; Laravel: проверяет PostgreSQL + Redis + RabbitMQ |
| Композитные индексы БД | ✅ Реализовано | ✅ Реализовано | `(recipient_id, created_at)`, `batch_id`, `status` |
| Уникальный ключ идемпотентности в БД | ✅ Реализовано | ✅ Реализовано | `UNIQUE(idempotency_key)` — вторая линия защиты |
| Восстановление «застрявших» уведомлений | ✅ Реализовано | ✅ Реализовано | FastAPI: `_republish_loop` в worker (интервал из `REPUBLISH_INTERVAL_SECONDS`); Laravel: artisan-команда при старте |
| Детализированные проверки readiness по зависимостям | ✅ Реализовано | ✅ Реализовано | Оба: PostgreSQL + Redis + RabbitMQ с отдельными статусами в поле `checks` |

## 🏗 Архитектурные особенности

### Асинхронность

**FastAPI** построен на нативном asyncio Python. Все I/O-операции (PostgreSQL через asyncpg, Redis через redis.asyncio, RabbitMQ через aio-pika) — полностью асинхронные. Worker — отдельный процесс (`python -m app.worker`) с бесконечным event-loop.

**Laravel** использует синхронные клиенты (Predis, php-amqplib, Eloquent). Worker запускается как `queue:work rabbitmq` (отдельный PHP-процесс). Отсутствие нативного event-loop компенсируется моделью отдельных процессов.

### Retry-механизм

**FastAPI** реализует DLX-топологию RabbitMQ вручную: при `TemporaryProviderError` сообщение получает NACK без requeue, попадает в DLX, затем в `notifications.retry` (TTL 30 с), откуда возвращается в основную очередь. Счётчик попыток считывается из заголовка `x-death`.

**Laravel** использует нативный механизм Laravel Queue: `$tries = MAX_RETRIES + 1`, метод `backoff()`. При временном сбое: Redis-claim освобождается, статус сбрасывается в `queued`, исключение пробрасывается — Laravel сам повторяет job. При исчерпании попыток вызывается `failed()`.

### Идемпотентность Worker

**FastAPI**: при повторной доставке Redis SET NX возвращает `False` только на первой попытке (retry_count=0). Вторичная защита — атомарный `UPDATE ... WHERE status IN ('queued','sent')` в БД.

**Laravel**: при повторной доставке SET NX возвращает `False` только на первом `attempts()=1`. При временном сбое Redis-claim явно освобождается (`releaseWorkerClaim`) для следующей попытки.

### Публикация после коммита

Оба сервиса реализуют паттерн «publish-after-commit»: сначала транзакция в БД, затем публикация в RabbitMQ. Это гарантирует, что воркер никогда не получит `notification_id`, которого ещё нет в БД.

**Laravel** дополнительно реализует `RepublishStuckNotifications` — artisan-команду для восстановления уведомлений, которые были зафиксированы в БД, но не попали в очередь из-за краша между коммитом и публикацией.

### Mock-провайдеры

Обе реализации используют совместимые Redis-ключи для управления поведением mock-провайдеров из тестов. Это позволяет запускать одни и те же интеграционные сценарии против обоих сервисов.

**Laravel** добавляет атомарный Lua-скрипт для декремента `fail_count` — защита от race condition при нескольких воркерах.

### Структура топологии RabbitMQ

Оба сервиса объявляют одинаковую топологию при старте:
- Основная очередь `notifications` (`x-max-priority=10`, `x-dead-letter-exchange`)
- Retry-очередь `notifications.retry` (TTL → возврат в основную)
- DLX exchange `notifications.dlx`
- Dead-letter очередь `notifications.dead`

## ✅ Итог

| Критерий | FastAPI | Laravel |
|---|---|---|
| Функциональные требования | ✅ Полностью | ✅ Полностью |
| Нефункциональные требования | ✅ Полностью | ✅ Полностью |
| Интеграционные тесты | ✅ 43 теста | ✅ 42 теста |
| Swagger / OpenAPI | ✅ Автогенерация | ✅ L5-Swagger |
| Пагинация в GET /subscribers | ✅ limit+offset | ✅ limit+offset |
| Readiness по всем зависимостям | ✅ PG+Redis+RMQ | ✅ PG+Redis+RMQ |
| Восстановление застрявших задач | ✅ Фоновый loop | ✅ Artisan-команда |

**FastAPI** — нативно-асинхронная реализация с автоматической Swagger-документацией и периодическим republish-loop в воркере.

**Laravel** — реализация с `RepublishStuckNotifications` artisan-командой (запуск при старте), атомарным Lua-скриптом в mock-провайдере и более детальной структурой проекта.

Обе реализации полностью покрывают все требования технического задания, имеют идентичный API-контракт и запускаются через Docker Compose без изменения инфраструктурного слоя.

## Связанные документы

- [README.md](../README.md) — общий обзор проекта
- [docs/SERVICE_CONTRACT.md](SERVICE_CONTRACT.md) — единый API-контракт
- [service/docs/ARCHITECTURE.md](../service/docs/ARCHITECTURE.md) — архитектура FastAPI
- [service-laravel/README.md](../service-laravel/README.md) — руководство по Laravel
- [docs/infra/INFRASTRUCTURE.md](infra/INFRASTRUCTURE.md) — инфраструктура
