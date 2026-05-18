import logging
from typing import Annotated

from fastapi import APIRouter, Depends, status

from app.api.dependencies import get_notification_service
from app.schemas.notification import BulkSendRequest, BulkSendResponse
from app.services.notification_service import NotificationService

logger = logging.getLogger(__name__)
router = APIRouter(prefix="/notifications", tags=["notifications"])


@router.post(
    "/bulk",
    response_model=BulkSendResponse,
    status_code=status.HTTP_202_ACCEPTED,
    summary="Массовая отправка уведомлений",
    description=(
        "Принимает до 1 000 получателей за один вызов. "
        "Запрос принимается немедленно (202); доставка выполняется асинхронно. "
        "Передайте **idempotency_key** для безопасного повтора запроса — повторный "
        "запрос с тем же ключом вернёт оригинальный ответ без создания дубликатов."
    ),
)
async def bulk_send(
    request: BulkSendRequest,
    service: Annotated[NotificationService, Depends(get_notification_service)],
) -> BulkSendResponse:
    return await service.bulk_send(request)
