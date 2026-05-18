import uuid

from pydantic import BaseModel

from app.models.notification import NotificationChannel, NotificationType


class QueueMessage(BaseModel):
    """Payload, публикуемый в RabbitMQ для каждого отдельного уведомления."""

    notification_id: uuid.UUID
    batch_id: uuid.UUID
    recipient_id: str
    channel: NotificationChannel
    type: NotificationType
    message: str
