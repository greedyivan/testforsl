import logging
import uuid

from sqlalchemy.ext.asyncio import AsyncSession

from app.messaging.publisher import NotificationPublisher
from app.models.notification import NotificationStatus
from app.repositories.notification_repository import NotificationRepository
from app.schemas.notification import BulkSendRequest, BulkSendResponse, NotificationItem
from app.services.idempotency_service import IdempotencyService

logger = logging.getLogger(__name__)


class NotificationService:
    def __init__(
        self,
        session: AsyncSession,
        publisher: NotificationPublisher,
        idempotency: IdempotencyService,
    ) -> None:
        self._session = session
        self._publisher = publisher
        self._idempotency = idempotency
        self._repo = NotificationRepository(session)

    async def bulk_send(self, request: BulkSendRequest) -> BulkSendResponse:
        # ── Проверка идемпотентности уровня API ─────────────────────────────
        cached = await self._idempotency.get_api_response(request.idempotency_key)
        if cached is not None:
            logger.info(
                "Возвращаем кешированный ответ для idempotency_key=%s",
                request.idempotency_key,
            )
            return BulkSendResponse(**cached)

        batch_id = uuid.uuid4()

        # ── Сохранение в БД и публикация: publish after commit ───────────────
        # Все уведомления вставляются в одном flush: если любая часть упадёт
        # (например, нарушение уникального ключа), весь batch откатится до
        # того, как мы коснёмся брокера.
        async with self._session.begin():
            notifications = await self._repo.create_many(
                batch_id=batch_id,
                recipient_ids=request.recipient_ids,
                channel=request.channel,
                notification_type=request.type,
                message=request.message,
                idempotency_key=request.idempotency_key,
            )

        # Публикуем ПОСЛЕ коммита: worker никогда не увидит notification_id,
        # которого ещё нет в БД.
        for notif in notifications:
            await self._publisher.publish(
                notification_id=notif.id,
                batch_id=batch_id,
                recipient_id=notif.recipient_id,
                channel=request.channel,
                notification_type=request.type,
                message=request.message,
            )

        response = BulkSendResponse(
            batch_id=batch_id,
            accepted=len(notifications),
            notifications=[
                NotificationItem(
                    notification_id=n.id,
                    recipient_id=n.recipient_id,
                    status=NotificationStatus.QUEUED,
                )
                for n in notifications
            ],
        )

        # Кешируем ответ для обработки повторных запросов с тем же ключом
        await self._idempotency.set_api_response(
            request.idempotency_key, response.model_dump()
        )
        logger.info(
            "Bulk send принят: batch_id=%s recipients=%d channel=%s type=%s",
            batch_id,
            len(notifications),
            request.channel,
            request.type,
        )
        return response
