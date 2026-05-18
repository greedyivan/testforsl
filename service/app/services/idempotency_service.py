import json
import logging
from typing import Any

import redis.asyncio as aio_redis

from app.cache.constants import RedisKeys
from app.config import Settings

logger = logging.getLogger(__name__)


class IdempotencyService:
    """Двухуровневая дедупликация на базе Redis.

    Уровень 1 (API): предотвращает повторные bulk-send запросы от клиента.
    Уровень 2 (Worker): предотвращает повторную доставку одного уведомления
    при переотправке сообщения из RabbitMQ (at-least-once семантика).
    """

    def __init__(self, redis_client: aio_redis.Redis, settings: Settings) -> None:
        self._redis = redis_client
        self._ttl = settings.idempotency_ttl

    # ── Уровень API ──────────────────────────────────────────────────────────

    async def get_api_response(self, key: str) -> Any | None:
        raw = await self._redis.get(f"{RedisKeys.IDEMPOTENCY_API}{key}")
        if raw is None:
            return None
        logger.debug("Идемпотентность: cache hit для key=%s", key)
        return json.loads(raw)

    async def set_api_response(self, key: str, response: Any) -> None:
        await self._redis.set(
            f"{RedisKeys.IDEMPOTENCY_API}{key}",
            json.dumps(response, default=str),
            ex=self._ttl,
        )

    # ── Уровень Worker ───────────────────────────────────────────────────────

    async def try_claim_worker(self, notification_id: str) -> bool:
        """Атомарно застолбить уведомление для обработки worker-ом.

        Returns:
            True  — этот worker первым заявил на него права.
            False — другой экземпляр уже обработал (дублированная доставка).

        Вторичная защита: основная — атомарный UPDATE статуса в БД
        (NotificationRepository.try_claim_for_processing). Redis — быстрый
        pre-check, позволяющий не трогать БД при очевидных дублях.
        """
        result = await self._redis.set(
            f"{RedisKeys.IDEMPOTENCY_WORKER}{notification_id}",
            "1",
            nx=True,
            ex=self._ttl,
        )
        return result is not None
