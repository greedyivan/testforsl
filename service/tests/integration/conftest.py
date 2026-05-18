"""Фикстуры интеграционных тестов.

Все сервисы (postgres, rabbitmq, redis, app, worker) поднимаются через
docker-compose.test.yml. Тесты обращаются к API по HTTP и напрямую работают
с Redis для управления поведением mock-провайдеров и проверки логов вызовов.
"""
import asyncio
import os
import time
import uuid
from typing import AsyncGenerator

import aio_pika
import aio_pika.abc
import httpx
import pytest
import redis.asyncio as aio_redis

API_BASE_URL = os.environ.get("API_BASE_URL", "http://localhost:8000")
REDIS_URL = os.environ.get("REDIS_URL", "redis://localhost:6379/1")
RABBITMQ_URL = os.environ.get("RABBITMQ_URL", "amqp://guest:guest@localhost:5672/")


@pytest.fixture(scope="session")
async def http_client() -> AsyncGenerator[httpx.AsyncClient, None]:
    """Session-scoped HTTP-клиент; ожидает готовности API перед выдачей."""
    async with httpx.AsyncClient(base_url=API_BASE_URL, timeout=60.0) as client:
        for attempt in range(60):
            try:
                r = await client.get("/health")
                if r.status_code == 200:
                    break
            except Exception:
                pass
            await asyncio.sleep(2)
        else:
            pytest.fail("API не стал готов в течение 120 с")
        yield client


@pytest.fixture
async def amqp_channel() -> AsyncGenerator[aio_pika.abc.AbstractChannel, None]:
    """Function-scoped RabbitMQ channel для прямой публикации сообщений в тестах."""
    connection = await aio_pika.connect_robust(RABBITMQ_URL)
    channel = await connection.channel()
    yield channel
    await channel.close()
    await connection.close()


@pytest.fixture
async def redis_client() -> AsyncGenerator[aio_redis.Redis, None]:
    """Function-scoped Redis-клиент; очищает ключи mock:* и ik:* после каждого теста."""
    client = aio_redis.from_url(REDIS_URL, decode_responses=False)
    await client.ping()
    yield client
    for pattern in ("mock:*", "ik:*"):
        keys = await client.keys(pattern)
        if keys:
            await client.delete(*keys)
    await client.aclose()


# ── Helpers ───────────────────────────────────────────────────────────────────

def unique_recipient() -> str:
    return f"test-{uuid.uuid4().hex[:10]}"


async def wait_for_status(
    client: httpx.AsyncClient,
    subscriber_id: str,
    notification_id: str,
    expected_status: str,
    timeout: float = 30.0,
) -> dict:
    """Опрашивать эндпоинт уведомлений подписчика до совпадения статуса."""
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        resp = await client.get(f"/api/v1/subscribers/{subscriber_id}/notifications")
        resp.raise_for_status()
        for n in resp.json()["notifications"]:
            if (
                n["notification_id"] == notification_id
                and n["status"] == expected_status
            ):
                return n
        await asyncio.sleep(0.5)
    raise TimeoutError(
        f"Уведомление {notification_id} не перешло в статус '{expected_status}' "
        f"за {timeout}с"
    )


async def bulk_send(
    client: httpx.AsyncClient,
    recipient_ids: list[str],
    channel: str = "sms",
    notification_type: str = "marketing",
    message: str = "Test message",
    idempotency_key: str | None = None,
) -> dict:
    payload = {
        "channel": channel,
        "message": message,
        "type": notification_type,
        "recipient_ids": recipient_ids,
        "idempotency_key": idempotency_key or str(uuid.uuid4()),
    }
    resp = await client.post("/api/v1/notifications/bulk", json=payload)
    resp.raise_for_status()
    return resp.json()
