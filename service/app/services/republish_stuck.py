"""Сервис восстановления уведомлений, застрявших в статусе 'queued'.

Проблема: краш между коммитом транзакции (persist) и публикацией в RabbitMQ (publish)
оставляет уведомления в статусе 'queued' навсегда — воркер никогда их не получит,
потому что сообщение в очередь не попало.

Решение: периодически сканировать записи в статусе 'queued' старше N секунд
и повторно публиковать их в RabbitMQ. Дублирование исключается двухуровневой
идемпотентностью воркера (Redis SET NX + атомарный UPDATE в БД).
"""
import logging

import aio_pika.abc

from app.config import get_settings
from app.db.session import DatabaseManager
from app.messaging.publisher import NotificationPublisher
from app.repositories.notification_repository import NotificationRepository

logger = logging.getLogger(__name__)


async def republish_stuck_notifications(
    db: DatabaseManager,
    connection: aio_pika.abc.AbstractRobustConnection,
    older_than_seconds: int = 60,
) -> int:
    """Найти застрявшие уведомления и повторно опубликовать их в RabbitMQ.

    Args:
        db: DatabaseManager для получения сессии.
        connection: RabbitMQ-соединение для публикации.
        older_than_seconds: порог «застрявшести» в секундах (по умолчанию 60).

    Returns:
        Количество повторно опубликованных уведомлений.
    """
    async with db.session_maker() as session:
        repo = NotificationRepository(session)
        stuck = await repo.find_stuck_queued(older_than_seconds)

    if not stuck:
        return 0

    # Открываем новый канал для публикации и закрываем после завершения,
    # чтобы не удерживать ресурс брокера между редкими вызовами.
    channel = await connection.channel()
    publisher = NotificationPublisher(channel, get_settings())

    count = 0
    for notification in stuck:
        try:
            await publisher.publish(
                notification_id=notification.id,
                batch_id=notification.batch_id,
                recipient_id=notification.recipient_id,
                channel=notification.channel,
                notification_type=notification.type,
                message=notification.message,
            )
            count += 1
            logger.info(
                "Повторно опубликовано застрявшее уведомление notification_id=%s",
                notification.id,
            )
        except Exception:
            logger.exception(
                "Не удалось опубликовать застрявшее уведомление notification_id=%s",
                notification.id,
            )

    await channel.close()
    return count
