import uuid
from datetime import datetime
from enum import StrEnum

from sqlalchemy import Enum as SaEnum, Index, Integer, String, Text, func
from sqlalchemy.dialects.postgresql import TIMESTAMP, UUID
from sqlalchemy.orm import DeclarativeBase, Mapped, mapped_column


class NotificationChannel(StrEnum):
    SMS = "sms"
    EMAIL = "email"


class NotificationType(StrEnum):
    TRANSACTIONAL = "transactional"
    MARKETING = "marketing"


class NotificationStatus(StrEnum):
    QUEUED = "queued"
    SENT = "sent"
    DELIVERED = "delivered"
    DROPPED = "dropped"


class Base(DeclarativeBase):
    pass


class Notification(Base):
    __tablename__ = "notifications"
    __table_args__ = (
        # Индексы — CheckConstraint-ы убраны, ORM валидирует значения через SaEnum
        Index("ix_notifications_recipient_created", "recipient_id", "created_at"),
        Index("ix_notifications_batch", "batch_id"),
        Index("ix_notifications_status", "status"),
    )

    id: Mapped[uuid.UUID] = mapped_column(
        UUID(as_uuid=True), primary_key=True, default=uuid.uuid4
    )
    batch_id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    recipient_id: Mapped[str] = mapped_column(String(255), nullable=False)
    # native_enum=False → хранение как VARCHAR, миграция не меняет тип колонки
    channel: Mapped[NotificationChannel] = mapped_column(
        SaEnum(NotificationChannel, native_enum=False), nullable=False
    )
    type: Mapped[NotificationType] = mapped_column(
        SaEnum(NotificationType, native_enum=False), nullable=False
    )
    message: Mapped[str] = mapped_column(Text, nullable=False)
    status: Mapped[NotificationStatus] = mapped_column(
        SaEnum(NotificationStatus, native_enum=False),
        nullable=False,
        default=NotificationStatus.QUEUED,
    )
    # Ключ идемпотентности от вызывающей стороны для дедупликации на уровне API.
    # Уникальность обеспечена индексом uq_notifications_idempotency_key (NULLS NOT DISTINCT)
    # из миграции — здесь unique=True намеренно отсутствует, чтобы не создавать второй индекс.
    idempotency_key: Mapped[str | None] = mapped_column(
        String(255), nullable=True
    )
    retry_count: Mapped[int] = mapped_column(Integer, nullable=False, default=0)
    error_message: Mapped[str | None] = mapped_column(Text, nullable=True)
    created_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True), server_default=func.now(), nullable=False
    )
    sent_at: Mapped[datetime | None] = mapped_column(
        TIMESTAMP(timezone=True), nullable=True
    )
    delivered_at: Mapped[datetime | None] = mapped_column(
        TIMESTAMP(timezone=True), nullable=True
    )
    updated_at: Mapped[datetime] = mapped_column(
        TIMESTAMP(timezone=True),
        server_default=func.now(),
        onupdate=func.now(),
        nullable=False,
    )
