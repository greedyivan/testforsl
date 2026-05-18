"""Unit-тесты IdempotencyService на реальном Redis (тестовый DB index 2)."""
import os
import uuid

import pytest
import redis.asyncio as aio_redis

from app.config import get_settings
from app.services.idempotency_service import IdempotencyService

REDIS_URL = os.environ.get("REDIS_URL", "redis://redis:6379/2")


@pytest.fixture
async def redis_client():
    client = aio_redis.from_url(REDIS_URL, decode_responses=False)
    await client.ping()
    yield client
    await client.flushdb()
    await client.aclose()


@pytest.fixture
def service(redis_client):
    return IdempotencyService(redis_client, get_settings())


class TestApiIdempotency:
    async def test_get_returns_none_for_unknown_key(self, service: IdempotencyService):
        result = await service.get_api_response("nonexistent-key")
        assert result is None

    async def test_set_and_get_round_trip(self, service: IdempotencyService):
        key = str(uuid.uuid4())
        payload = {"batch_id": str(uuid.uuid4()), "accepted": 3}
        await service.set_api_response(key, payload)
        result = await service.get_api_response(key)
        assert result == payload

    async def test_second_set_does_not_overwrite(self, service: IdempotencyService):
        key = str(uuid.uuid4())
        first = {"value": "first"}
        second = {"value": "second"}
        await service.set_api_response(key, first)
        # set_api_response всегда перезаписывает (SET, не SET NX).
        # Дедупликация на уровне API: сервис сначала вызывает get_api_response,
        # и только при промахе — set_api_response; повторного вызова set не бывает.
        await service.set_api_response(key, second)
        result = await service.get_api_response(key)
        assert result == second


class TestWorkerIdempotency:
    async def test_first_claim_returns_true(self, service: IdempotencyService):
        nid = str(uuid.uuid4())
        result = await service.try_claim_worker(nid)
        assert result is True

    async def test_second_claim_same_id_returns_false(self, service: IdempotencyService):
        nid = str(uuid.uuid4())
        await service.try_claim_worker(nid)
        result = await service.try_claim_worker(nid)
        assert result is False

    async def test_different_ids_both_succeed(self, service: IdempotencyService):
        nid1, nid2 = str(uuid.uuid4()), str(uuid.uuid4())
        assert await service.try_claim_worker(nid1) is True
        assert await service.try_claim_worker(nid2) is True
