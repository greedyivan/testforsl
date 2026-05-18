"""Unit tests для mock-провайдеров уведомлений (реальный Redis, DB index 2)."""
import os
import uuid

import pytest
import redis.asyncio as aio_redis

from app.providers.base import PermanentProviderError, TemporaryProviderError
from app.providers.email_mock import EmailMockProvider
from app.providers.sms_mock import SmsMockProvider
from tests.factories import make_queue_message

REDIS_URL = os.environ.get("REDIS_URL", "redis://redis:6379/2")


@pytest.fixture
async def redis_client():
    client = aio_redis.from_url(REDIS_URL, decode_responses=False)
    await client.ping()
    yield client
    await client.flushdb()
    await client.aclose()


@pytest.mark.parametrize(
    "provider_class,channel,behavior_prefix,calls_key",
    [
        (SmsMockProvider, "sms", "mock:sms:behavior:", "mock:sms:calls"),
        (EmailMockProvider, "email", "mock:email:behavior:", "mock:email:calls"),
    ],
)
class TestMockProvider:
    """Параметризованный набор тестов, покрывающий оба провайдера."""

    async def test_success_no_exception(
        self, provider_class, channel, behavior_prefix, calls_key, redis_client
    ):
        provider = provider_class(redis_client)
        await provider.send(make_queue_message(channel=channel))

    async def test_records_call_in_redis(
        self, provider_class, channel, behavior_prefix, calls_key, redis_client
    ):
        provider = provider_class(redis_client)
        msg = make_queue_message(channel=channel)
        await provider.send(msg)

        calls = await redis_client.lrange(calls_key, 0, -1)
        assert str(msg.notification_id).encode() in calls

    async def test_temporary_fail_behavior(
        self, provider_class, channel, behavior_prefix, calls_key, redis_client
    ):
        provider = provider_class(redis_client)
        recipient = f"fail-{uuid.uuid4().hex[:6]}"
        await redis_client.set(f"{behavior_prefix}{recipient}", "temporary_fail")

        with pytest.raises(TemporaryProviderError):
            await provider.send(make_queue_message(channel=channel, recipient_id=recipient))

    async def test_permanent_fail_behavior(
        self, provider_class, channel, behavior_prefix, calls_key, redis_client
    ):
        provider = provider_class(redis_client)
        recipient = f"pfail-{uuid.uuid4().hex[:6]}"
        await redis_client.set(f"{behavior_prefix}{recipient}", "permanent_fail")

        with pytest.raises(PermanentProviderError):
            await provider.send(make_queue_message(channel=channel, recipient_id=recipient))


class TestSmsMockProviderExtra:
    """SMS-специфичные тесты (slow behavior, fail_count)."""

    async def test_fail_count_decrements_to_success(self, redis_client):
        provider = SmsMockProvider(redis_client)
        recipient = f"count-{uuid.uuid4().hex[:6]}"
        await redis_client.set(f"mock:sms:behavior:{recipient}", "temporary_fail")
        await redis_client.set(f"mock:sms:fail_count:{recipient}", 1)

        msg = make_queue_message("sms", recipient_id=recipient)

        # Первая попытка: сбой
        with pytest.raises(TemporaryProviderError):
            await provider.send(msg)

        # После декремента ключ удалён — вторая попытка успешна
        await provider.send(msg)


class TestEmailMockProviderExtra:
    """Email-специфичные тесты (fail_count)."""

    async def test_fail_count_decrements_to_success(self, redis_client):
        provider = EmailMockProvider(redis_client)
        recipient = f"count-{uuid.uuid4().hex[:6]}"
        await redis_client.set(f"mock:email:behavior:{recipient}", "temporary_fail")
        await redis_client.set(f"mock:email:fail_count:{recipient}", 1)

        msg = make_queue_message("email", recipient_id=recipient)

        # Первая попытка: сбой
        with pytest.raises(TemporaryProviderError):
            await provider.send(msg)

        # После декремента ключ удалён — вторая попытка успешна
        await provider.send(msg)
