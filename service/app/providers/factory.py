import redis.asyncio as aio_redis

from app.providers.base import BaseNotificationProvider
from app.providers.email_mock import EmailMockProvider
from app.providers.sms_mock import SmsMockProvider


class ProviderFactory:
    """Фабрика провайдеров уведомлений.

    Заменяет условный оператор if/elif в consumer-е на lookup-таблицу.
    Добавление нового канала требует только регистрации нового провайдера здесь,
    без изменения логики consumer (Open/Closed Principle).
    """

    def __init__(self, redis_client: aio_redis.Redis) -> None:
        # Провайдеры создаются один раз, а не при каждом сообщении
        self._providers: dict[str, BaseNotificationProvider] = {
            "sms": SmsMockProvider(redis_client),
            "email": EmailMockProvider(redis_client),
        }

    def get(self, channel: str) -> BaseNotificationProvider:
        """Вернуть провайдер для указанного канала доставки.

        Raises:
            ValueError: если канал не зарегистрирован в фабрике.
        """
        try:
            return self._providers[channel]
        except KeyError as exc:
            raise ValueError(f"Неизвестный канал доставки: {channel!r}") from exc
