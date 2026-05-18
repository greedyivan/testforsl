"""Базовый класс для всех mock-провайдеров уведомлений.

Поведение каждого получателя управляется через Redis:
  mock:{channel}:behavior:{recipient_id} → значение MockBehavior

Это позволяет integration-тестам контролировать провайдер из другого контейнера,
не изменяя код приложения.
"""
import logging
from enum import StrEnum

import redis.asyncio as aio_redis

from app.messaging.schemas import QueueMessage
from app.providers.base import (
    BaseNotificationProvider,
    PermanentProviderError,
    TemporaryProviderError,
)

logger = logging.getLogger(__name__)


class MockBehavior(StrEnum):
    """Режимы поведения mock-провайдеров (выставляются тестами через Redis)."""
    TEMPORARY_FAIL = "temporary_fail"
    PERMANENT_FAIL = "permanent_fail"
    # Имитирует медленный внешний API — позволяет тестировать приоритизацию
    SLOW = "slow"


class MockNotificationProvider(BaseNotificationProvider):
    """Базовый класс для SMS/Email mock-провайдеров.

    Подклассы задают Redis-ключи и при необходимости переопределяют
    _apply_slow_behavior (например, добавляют asyncio.sleep).
    """

    # Переопределяются в подклассах
    BEHAVIOR_KEY_TEMPLATE: str = ""
    FAIL_COUNT_KEY_TEMPLATE: str = ""
    CALLS_KEY: str = ""
    CALL_COUNT_KEY_TEMPLATE: str = ""
    CHANNEL_NAME: str = ""

    def __init__(self, redis_client: aio_redis.Redis) -> None:
        self._redis = redis_client

    async def send(self, msg: QueueMessage) -> None:
        behavior = await self._get_behavior(msg.recipient_id)

        if behavior == MockBehavior.TEMPORARY_FAIL:
            await self._decrement_fail_count(msg.recipient_id)
            logger.info(
                "Mock %s: временный отказ для получателя=%s",
                self.CHANNEL_NAME,
                msg.recipient_id,
            )
            raise TemporaryProviderError(
                f"Имитация временного отказа {self.CHANNEL_NAME} для {msg.recipient_id}"
            )

        if behavior == MockBehavior.PERMANENT_FAIL:
            logger.info(
                "Mock %s: постоянный отказ для получателя=%s",
                self.CHANNEL_NAME,
                msg.recipient_id,
            )
            raise PermanentProviderError(
                f"Имитация постоянного отказа {self.CHANNEL_NAME} для {msg.recipient_id}"
            )

        if behavior == MockBehavior.SLOW:
            await self._apply_slow_behavior()

        await self._record_call(msg)
        logger.info(
            "Mock %s: отправлено получателю=%s notification_id=%s",
            self.CHANNEL_NAME,
            msg.recipient_id,
            msg.notification_id,
        )

    async def _get_behavior(self, recipient_id: str) -> MockBehavior | None:
        """Получить поведение для этого получателя из Redis."""
        value = await self._redis.get(
            self.BEHAVIOR_KEY_TEMPLATE.format(recipient_id=recipient_id)
        )
        if value is None:
            return None
        try:
            return MockBehavior(value.decode())
        except ValueError:
            return None

    async def _decrement_fail_count(self, recipient_id: str) -> None:
        """Уменьшить счётчик оставшихся сбоев; удалить ключи при достижении 0."""
        fail_count_key = self.FAIL_COUNT_KEY_TEMPLATE.format(recipient_id=recipient_id)
        remaining = await self._redis.get(fail_count_key)
        if remaining is not None:
            new_remaining = int(remaining) - 1
            behavior_key = self.BEHAVIOR_KEY_TEMPLATE.format(recipient_id=recipient_id)
            if new_remaining <= 0:
                await self._redis.delete(behavior_key, fail_count_key)
            else:
                await self._redis.set(fail_count_key, new_remaining)

    async def _apply_slow_behavior(self) -> None:
        """Хук для имитации медленного провайдера. Переопределяется в подклассах."""

    async def _record_call(self, msg: QueueMessage) -> None:
        """Записать вызов в Redis для отслеживания тестами."""
        # transaction=False — атомарность не нужна, цель — батч-отправка двух команд
        async with self._redis.pipeline(transaction=False) as pipe:
            pipe.rpush(self.CALLS_KEY, str(msg.notification_id))
            pipe.incr(self.CALL_COUNT_KEY_TEMPLATE.format(notification_id=msg.notification_id))
            await pipe.execute()
