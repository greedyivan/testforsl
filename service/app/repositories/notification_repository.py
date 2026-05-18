import uuid
from collections.abc import Sequence
from datetime import datetime, timedelta, timezone
from typing import TypeAlias

from sqlalchemy import func, select, update
from sqlalchemy.ext.asyncio import AsyncSession

from app.models.notification import (
    Notification,
    NotificationChannel,
    NotificationStatus,
    NotificationType,
)

NotificationQueryResult: TypeAlias = tuple[int, Sequence[Notification]]


class NotificationRepository:
    def __init__(self, session: AsyncSession) -> None:
        self._session = session

    async def create_many(
        self,
        batch_id: uuid.UUID,
        recipient_ids: list[str],
        channel: NotificationChannel,
        notification_type: NotificationType,
        message: str,
        idempotency_key: str,
    ) -> list[Notification]:
        notifications = [
            Notification(
                batch_id=batch_id,
                recipient_id=rid,
                channel=channel,
                type=notification_type,
                message=message,
                status=NotificationStatus.QUEUED,
                # Ключ идемпотентности per-notification: caller key + recipient
                # гарантирует уникальность внутри batch и идемпотентность
                # при повторном запросе API с тем же ключом.
                idempotency_key=f"{idempotency_key}:{rid}",
            )
            for rid in recipient_ids
        ]
        self._session.add_all(notifications)
        await self._session.flush()
        return notifications

    async def get_by_subscriber(
        self,
        subscriber_id: str,
        status: NotificationStatus | None = None,
        channel: NotificationChannel | None = None,
        limit: int = 50,
        offset: int = 0,
    ) -> NotificationQueryResult:
        base = select(Notification).where(Notification.recipient_id == subscriber_id)
        if status:
            base = base.where(Notification.status == status)
        if channel:
            base = base.where(Notification.channel == channel)

        count_result = await self._session.execute(
            select(func.count()).select_from(base.subquery())
        )
        total = count_result.scalar_one()

        rows = await self._session.execute(
            base.order_by(Notification.created_at.desc()).limit(limit).offset(offset)
        )
        return total, rows.scalars().all()

    # ── Переходы статусов (worker) ────────────────────────────────────────────

    async def try_claim_for_processing(self, notification_id: uuid.UUID) -> bool:
        """Атомарный переход статуса queued→sent.

        Гарантирует, что только один worker обработает уведомление благодаря
        атомарности UPDATE ... WHERE status IN (...).

        Returns:
            True если переход совершён (этот worker — владелец обработки).
            False если запись уже в терминальном состоянии (дублированная доставка).
        """
        stmt = (
            update(Notification)
            .where(Notification.id == notification_id)
            .where(Notification.status.in_([
                NotificationStatus.QUEUED,
                NotificationStatus.SENT,
            ]))
            .values(status=NotificationStatus.SENT, sent_at=func.now())
            .returning(Notification.id)
        )
        result = await self._session.execute(stmt)
        await self._session.flush()
        return result.scalar_one_or_none() is not None

    async def mark_delivered(self, notification_id: uuid.UUID) -> None:
        await self._session.execute(
            update(Notification)
            .where(Notification.id == notification_id)
            .values(status=NotificationStatus.DELIVERED, delivered_at=func.now())
        )
        await self._session.flush()

    async def mark_dropped(self, notification_id: uuid.UUID, error: str) -> None:
        await self._session.execute(
            update(Notification)
            .where(Notification.id == notification_id)
            .values(status=NotificationStatus.DROPPED, error_message=error)
        )
        await self._session.flush()

    async def increment_retry_count(self, notification_id: uuid.UUID) -> None:
        await self._session.execute(
            update(Notification)
            .where(Notification.id == notification_id)
            .values(retry_count=Notification.retry_count + 1)
        )
        await self._session.flush()

    async def find_stuck_queued(self, older_than_seconds: int = 60) -> Sequence[Notification]:
        """Находит уведомления, застрявшие в статусе 'queued' дольше порогового времени.

        Нормальная публикация в RabbitMQ происходит почти мгновенно после коммита.
        Если запись в 'queued' уже N секунд — признак краша между коммитом транзакции
        и публикацией (publish-after-commit gap).
        """
        cutoff = datetime.now(timezone.utc) - timedelta(seconds=older_than_seconds)
        result = await self._session.execute(
            select(Notification)
            .where(Notification.status == NotificationStatus.QUEUED)
            .where(Notification.created_at < cutoff)
        )
        return result.scalars().all()
