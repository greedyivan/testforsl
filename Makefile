.PHONY: up up-d down test test-fast logs shell lint \
        up-laravel up-laravel-d down-laravel test-laravel shell-laravel

# Поднять полный локальный стек (с пересборкой)
up:
	docker compose -f infra/docker-compose.yml up --build

# Поднять в фоне
up-d:
	docker compose -f infra/docker-compose.yml up --build -d

# Остановить и удалить volumes
down:
	docker compose -f infra/docker-compose.yml down -v

# Запустить тест-сьют
test:
	docker compose -f infra/docker-compose.test.yml run --rm tests

# Тесты без пересборки образов
test-fast:
	docker compose -f infra/docker-compose.test.yml run --rm tests pytest tests/ -q

# Логи всех сервисов
logs:
	docker compose -f infra/docker-compose.yml logs -f

# Shell внутри app-контейнера
shell:
	docker compose -f infra/docker-compose.yml exec app bash

# Линтинг + type-check (запускается внутри тест-контейнера)
lint:
	docker compose -f infra/docker-compose.test.yml run --rm tests \
		sh -c "ruff check app/ && mypy app/"

# ── Laravel ─────────────────────────────────────────────────────────────────
# Поднять стек с Laravel-реализацией
up-laravel:
	docker compose -f infra/docker-compose.yml \
	               -f infra/docker-compose.laravel.override.yml up --build

up-laravel-d:
	docker compose -f infra/docker-compose.yml \
	               -f infra/docker-compose.laravel.override.yml up --build -d

down-laravel:
	docker compose -f infra/docker-compose.yml \
	               -f infra/docker-compose.laravel.override.yml down -v

# Запустить тест-сьют для Laravel (полная пересборка)
test-laravel:
	docker compose -f infra/docker-compose.test.laravel.yml build --no-cache
	docker compose -f infra/docker-compose.test.laravel.yml run --rm tests

# Запустить тесты без пересборки
test-laravel-fast:
	docker compose -f infra/docker-compose.test.laravel.yml run --rm tests

# Shell внутри Laravel app-контейнера
shell-laravel:
	docker compose -f infra/docker-compose.yml \
	               -f infra/docker-compose.laravel.override.yml exec app bash
