import uuid
from datetime import datetime
from typing import Annotated, TypeAlias

from pydantic import BaseModel, ConfigDict, Field, model_validator

from app.models.notification import (
    NotificationChannel,
    NotificationStatus,
    NotificationType,
)

# TypeAlias для обратной совместимости — внешний код импортирует Channel,
# NotificationType, NotificationStatus из этого модуля.
Channel: TypeAlias = NotificationChannel

# Валидируемые строковые типы
Message: TypeAlias = Annotated[str, Field(min_length=1, max_length=4096)]
RecipientId: TypeAlias = Annotated[str, Field(min_length=1, max_length=255)]
IdempotencyKey: TypeAlias = Annotated[str, Field(min_length=1, max_length=255)]


class BulkSendRequest(BaseModel):
    channel: NotificationChannel
    message: Message
    type: NotificationType
    recipient_ids: Annotated[list[RecipientId], Field(min_length=1, max_length=1000)]
    idempotency_key: IdempotencyKey

    @model_validator(mode="after")
    def validate_recipient_ids(self) -> "BulkSendRequest":
        if len(self.recipient_ids) != len(set(self.recipient_ids)):
            raise ValueError("recipient_ids должны быть уникальными в одном запросе")
        return self


class NotificationItem(BaseModel):
    notification_id: uuid.UUID
    recipient_id: str
    status: NotificationStatus

    model_config = ConfigDict(from_attributes=True)


class BulkSendResponse(BaseModel):
    batch_id: uuid.UUID
    accepted: int
    notifications: list[NotificationItem]


class NotificationDetail(BaseModel):
    notification_id: uuid.UUID
    batch_id: uuid.UUID
    channel: NotificationChannel
    type: NotificationType
    message: str
    status: NotificationStatus
    retry_count: int
    error_message: str | None
    created_at: datetime
    sent_at: datetime | None
    delivered_at: datetime | None

    model_config = ConfigDict(from_attributes=True)


class SubscriberNotificationsResponse(BaseModel):
    subscriber_id: str
    total: int
    notifications: list[NotificationDetail]
