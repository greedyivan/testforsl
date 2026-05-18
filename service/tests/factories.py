"""Фабрики тестовых данных.

Единое место для создания тестовых объектов — устраняет дублирование
вспомогательных функций `make_message()` в каждом тестовом файле.
"""
import uuid

from app.messaging.schemas import QueueMessage


def make_queue_message(
    channel: str = "sms",
    notification_type: str = "marketing",
    recipient_id: str | None = None,
    message: str = "Тестовое сообщение",
) -> QueueMessage:
    return QueueMessage(
        notification_id=uuid.uuid4(),
        batch_id=uuid.uuid4(),
        recipient_id=recipient_id or f"test-{uuid.uuid4().hex[:6]}",
        channel=channel,
        type=notification_type,
        message=message,
    )
