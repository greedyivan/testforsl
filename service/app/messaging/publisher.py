import logging
import uuid
from enum import IntEnum

import aio_pika

from app.config import Settings
from app.messaging.schemas import QueueMessage
from app.models.notification import NotificationChannel, NotificationType

logger = logging.getLogger(__name__)


class MessagePriority(IntEnum):
    """Приоритеты сообщений RabbitMQ (шкала 0–10, задана через x-max-priority=10).

    Transactional всегда опережает marketing в очереди с prefetch_count=1.
    """
    MARKETING = 1
    TRANSACTIONAL = 10


_PRIORITY_MAP: dict[str, MessagePriority] = {
    NotificationType.MARKETING: MessagePriority.MARKETING,
    NotificationType.TRANSACTIONAL: MessagePriority.TRANSACTIONAL,
}


class NotificationPublisher:
    """Публикует отдельные уведомления в приоритетную очередь.

    Использует default (безымянный) exchange: routing_key == имя очереди —
    простейший и наиболее надёжный паттерн маршрутизации для одной очереди.
    """

    def __init__(self, channel: aio_pika.abc.AbstractChannel, settings: Settings) -> None:
        self._channel = channel
        self._settings = settings

    async def publish(
        self,
        notification_id: uuid.UUID,
        batch_id: uuid.UUID,
        recipient_id: str,
        channel: NotificationChannel,
        notification_type: NotificationType,
        message: str,
    ) -> None:
        payload = QueueMessage(
            notification_id=notification_id,
            batch_id=batch_id,
            recipient_id=recipient_id,
            channel=channel,
            type=notification_type,
            message=message,
        )

        priority = _PRIORITY_MAP.get(notification_type, MessagePriority.MARKETING)
        amqp_message = aio_pika.Message(
            body=payload.model_dump_json().encode(),
            delivery_mode=aio_pika.DeliveryMode.PERSISTENT,  # переживает перезапуск брокера
            priority=int(priority),
            content_type="application/json",
        )

        # Публикация в default exchange — routing_key == имя очереди
        await self._channel.default_exchange.publish(
            amqp_message,
            routing_key=self._settings.main_queue,
        )
        logger.debug(
            "Опубликовано notification_id=%s priority=%d",
            notification_id,
            priority,
        )
