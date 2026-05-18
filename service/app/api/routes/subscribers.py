import logging
from typing import Annotated

from fastapi import APIRouter, Depends, Query
from sqlalchemy.ext.asyncio import AsyncSession

from app.api.dependencies import get_db
from app.repositories.notification_repository import NotificationRepository
from app.schemas.notification import (
    Channel,
    NotificationDetail,
    NotificationStatus,
    SubscriberNotificationsResponse,
)

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/subscribers", tags=["subscribers"])


@router.get(
    "/{subscriber_id}/notifications",
    response_model=SubscriberNotificationsResponse,
    summary="История уведомлений подписчика",
)
async def get_subscriber_notifications(
    subscriber_id: str,
    session: Annotated[AsyncSession, Depends(get_db)],
    status: Annotated[NotificationStatus | None, Query()] = None,
    channel: Annotated[Channel | None, Query()] = None,
    limit: Annotated[int, Query(ge=1, le=200)] = 50,
    offset: Annotated[int, Query(ge=0)] = 0,
) -> SubscriberNotificationsResponse:
    repo = NotificationRepository(session)
    total, notifications = await repo.get_by_subscriber(
        subscriber_id=subscriber_id,
        status=status,
        channel=channel,
        limit=limit,
        offset=offset,
    )
    return SubscriberNotificationsResponse(
        subscriber_id=subscriber_id,
        total=total,
        notifications=[
            NotificationDetail(
                notification_id=n.id,
                batch_id=n.batch_id,
                channel=n.channel,
                type=n.type,
                message=n.message,
                status=n.status,
                retry_count=n.retry_count,
                error_message=n.error_message,
                created_at=n.created_at,
                sent_at=n.sent_at,
                delivered_at=n.delivered_at,
            )
            for n in notifications
        ],
    )
