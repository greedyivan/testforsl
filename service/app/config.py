from typing import Annotated

from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        case_sensitive=False,
    )

    # База данных
    database_url: str = "postgresql+asyncpg://postgres:postgres@localhost:5432/notifications"

    # RabbitMQ
    rabbitmq_url: str = "amqp://guest:guest@localhost:5672/"

    # Redis
    redis_url: str = "redis://localhost:6379/0"

    # Поведение приложения
    debug: bool = False
    max_retries: Annotated[int, Field(ge=1, le=10)] = 3
    # Задержка между повторными попытками доставки (мс)
    retry_ttl_ms: Annotated[int, Field(ge=1000, le=300_000)] = 30_000
    # TTL для Redis ключей идемпотентности (сек)
    idempotency_ttl: Annotated[int, Field(ge=60, le=2_592_000)] = 86_400

    # Интервал между проверками застрявших уведомлений (сек)
    republish_interval_seconds: Annotated[int, Field(ge=5, le=3600)] = 60

    # Топология RabbitMQ
    main_queue: str = Field(
        default="notifications",
        description="Имя основной очереди RabbitMQ для уведомлений",
    )
    retry_queue: str = Field(
        default="notifications.retry",
        description="Очередь повторных попыток (TTL → возврат в main_queue)",
    )
    dead_queue: str = Field(
        default="notifications.dead",
        description="Очередь для неисправимых (poison) сообщений",
    )
    dlx_exchange: str = Field(
        default="notifications.dlx",
        description="Dead-letter exchange для NACK-нутых сообщений",
    )
    dead_exchange: str = Field(
        default="notifications.dead.exchange",
        description="Fanout exchange → dead_queue для poison-сообщений",
    )


def get_settings() -> Settings:
    # lru_cache намеренно убран — Settings() легковесен, кеш скрывал бы
    # пересоздание настроек в тестах при смене переменных окружения.
    return Settings()
