"""Начальная схема: таблица notifications

Revision ID: 001
Revises:
Create Date: 2026-05-17

Создаёт таблицу notifications с индексами и trigger для updated_at.
Значения channel/type/status валидируются на уровне ORM (SaEnum) —
CheckConstraint-ы не используются намеренно.
"""

from typing import Sequence, Union

import sqlalchemy as sa
from alembic import op
from sqlalchemy.dialects.postgresql import TIMESTAMP, UUID

revision: str = "001"
down_revision: Union[str, None] = None
branch_labels: Union[str, Sequence[str], None] = None
depends_on: Union[str, Sequence[str], None] = None


def upgrade() -> None:
    op.create_table(
        "notifications",
        sa.Column(
            "id",
            UUID(as_uuid=True),
            primary_key=True,
            server_default=sa.text("gen_random_uuid()"),
        ),
        sa.Column("batch_id", UUID(as_uuid=True), nullable=False),
        sa.Column("recipient_id", sa.String(255), nullable=False),
        sa.Column("channel", sa.String(10), nullable=False),
        sa.Column("type", sa.String(20), nullable=False),
        sa.Column("message", sa.Text, nullable=False),
        sa.Column("status", sa.String(20), nullable=False, server_default="queued"),
        sa.Column("idempotency_key", sa.String(255), nullable=True),
        sa.Column("retry_count", sa.Integer, nullable=False, server_default="0"),
        sa.Column("error_message", sa.Text, nullable=True),
        sa.Column(
            "created_at",
            TIMESTAMP(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
        sa.Column("sent_at", TIMESTAMP(timezone=True), nullable=True),
        sa.Column("delivered_at", TIMESTAMP(timezone=True), nullable=True),
        sa.Column(
            "updated_at",
            TIMESTAMP(timezone=True),
            server_default=sa.text("now()"),
            nullable=False,
        ),
    )

    # NULLS NOT DISTINCT: два NULL-значения считаются одинаковыми —
    # защита от случайных дублей при idempotency_key IS NULL.
    # Поддерживается в PostgreSQL 15+.
    op.execute(
        """
        CREATE UNIQUE INDEX uq_notifications_idempotency_key
        ON notifications (idempotency_key)
        NULLS NOT DISTINCT
        WHERE idempotency_key IS NOT NULL
        """
    )
    op.create_index("ix_notifications_batch", "notifications", ["batch_id"])
    op.create_index("ix_notifications_status", "notifications", ["status"])
    op.create_index(
        "ix_notifications_recipient_created",
        "notifications",
        ["recipient_id", "created_at"],
    )
    # Составные индексы для фильтрации уведомлений подписчика.
    # Позволяют PostgreSQL выполнить Index Scan вместо Filter при запросах вида:
    #   WHERE recipient_id = ? AND status = ? ORDER BY created_at DESC
    op.create_index(
        "ix_notifications_recipient_status_created",
        "notifications",
        ["recipient_id", "status", "created_at"],
    )
    op.create_index(
        "ix_notifications_recipient_channel_created",
        "notifications",
        ["recipient_id", "channel", "created_at"],
    )

    # Trigger для автоматического обновления updated_at без участия ORM
    op.execute(
        """
        CREATE OR REPLACE FUNCTION update_updated_at()
        RETURNS TRIGGER AS $$
        BEGIN
            NEW.updated_at = now();
            RETURN NEW;
        END;
        $$ language 'plpgsql';
        """
    )
    op.execute(
        """
        CREATE TRIGGER notifications_updated_at
        BEFORE UPDATE ON notifications
        FOR EACH ROW EXECUTE PROCEDURE update_updated_at();
        """
    )


def downgrade() -> None:
    op.execute("DROP TRIGGER IF EXISTS notifications_updated_at ON notifications")
    op.execute("DROP FUNCTION IF EXISTS update_updated_at")
    op.execute("DROP INDEX IF EXISTS uq_notifications_idempotency_key")
    op.drop_index("ix_notifications_recipient_channel_created", table_name="notifications")
    op.drop_index("ix_notifications_recipient_status_created", table_name="notifications")
    op.drop_index("ix_notifications_recipient_created", table_name="notifications")
    op.drop_index("ix_notifications_status", table_name="notifications")
    op.drop_index("ix_notifications_batch", table_name="notifications")
    op.drop_table("notifications")
