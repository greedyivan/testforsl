"""Фабрика для Redis-клиента.

Единственное место, где задаются параметры подключения — encoding, decode_responses.
Используется в main.py (lifespan) и worker.py, чтобы не дублировать один и тот же блок.
"""
import redis.asyncio as aio_redis

from app.config import get_settings


async def create_redis_client() -> aio_redis.Redis:
    """Создать Redis-клиент и проверить соединение через ping."""
    settings = get_settings()
    client: aio_redis.Redis = aio_redis.from_url(
        settings.redis_url,
        encoding="utf-8",
        # decode_responses=False — bytes нужны для универсального чтения ключей
        decode_responses=False,
    )
    await client.ping()  # type: ignore[misc]
    return client
