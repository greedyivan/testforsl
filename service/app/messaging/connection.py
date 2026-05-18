import logging

import aio_pika
import aio_pika.abc

from app.config import get_settings

logger = logging.getLogger(__name__)


async def get_robust_connection() -> aio_pika.abc.AbstractRobustConnection:
    """Создать RobustConnection с автоматическим переподключением при сбоях."""
    settings = get_settings()
    return await aio_pika.connect_robust(settings.rabbitmq_url)


async def declare_topology(
    channel: aio_pika.abc.AbstractChannel,
) -> aio_pika.abc.AbstractQueue:
    """Объявить все exchange-ы, очереди и binding-и.

    Идемпотентна — безопасно вызывать при каждом старте.
    Возвращает объект основной очереди, готовый к потреблению и инспекции.
    """
    settings = get_settings()

    # ── Dead-letter exchange (маршрутизирует NACK-нутые сообщения в retry queue)
    dlx = await channel.declare_exchange(
        settings.dlx_exchange,
        aio_pika.ExchangeType.DIRECT,
        durable=True,
    )

    # ── Dead / poison-message exchange (конечная точка для неисправимых сообщений)
    dead_exchange = await channel.declare_exchange(
        settings.dead_exchange,
        aio_pika.ExchangeType.FANOUT,
        durable=True,
    )

    # ── Dead queue ────────────────────────────────────────────────────────────
    dead_queue = await channel.declare_queue(
        settings.dead_queue,
        durable=True,
    )
    await dead_queue.bind(dead_exchange)

    # ── Retry queue ───────────────────────────────────────────────────────────
    # После истечения TTL сообщения возвращаются в main queue через default
    # exchange, используя имя очереди в качестве routing key.
    retry_queue = await channel.declare_queue(
        settings.retry_queue,
        durable=True,
        arguments={
            "x-message-ttl": settings.retry_ttl_ms,
            "x-dead-letter-exchange": "",              # default exchange
            "x-dead-letter-routing-key": settings.main_queue,
        },
    )
    await retry_queue.bind(dlx, routing_key="retry")

    # ── Main queue ────────────────────────────────────────────────────────────
    # x-max-priority: 10 включает приоритизацию (шкала 0–10).
    # transactional публикуется с priority=10, marketing — с priority=1.
    main_queue = await channel.declare_queue(
        settings.main_queue,
        durable=True,
        arguments={
            "x-max-priority": 10,
            "x-dead-letter-exchange": settings.dlx_exchange,
            "x-dead-letter-routing-key": "retry",
        },
    )

    logger.info("Топология RabbitMQ успешно объявлена")
    return main_queue
