import logging
from collections.abc import AsyncGenerator
from contextlib import asynccontextmanager

import aio_pika
import aio_pika.abc
import redis.asyncio as aio_redis
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse
from sqlalchemy import text

# Импортируем конкретный тип Redis для isinstance-проверки в /ready
_RedisClient = aio_redis.Redis

from app.api.routes import notifications, subscribers
from app.cache.client import create_redis_client
from app.db.session import get_db_manager
from app.logging_config import setup_logging
from app.messaging.connection import declare_topology, get_robust_connection

setup_logging()
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI) -> AsyncGenerator[None, None]:
    # ── Запуск ────────────────────────────────────────────────────────────────
    logger.info("Подключение к RabbitMQ…")
    connection: aio_pika.abc.AbstractRobustConnection = await get_robust_connection()
    channel = await connection.channel()
    await declare_topology(channel)
    app.state.amqp_connection = connection
    app.state.amqp_channel = channel

    logger.info("Подключение к Redis…")
    redis_client = await create_redis_client()
    app.state.redis = redis_client

    logger.info("API готов")
    yield

    # ── Завершение ────────────────────────────────────────────────────────────
    logger.info("Закрытие соединений…")
    await redis_client.aclose()
    await connection.close()
    # Явно закрываем пул соединений с БД чтобы не ждать GC
    await get_db_manager().dispose()


app = FastAPI(
    title="Notification Service",
    version="1.0.0",
    description=(
        "Асинхронный сервис уведомлений с приоритетными очередями, "
        "at-least-once доставкой, идемпотентностью и механизмом повторных попыток."
    ),
    lifespan=lifespan,
)

app.include_router(notifications.router, prefix="/api/v1")
app.include_router(subscribers.router, prefix="/api/v1")


@app.get("/health", tags=["system"])
async def health() -> JSONResponse:
    return JSONResponse({"status": "ok"})


@app.get("/ready", tags=["system"], summary="Readiness probe")
async def ready(request: Request) -> JSONResponse:
    """Проверка готовности всех зависимостей: PostgreSQL, Redis, RabbitMQ.

    Возвращает 200 если все зависимости доступны, 503 если хотя бы одна недоступна.
    Каждая зависимость проверяется независимо — ответ всегда содержит поле `checks`.
    """
    checks: dict[str, str] = {}
    all_ok = True

    # ── PostgreSQL ────────────────────────────────────────────────────────────
    try:
        db = get_db_manager()
        async with db.session_maker() as session:
            await session.execute(text("SELECT 1"))
        checks["postgres"] = "ok"
    except Exception as exc:
        checks["postgres"] = f"error: {exc}"
        all_ok = False

    # ── Redis ─────────────────────────────────────────────────────────────────
    redis = getattr(request.app.state, "redis", None)
    if isinstance(redis, _RedisClient):
        try:
            await redis.ping()  # type: ignore[misc]
            checks["redis"] = "ok"
        except Exception as exc:
            checks["redis"] = f"error: {exc}"
            all_ok = False
    else:
        checks["redis"] = "error: не инициализирован"
        all_ok = False

    # ── RabbitMQ ──────────────────────────────────────────────────────────────
    connection = getattr(request.app.state, "amqp_connection", None)
    if isinstance(connection, aio_pika.abc.AbstractRobustConnection) and connection.connected:
        checks["rabbitmq"] = "ok"
    else:
        checks["rabbitmq"] = "error: нет соединения"
        all_ok = False

    return JSONResponse(
        {"status": "ok" if all_ok else "degraded", "checks": checks},
        status_code=200 if all_ok else 503,
    )
