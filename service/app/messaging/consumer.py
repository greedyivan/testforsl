import logging
import uuid
from typing import Any

import aio_pika
import aio_pika.abc
import redis.asyncio as aio_redis

from app.config import Settings
from app.db.session import get_db_manager
from app.messaging.schemas import QueueMessage
from app.providers.base import PermanentProviderError, TemporaryProviderError
from app.providers.factory import ProviderFactory
from app.repositories.notification_repository import NotificationRepository
from app.services.idempotency_service import IdempotencyService

logger = logging.getLogger(__name__)


class NotificationConsumer:
    """Потребитель уведомлений из RabbitMQ, управляющий конвейером доставки.

    Контракт обработки
    ──────────────────
    1. Быстрая Redis pre-check (ключ идемпотентности): пропускает очевидные
       дубли без обращения к БД.
    2. Атомарный claim в БД: UPDATE ... WHERE status IN ('queued','sent').
       Возвращает False для терминальных статусов → ACK молча.
    3. Вызов провайдера: бросает TemporaryProviderError (→ NACK/retry) или
       PermanentProviderError (→ mark dropped, ACK).
    4. ACK только после успешного вызова провайдера И обновления БД.
    """

    def __init__(
        self,
        connection: aio_pika.abc.AbstractRobustConnection,
        redis_client: aio_redis.Redis,
        settings: Settings,
    ) -> None:
        self._connection = connection
        self._redis = redis_client
        self._settings = settings
        # Factory создаётся один раз для всего lifetime consumer-а
        self._provider_factory = ProviderFactory(redis_client)

    def _get_retry_count(self, message: aio_pika.abc.AbstractIncomingMessage) -> int:
        """Извлечь счётчик повторных попыток из заголовка x-death, выставляемого RabbitMQ.

        При каждом dead-letter-е из main queue RabbitMQ увеличивает поле 'count'
        в соответствующей записи x-death. Возвращает 0 для первой доставки.
        """
        # aio_pika возвращает заголовки как union сложных типов; приводим к Any
        # чтобы не усложнять код isinstance-проверками для редко меняющейся логики.
        x_death: list[Any] = message.headers.get("x-death") or []  # type: ignore[assignment]
        for entry in x_death:
            try:
                if entry.get("queue") == self._settings.main_queue:
                    return int(entry.get("count", 0))
            except (TypeError, ValueError):
                pass
        return 0

    async def start(self) -> None:
        from app.messaging.connection import declare_topology

        channel = await self._connection.channel()
        # prefetch_count=1: обрабатываем одно сообщение за раз, чтобы
        # приоритизация соблюдалась — брокер отдаёт самое высокоприоритетное
        # из оставшихся сообщений после каждого ACK.
        await channel.set_qos(prefetch_count=1)

        main_queue = await declare_topology(channel)

        logger.info("Рабочий запущен, потребление из очереди '%s'", self._settings.main_queue)
        await main_queue.consume(self._handle_message)

    async def _handle_message(
        self, message: aio_pika.abc.AbstractIncomingMessage
    ) -> None:
        async with message.process(ignore_processed=True):
            try:
                await self._process(message)
            except Exception:
                # Необработанные исключения не должны крашить consumer loop.
                # Сообщение уже было NACK-нуто внутри _process если нужно;
                # здесь случилось что-то непредвиденное — ACK, чтобы
                # не создавать бесконечный цикл.
                logger.exception("Непредвиденная ошибка — ACK для предотвращения poison loop")
                await message.ack()

    async def _process(
        self, message: aio_pika.abc.AbstractIncomingMessage
    ) -> None:
        try:
            payload = QueueMessage.model_validate_json(message.body)
        except Exception:
            logger.exception("Не удалось распарсить тело сообщения — отбрасываем")
            await message.ack()
            return

        notification_id = payload.notification_id
        batch_id = payload.batch_id
        retry_count = self._get_retry_count(message)

        logger.info(
            "Обработка notification_id=%s batch_id=%s retry_count=%d channel=%s",
            notification_id,
            batch_id,
            retry_count,
            payload.channel,
        )

        # ── Защита от превышения max_retries ────────────────────────────────
        if retry_count >= self._settings.max_retries:
            logger.warning(
                "Превышен лимит повторных попыток (%d) для notification_id=%s batch_id=%s — отбрасываем",
                self._settings.max_retries,
                notification_id,
                batch_id,
            )
            await self._mark_dropped(
                notification_id, f"Превышен лимит повторных попыток ({self._settings.max_retries})"
            )
            await message.ack()
            return

        # ── Быстрая Redis pre-check ──────────────────────────────────────────
        idempotency = IdempotencyService(self._redis, self._settings)
        claimed = await idempotency.try_claim_worker(str(notification_id))
        if not claimed and retry_count == 0:
            # На первой доставке ключ уже существует → очевидный дубль,
            # на повторных попытках ключ уже был выставлен при первой доставке.
            logger.info(
                "Дублированная доставка обнаружена через Redis для notification_id=%s batch_id=%s",
                notification_id,
                batch_id,
            )
            await message.ack()
            return

        # ── Атомарный claim в БД ─────────────────────────────────────────────
        db = get_db_manager()
        async with db.session_maker() as session:
            repo = NotificationRepository(session)
            claimed = await repo.try_claim_for_processing(notification_id)
            await session.commit()

        if not claimed:
            # Уведомление уже в терминальном состоянии от предыдущей попытки.
            logger.info(
                "Уведомление уже в терминальном состоянии, пропускаем notification_id=%s batch_id=%s",
                notification_id,
                batch_id,
            )
            await message.ack()
            return

        # ── Вызов провайдера ─────────────────────────────────────────────────
        provider = self._provider_factory.get(payload.channel)
        try:
            await provider.send(payload)
        except TemporaryProviderError as exc:
            logger.warning(
                "Временная ошибка провайдера для notification_id=%s batch_id=%s: %s — будет повтор",
                notification_id,
                batch_id,
                exc,
            )
            async with db.session_maker() as session:
                await NotificationRepository(session).increment_retry_count(notification_id)
                await session.commit()
            # NACK без requeue → DLX → retry queue → возврат после retry_ttl_ms мс
            await message.nack(requeue=False)
            return

        except PermanentProviderError as exc:
            logger.error(
                "Постоянная ошибка провайдера для notification_id=%s batch_id=%s: %s — отбрасываем",
                notification_id,
                batch_id,
                exc,
            )
            await self._mark_dropped(notification_id, str(exc))
            await message.ack()
            return

        # ── Пометить доставленным ────────────────────────────────────────────
        async with db.session_maker() as session:
            await NotificationRepository(session).mark_delivered(notification_id)
            await session.commit()

        logger.info("Доставлено notification_id=%s batch_id=%s", notification_id, batch_id)
        await message.ack()

    async def _mark_dropped(self, notification_id: uuid.UUID, error: str) -> None:
        db = get_db_manager()
        async with db.session_maker() as session:
            await NotificationRepository(session).mark_dropped(notification_id, error)
            await session.commit()
