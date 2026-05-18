"""Точка входа для worker-процесса.

Запуск: python -m app.worker
Docker CMD: python -m app.worker

Запускает два параллельных цикла:
- NotificationConsumer — потребление сообщений из RabbitMQ
- _republish_loop    — восстановление уведомлений, застрявших в 'queued'
"""
import asyncio
import logging
import signal
import types

import aio_pika.abc

from app.cache.client import create_redis_client
from app.config import get_settings
from app.db.session import DatabaseManager, get_db_manager
from app.logging_config import setup_logging
from app.messaging.connection import get_robust_connection
from app.messaging.consumer import NotificationConsumer
from app.services.republish_stuck import republish_stuck_notifications

setup_logging()
logger = logging.getLogger(__name__)


async def _republish_loop(
    connection: aio_pika.abc.AbstractRobustConnection,
    db: DatabaseManager,
    stop_event: asyncio.Event,
    interval_seconds: int,
) -> None:
    """Периодически публикует уведомления, застрявшие в статусе 'queued'.

    Ожидает stop_event с таймаутом вместо asyncio.sleep, чтобы немедленно
    завершиться при получении сигнала остановки.
    """
    logger.info("Republish-loop запущен (интервал %ds)", interval_seconds)
    while not stop_event.is_set():
        try:
            count = await republish_stuck_notifications(db, connection)
            if count:
                logger.info(
                    "Republish-loop: повторно опубликовано %d застрявших уведомлений",
                    count,
                )
        except Exception:
            logger.exception("Republish-loop: ошибка при сканировании застрявших уведомлений")

        # Ждём интервал или сигнала остановки — что наступит раньше
        try:
            await asyncio.wait_for(stop_event.wait(), timeout=interval_seconds)
        except asyncio.TimeoutError:
            pass

    logger.info("Republish-loop остановлен")


async def main() -> None:
    logger.info("Worker запускается…")

    connection = await get_robust_connection()
    redis_client = await create_redis_client()
    db = get_db_manager()

    settings = get_settings()

    consumer = NotificationConsumer(connection, redis_client, settings)
    await consumer.start()

    loop = asyncio.get_running_loop()
    stop_event = asyncio.Event()

    def _shutdown(sig: int, frame: types.FrameType | None) -> None:
        logger.info("Получен сигнал %s, завершение работы…", sig)
        loop.call_soon_threadsafe(stop_event.set)

    signal.signal(signal.SIGINT, _shutdown)
    signal.signal(signal.SIGTERM, _shutdown)

    # Запускаем republish-loop как фоновую задачу параллельно с consumer
    republish_task = asyncio.create_task(
        _republish_loop(connection, db, stop_event, settings.republish_interval_seconds),
        name="republish-loop",
    )

    logger.info("Worker запущен. Ctrl+C для остановки.")
    await stop_event.wait()

    logger.info("Ожидаем завершения republish-loop…")
    await republish_task

    logger.info("Закрытие соединений…")
    await redis_client.aclose()
    await connection.close()
    await db.dispose()
    logger.info("Worker остановлен.")


if __name__ == "__main__":
    asyncio.run(main())
