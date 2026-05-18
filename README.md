# Notification Service

Асинхронный микросервис массовой отправки уведомлений (SMS / Email) с приоритетными очередями, at-least-once доставкой и двухуровневой идемпотентностью.

Реализован в двух вариантах: **FastAPI (Python)** и **Laravel (PHP)** — с идентичным API-контрактом и инфраструктурой.

## 📦 Два сервиса

### FastAPI (Python)

Нативно-асинхронная реализация на Python 3.12 + FastAPI. Использует asyncpg (PostgreSQL), aio-pika (RabbitMQ) и redis.asyncio. Swagger-документация генерируется автоматически.

Расположение: `service/`

### Laravel (PHP)

Реализация на PHP 8.3 + Laravel 11. Использует Eloquent (PostgreSQL), php-amqplib (RabbitMQ) и Predis. Swagger-документация через L5-Swagger. Дополнительно реализована artisan-команда `RepublishStuckNotifications` для восстановления уведомлений при сбоях.

Расположение: `service-laravel/`

## 🗂 Структура репозитория

```
notification-service/
├── service/                    # FastAPI (Python)
│   ├── app/                    # Код приложения
│   │   ├── api/routes/         # HTTP-роуты
│   │   ├── services/           # Бизнес-логика
│   │   ├── repositories/       # Слой данных
│   │   ├── messaging/          # RabbitMQ publisher/consumer
│   │   ├── providers/          # Mock-провайдеры SMS/Email
│   │   └── ...
│   ├── tests/                  # 43 pytest-теста (integration + unit)
│   ├── docs/
│   │   └── ARCHITECTURE.md     # Архитектура FastAPI-сервиса
│   └── Dockerfile
├── service-laravel/            # Laravel (PHP)
│   ├── app/                    # Код приложения
│   │   ├── Http/               # Controllers, Requests, Resources
│   │   ├── Services/           # Бизнес-логика
│   │   ├── Repositories/       # Слой данных
│   │   ├── Jobs/               # Queue jobs
│   │   └── ...
│   ├── tests/                  # 42 PHPUnit-теста (Feature + Unit)
│   ├── README.md               # Руководство по Laravel-сервису
│   └── Dockerfile
├── infra/                      # Инфраструктура (language-agnostic)
│   ├── docker-compose.yml                    # Prod: FastAPI
│   ├── docker-compose.laravel.override.yml   # Override: Laravel вместо FastAPI
│   ├── docker-compose.test.yml               # Тесты FastAPI
│   └── docker-compose.test.laravel.yml       # Тесты Laravel
├── docs/                       # Документация уровня проекта
│   ├── COMPARISON.md           # Сравнение реализаций
│   ├── SERVICE_CONTRACT.md     # Единый API-контракт
│   ├── notification-service.postman_collection.json  # Postman-коллекция (все эндпоинты)
│   └── infra/
│       └── INFRASTRUCTURE.md   # Документация по инфраструктуре
├── Makefile                    # Команды для локальной разработки
└── README.md
```

## 🚀 Быстрый старт

### FastAPI

```bash
# Запустить полный стек
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

### Laravel

```bash
# Запустить полный стек
docker compose -f infra/docker-compose.yml \
               -f infra/docker-compose.laravel.override.yml up --build

# Запустить тесты
docker compose -f infra/docker-compose.test.laravel.yml run --rm tests
```

После старта (~60 с):

| Сервис | URL |
|---|---|
| API | http://localhost:8000 |
| Swagger UI | http://localhost:8000/docs |
| RabbitMQ Management | http://localhost:15672 (guest / guest) |

### Makefile (FastAPI)

```bash
make up        # поднять стек (с пересборкой образов)
make up-d      # то же, в фоне
make down      # остановить и удалить volumes
make test      # запустить тесты в Docker
make test-fast # тесты без пересборки
make logs      # логи всех сервисов
make lint      # ruff + mypy
```

## 📖 Документация

| Документ | Описание |
|---|---|
| [docs/COMPARISON.md](docs/COMPARISON.md) | Сравнение FastAPI и Laravel по всем требованиям |
| [docs/SERVICE_CONTRACT.md](docs/SERVICE_CONTRACT.md) | Единый API-контракт (эндпоинты, схемы, curl-примеры) |
| [docs/infra/INFRASTRUCTURE.md](docs/infra/INFRASTRUCTURE.md) | Инфраструктура: compose-файлы, переменные, порты |
| [docs/notification-service.postman_collection.json](docs/notification-service.postman_collection.json) | Postman-коллекция для ручного тестирования API |
| [service/README.md](service/README.md) | Руководство по FastAPI-реализации |
| [service/docs/ARCHITECTURE.md](service/docs/ARCHITECTURE.md) | Подробная архитектура FastAPI-сервиса |
| [service-laravel/README.md](service-laravel/README.md) | Руководство по Laravel-реализации |
