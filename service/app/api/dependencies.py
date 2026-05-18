from collections.abc import AsyncGenerator
from typing import Annotated

import aio_pika
import redis.asyncio as aio_redis
from fastapi import Depends, HTTPException, Request, status
from sqlalchemy.ext.asyncio import AsyncSession

from app.config import get_settings
from app.db.session import get_db_manager
from app.messaging.publisher import NotificationPublisher
from app.services.idempotency_service import IdempotencyService
from app.services.notification_service import NotificationService


async def get_db() -> AsyncGenerator[AsyncSession, None]:
    async with get_db_manager().session_maker() as session:
        yield session


def get_rabbitmq_channel(request: Request) -> aio_pika.abc.AbstractChannel:
    channel = getattr(request.app.state, "amqp_channel", None)
    if channel is None:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="RabbitMQ недоступен",
        )
    return channel


def get_redis(request: Request) -> aio_redis.Redis:
    redis = getattr(request.app.state, "redis", None)
    if redis is None:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Redis недоступен",
        )
    return redis


async def get_notification_service(
    session: Annotated[AsyncSession, Depends(get_db)],
    amqp_channel: Annotated[aio_pika.abc.AbstractChannel, Depends(get_rabbitmq_channel)],
    redis_client: Annotated[aio_redis.Redis, Depends(get_redis)],
) -> NotificationService:
    return NotificationService(
        session=session,
        publisher=NotificationPublisher(amqp_channel, get_settings()),
        idempotency=IdempotencyService(redis_client, get_settings()),
    )
