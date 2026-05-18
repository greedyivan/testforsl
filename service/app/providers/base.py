from abc import ABC, abstractmethod
from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from app.messaging.schemas import QueueMessage


class TemporaryProviderError(Exception):
    """Временный отказ — сообщение должно быть повторно отправлено после задержки."""


class PermanentProviderError(Exception):
    """Постоянный отказ — уведомление должно быть помечено как dropped."""


class BaseNotificationProvider(ABC):
    @abstractmethod
    async def send(self, msg: "QueueMessage") -> None:
        """Доставить уведомление.

        Raises:
            TemporaryProviderError: временный отказ, повторить позже.
            PermanentProviderError: постоянный отказ, пометить как dropped.
        """
