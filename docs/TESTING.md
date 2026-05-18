# Test Coverage

Подробное описание тестового покрытия обеих реализаций Notification Service.

## 📊 Общая статистика

| Параметр | FastAPI | Laravel |
|---|---|---|
| Всего тестов | **43** | **42** |
| Feature / Integration | 31 | 33 |
| Unit | 12 | 9 |
| Тестовая среда | Docker Compose (реальные сервисы) | Docker Compose (реальные сервисы) |
| Тестовый фреймворк | pytest + anyio | PHPUnit |
| HTTP-клиент | httpx.AsyncClient | GuzzleHttp\Client |
| Redis-клиент | redis.asyncio | Predis |
| DB-клиент (для direct inserts) | asyncpg | PDO (pdo_pgsql) |

Единственная асимметрия (1 тест): Laravel имеет `test_release_allows_reclaim` в `IdempotencyServiceTest`, проверяющий явный `releaseWorkerClaim()`. В FastAPI эквивалентного публичного метода нет — сброс claim происходит имплицитно внутри воркера при TemporaryProviderError и покрыт интеграционным тестом retry-механизма.

---

## 🗂 Структура тестов

### FastAPI

```
service/tests/
├── integration/
│   ├── conftest.py              # Фикстуры: http_client, redis_client, amqp_channel
│   ├── test_notification_flow.py  # 21 тест: основной flow
│   ├── test_priority.py           #  4 теста: очередь с приоритетами
│   └── test_system.py             #  3 теста: health/ready + republish
└── unit/
    ├── test_idempotency.py        #  6 тестов: IdempotencyService
    └── test_provider_mocks.py     #  9 тестов: SmsMockProvider + EmailMockProvider
```

### Laravel

```
service-laravel/tests/
├── Feature/
│   ├── BulkSendTest.php           #  3 теста
│   ├── DeliveryFlowTest.php       #  3 теста
│   ├── HealthTest.php             #  2 теста
│   ├── IdempotencyTest.php        #  2 теста
│   ├── PriorityTest.php           #  4 теста
│   ├── RepublishStuckTest.php     #  1 тест
│   ├── RetryMechanismTest.php     #  3 теста
│   ├── StatusHistoryTest.php      #  5 тестов
│   ├── ValidationTest.php         #  3 теста
│   └── WorkerDeduplicationTest.php #  1 тест
├── Unit/
│   ├── IdempotencyServiceTest.php #  7 тестов
│   └── MockProviderTest.php       #  8 тестов
└── Support/
    └── IntegrationTestCase.php    # Базовый класс
```

---

## 🧪 Детальное описание тестов

### 1. Массовая рассылка (BulkSend)

**FastAPI** `TestBulkSend` (3 теста) / **Laravel** `BulkSendTest` (3 теста)

| Тест | Что проверяется |
|---|---|
| `test_returns_202_with_notification_list` | POST возвращает 202, `accepted` = числу получателей, все статусы = `queued` |
| `test_each_recipient_gets_own_notification` | Каждый recipient_id присутствует в ответе ровно один раз |
| `test_batch_id_is_consistent` | `batch_id` в ответе POST совпадает с `batch_id` в истории подписчика |

---

### 2. Валидация входных данных (Validation)

**FastAPI** `TestValidation` (3 теста) / **Laravel** `ValidationTest` (3 теста)

| Тест | Что проверяется |
|---|---|
| `test_invalid_channel_returns_422` | Несуществующий канал (`telegram`) → 422 Unprocessable Entity |
| `test_missing_recipient_ids_returns_422` | Отсутствующее поле `recipient_ids` → 422 |
| `test_empty_recipient_ids_returns_422` | Пустой массив `recipient_ids: []` → 422 |

---

### 3. Полный цикл доставки (DeliveryFlow)

**FastAPI** `TestDeliveryFlow` (3 теста) / **Laravel** `DeliveryFlowTest` (3 теста)

| Тест | Что проверяется |
|---|---|
| `test_sms_notification_reaches_delivered` | SMS-уведомление проходит path `queued→sent→delivered`; `delivered_at` и `sent_at` заполнены |
| `test_email_notification_reaches_delivered` | Email-уведомление достигает статуса `delivered` |
| `test_transactional_notification_delivered` | Уведомление типа `transactional` доставляется; в ответе `type = "transactional"` |

---

### 4. История уведомлений подписчика (StatusHistory)

**FastAPI** `TestStatusHistory` (5 тестов) / **Laravel** `StatusHistoryTest` (5 тестов)

