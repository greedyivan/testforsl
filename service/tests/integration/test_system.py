"""Тесты системных эндпоинтов и фоновых процессов.

- /health — liveness probe
- /ready  — readiness probe (PostgreSQL + Redis + RabbitMQ)
- Republish stuck — воркер повторно публикует застрявшие уведомления
"""
import os
import uuid
from datetime import datetime, timezone

import asyncpg
import httpx
import pytest

from tests.integration.conftest import unique_recipient, wait_for_status

_DB_URL = os.environ.get(
    "DATABASE_URL",
    "postgresql+asyncpg://postgres:postgres@postgres:5432/notifications_test",
).replace("postgresql+asyncpg://", "postgresql://")


@pytest.fixture
async def db_conn():
    """Прямое asyncpg-соединение для вставки тестовых данных в БД."""
    conn = await asyncpg.connect(_DB_URL)
    yield conn
    await conn.close()


class TestHealth:
    async def test_liveness_always_returns_200(self, http_client: httpx.AsyncClient):
        resp = await http_client.get("/health")
        assert resp.status_code == 200
        assert resp.json()["status"] == "ok"

    async def test_readiness_returns_200_when_all_deps_healthy(
        self, http_client: httpx.AsyncClient
    ):
        resp = await http_client.get("/ready")
        assert resp.status_code == 200
        data = resp.json()
        assert data["status"] == "ok"

        # Каждая зависимость должна присутствовать и быть 'ok'
        for dep in ("postgres", "redis", "rabbitmq"):
            assert dep in data["checks"], f"Поле '{dep}' отсутствует в checks"
            assert data["checks"][dep] == "ok", (
                f"Зависимость '{dep}' не готова: {data['checks'][dep]}"
            )


class TestRepublishStuck:
    async def test_stuck_notification_gets_republished_and_delivered(
        self,
        http_client: httpx.AsyncClient,
        db_conn: asyncpg.Connection,
    ):
        """Воркер обнаруживает уведомление, застрявшее в 'queued', и доставляет его.

        Сценарий: имитируем publish-after-commit gap — вставляем запись напрямую
        в БД с заведомо старым created_at, минуя RabbitMQ. Через REPUBLISH_INTERVAL_SECONDS
        (10 с в тестах) воркер публикует её в очередь, и она доставляется.
        """
        recipient = unique_recipient()
        notification_id = uuid.uuid4()
        batch_id = uuid.uuid4()

        # Вставляем «застрявшее» уведомление со старым timestamp (2 мин назад)
        # чтобы гарантированно попасть под порог older_than=60s.
        #
        # ВАЖНО: SaEnum(native_enum=False) хранит имена членов enum (SMS, MARKETING, QUEUED),
        # а не значения (sms, marketing, queued). При raw INSERT нужно использовать имена.
        old_time = datetime(2000, 1, 1, tzinfo=timezone.utc)
        await db_conn.execute(
            """
            INSERT INTO notifications (
                id, batch_id, recipient_id, channel, type, message, status,
                idempotency_key, retry_count, created_at, updated_at
            ) VALUES ($1, $2, $3, 'SMS', 'MARKETING', 'Stuck republish test', 'QUEUED',
                      $4, 0, $5, $5)
            """,
            str(notification_id),
            str(batch_id),
            recipient,
            f"stuck-ik:{recipient}",
            old_time,
        )

        # Ждём, пока republish-loop (интервал 10 с) поднимет уведомление и
        # воркер доставит его. Даём 60 с с запасом.
        result = await wait_for_status(
            http_client, recipient, str(notification_id), "delivered", timeout=60
        )
        assert result["status"] == "delivered"