| Тест | Что проверяется |
|---|---|
| `test_get_subscriber_notifications_returns_all` | GET возвращает все уведомления; `subscriber_id` корректен; `total >= 2` после двух рассылок |
| `test_status_filter_works` | Query-параметр `?status=delivered` фильтрует: все возвращённые уведомления имеют `status = delivered` |
| `test_channel_filter_works` | Query-параметр `?channel=sms` фильтрует: все возвращённые уведомления имеют `channel = sms` |
| `test_total_count_reflects_actual_rows` | Без пагинации `total == len(notifications)` — поле `total` точно отражает число строк |
| `test_pagination_with_limit_and_offset` | `limit=2, offset=0` → 2 записи при `total=3`; `limit=2, offset=2` → 1 запись при `total=3` |

---

### 5. Идемпотентность API (Idempotency)

**FastAPI** `TestIdempotency` (2 теста) / **Laravel** `IdempotencyTest` (2 теста)

| Тест | Что проверяется |
|---|---|
| `test_duplicate_api_request_returns_same_response` | Повторный POST с тем же `idempotency_key` → тот же `batch_id` и `accepted` |
| `test_duplicate_api_request_creates_no_extra_notifications` | Повторный POST не создаёт вторую запись в БД (`total = 1`) |

---

### 6. Дедупликация воркера (WorkerDeduplication)

**FastAPI** `TestWorkerDeduplication` (1 тест) / **Laravel** `WorkerDeduplicationTest` (1 тест)

| Тест | Что проверяется |
|---|---|
| `test_worker_deduplication_on_redelivery` | При повторной доставке (at-least-once) воркер вызывает провайдера ровно один раз. Сценарий: уведомление доставлено → публикуется повторно в очередь вручную → счётчик вызовов провайдера остаётся `1` |

Механизм проверки: Redis-ключ `mock:sms:call_count:{notification_id}` не должен увеличиться после второй доставки (Redis SET NX в воркере блокирует повторный вызов).

---

### 7. Retry-механизм (RetryMechanism)

**FastAPI** `TestRetryMechanism` (3 теста) / **Laravel** `RetryMechanismTest` (3 теста)

| Тест | Что проверяется |
|---|---|
| `test_temporary_failure_retried_and_delivered` | При 2 временных ошибках (`temporary_fail`, `fail_count=2`) уведомление в итоге доставляется; `retry_count >= 2` |
| `test_permanent_failure_marks_dropped` | При постоянной ошибке (`permanent_fail`) уведомление немедленно переходит в `dropped`; `error_message` заполнен |
| `test_max_retries_exceeded_marks_dropped` | При бесконечном временном сбое (без `fail_count`) уведомление переходит в `dropped` после `MAX_RETRIES` попыток; `retry_count >= 3` |

---

### 8. Приоритеты очереди (Priority)

**FastAPI** `TestQueueConfiguration` + `TestPriorityOrdering` (4 теста) / **Laravel** `PriorityTest` (4 теста)

| Тест | Что проверяется |
|---|---|
| `test_main_queue_has_priority_argument` | RabbitMQ Management API подтверждает `x-max-priority=10` на очереди `notifications` |
| `test_retry_queue_has_ttl` | Очередь `notifications.retry` имеет параметр `x-message-ttl` |
| `test_transactional_processed_before_bulk_marketing` | 30 marketing (slow) + 1 transactional: transactional обрабатывается до большинства marketing; позиция в логе вызовов < `n_marketing / 2` |
| `test_transactional_type_stored_correctly` | Уведомление типа `transactional` хранится и возвращается API с `type = "transactional"` |

---

### 9. Health & Readiness (Health)

**FastAPI** `TestHealth` (2 теста) / **Laravel** `HealthTest` (2 теста)

| Тест | Что проверяется |
|---|---|
| `test_liveness_always_returns_200` | `GET /health` возвращает 200 и `{"status": "ok"}` |
| `test_readiness_returns_200_when_all_deps_healthy` | `GET /ready` возвращает 200, `status = "ok"`, поля `checks.postgres`, `checks.redis`, `checks.rabbitmq` все равны `"ok"` |

---

### 10. Восстановление застрявших уведомлений (RepublishStuck)

**FastAPI** `TestRepublishStuck` (1 тест) / **Laravel** `RepublishStuckTest` (1 тест)

| Тест | Что проверяется |
|---|---|
| `test_stuck_notification_gets_republished_and_delivered` | Уведомление, вставленное напрямую в БД с `created_at = 2000-01-01` (минуя RabbitMQ), в итоге доставляется воркером |

**Сценарий:** имитируется publish-after-commit gap. Строка вставляется через прямое подключение к БД (asyncpg / PDO), bypassing очередь. Republish-сервис (интервал 10 с в тестовом окружении) обнаруживает её через `older-than=60` и публикует в RabbitMQ. Воркер обрабатывает её как обычную задачу и переводит в `delivered`.

**Защита от двойной обработки:** двухуровневая идемпотентность (Redis SET NX + атомарный UPDATE WHERE status IN ('queued','sent')) предотвращает повторную доставку даже если republisher запустится несколько раз.

---

### 11. Сервис идемпотентности — Unit (IdempotencyService)

**FastAPI** `TestApiIdempotency` + `TestWorkerIdempotency` (6 тестов) / **Laravel** `IdempotencyServiceTest` (7 тестов)

| Тест | Что проверяется |
|---|---|
| `test_get_returns_none_for_unknown_key` | `get_api_response(unknown)` → `None` / `null` |
| `test_set_and_get_round_trip` | `set_api_response` → `get_api_response` возвращает то же значение |
| `test_second_set_overwrites_first` | Повторный вызов `set_api_response` перезаписывает значение (поведение SET, не SET NX) |
| `test_first_claim_returns_true` | `try_claim_worker(new_id)` → `True` |
| `test_second_claim_same_id_returns_false` | Повторный `try_claim_worker(same_id)` → `False` (SET NX) |
| `test_different_ids_both_succeed` | Два разных ID — оба claim успешны |
| `test_release_allows_reclaim` *(только Laravel)* | `release_worker_claim(id)` удаляет ключ → следующий `try_claim_worker(id)` возвращает `True` |

> **Примечание:** `test_release_allows_reclaim` присутствует только в Laravel, т.к. `releaseWorkerClaim()` — публичный метод, явно вызываемый при TemporaryProviderException для освобождения claim перед retry. В FastAPI сброс claim инкапсулирован внутри consumer и не требует отдельного unit-теста (покрывается интеграционным `test_temporary_failure_retried_and_delivered`).

---

### 12. Mock-провайдеры — Unit (MockProvider)

**FastAPI** `TestMockProvider` (параметризован × 2) + `TestSmsMockProviderExtra` + `TestEmailMockProviderExtra` (9 тестов) / **Laravel** `MockProviderTest` (8 тестов)

| Тест | SMS | Email | Что проверяется |
|---|---|---|---|
| `test_success_records_call` | ✅ | ✅ | Успешный вызов логирует `notification_id` в `mock:{channel}:calls` |
| `test_temporary_fail_raises_exception` | ✅ | ✅ | При `behavior=temporary_fail` → `TemporaryProviderError` / `TemporaryProviderException` |
| `test_permanent_fail_raises_exception` | ✅ | ✅ | При `behavior=permanent_fail` → `PermanentProviderError` / `PermanentProviderException` |
| `test_fail_count_decrements_to_success` | ✅ | ✅ | `fail_count=1`: первый вызов — ошибка, второй — успех (Lua-атомарный декремент) |

> **FastAPI особенность:** `TestMockProvider` параметризован через `@pytest.mark.parametrize` — одни и те же 4 теста запускаются для `SmsMockProvider` и `EmailMockProvider`, итого 8 тестов в классе. Плюс 2 отдельных класса `TestSmsMockProviderExtra` и `TestEmailMockProviderExtra` для `fail_count` — 1 тест каждый. Итого 10 тестов уровня unit для провайдеров.
>
> **Laravel:** 8 тестов без параметризации — SMS и Email проверяются раздельными методами в одном классе.

---

## 🔧 Запуск тестов

### FastAPI

```bash
# Все тесты (43)
docker compose -f infra/docker-compose.test.yml run --rm tests

# Только unit-тесты (без Docker)
cd service && pytest tests/unit/ -v

# Только интеграционные тесты
docker compose -f infra/docker-compose.test.yml run --rm --entrypoint="" tests \
    pytest tests/integration/ -v --testdox

# Конкретный тест
docker compose -f infra/docker-compose.test.yml run --rm --entrypoint="" tests \
    pytest tests/integration/test_notification_flow.py::TestValidation -v
```

### Laravel

```bash
# Все тесты (42)
docker compose -f infra/docker-compose.test.laravel.yml run --rm tests

# Только unit-тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --testsuite=unit --testdox

# Только feature-тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --testsuite=feature --testdox

# Конкретный тест
docker compose -f infra/docker-compose.test.laravel.yml run --rm --entrypoint="" tests \
    php vendor/bin/phpunit --filter=test_stuck_notification --testdox
```

---

## 🏗 Инфраструктура тестов

### Изоляция тестов

Каждый тест использует уникальные `recipient_id` (генерируются через UUID/random), поэтому тесты не мешают друг другу при параллельном запуске.

После каждого теста:
- **FastAPI:** Redis очищается от ключей `mock:*` и `ik:*` через `redis_client` fixture teardown
- **Laravel:** `IntegrationTestCase::tearDown()` выполняет SCAN по паттернам `mock:*` и `ik:*` и удаляет найденные ключи

### Параметры тестового окружения

| Переменная | Значение в тестах | Назначение |
|---|---|---|
| `MAX_RETRIES` | `3` | Число попыток доставки |
| `RETRY_TTL_MS` | `5000` | TTL retry-задержки (5 с вместо 30 с) |
| `IDEMPOTENCY_TTL` | `300` | TTL ключей идемпотентности (5 мин) |
| `REPUBLISH_INTERVAL_SECONDS` | `10` | Интервал republish-loop (только FastAPI, 10 с вместо 60 с) |

### Docker-сервисы в тестовом стеке

| Сервис | FastAPI | Laravel | Назначение |
|---|---|---|---|
| `postgres` | ✅ | ✅ | База данных |
| `rabbitmq` | ✅ | ✅ | Брокер сообщений |
| `redis` | ✅ | ✅ | Кэш идемпотентности + mock-контроль |
| `app` | ✅ | ✅ | API-сервер (миграции, healthcheck) |
| `worker` | ✅ | ✅ | Обработчик очереди |
| `republisher` | — | ✅ | Периодический republish (bash loop, 10 с) |

> **FastAPI:** republish-loop запускается внутри процесса воркера как фоновая asyncio-задача (`asyncio.create_task`). Отдельный контейнер не нужен.
>
> **Laravel:** republish-loop реализован как отдельный Docker-сервис `republisher`, запускающий `php artisan notifications:republish-stuck` каждые 10 секунд. Сервис запускается только после того, как `app` пройдёт healthcheck (миграции применены).

---

## 🎯 Матрица покрытия

| Функциональный блок | FastAPI | Laravel |
|---|---|---|
| POST /api/v1/notifications/bulk (202) | ✅ | ✅ |
| Валидация входных данных (422) | ✅ | ✅ |
| SMS доставка | ✅ | ✅ |
| Email доставка | ✅ | ✅ |
| Transactional тип | ✅ | ✅ |
| GET /subscribers/{id}/notifications | ✅ | ✅ |
| Фильтрация по статусу | ✅ | ✅ |
| Фильтрация по каналу | ✅ | ✅ |
| Пагинация (limit/offset/total) | ✅ | ✅ |
| API-идемпотентность (Redis Level 1) | ✅ | ✅ |
| Worker-дедупликация (Redis Level 2) | ✅ | ✅ |
| Retry при временном сбое | ✅ | ✅ |
| Dropped при постоянном сбое | ✅ | ✅ |
| Dropped при исчерпании попыток | ✅ | ✅ |
| Приоритет очереди (x-max-priority=10) | ✅ | ✅ |
| Приоритет transactional > marketing | ✅ | ✅ |
| GET /health (liveness) | ✅ | ✅ |
| GET /ready (readiness + checks) | ✅ | ✅ |
| Republish застрявших уведомлений | ✅ | ✅ |
| IdempotencyService unit | ✅ | ✅ |
| SmsMockProvider unit (все сценарии) | ✅ | ✅ |
| EmailMockProvider unit (все сценарии) | ✅ | ✅ |
| fail_count декремент до успеха (SMS) | ✅ | ✅ |
| fail_count декремент до успеха (Email) | ✅ | ✅ |
| releaseWorkerClaim unit | *(integration)* | ✅ |

## Связанные документы

- [README.md](../README.md) — общий обзор проекта
- [docs/SERVICE_CONTRACT.md](SERVICE_CONTRACT.md) — API-контракт
- [docs/COMPARISON.md](COMPARISON.md) — сравнение реализаций
- [service/docs/ARCHITECTURE.md](../service/docs/ARCHITECTURE.md) — архитектура FastAPI
- [service-laravel/README.md](../service-laravel/README.md) — руководство по Laravel
