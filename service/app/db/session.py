from sqlalchemy.ext.asyncio import AsyncSession, async_sessionmaker, create_async_engine

from app.config import get_settings


class DatabaseManager:
    """Управляет жизненным циклом engine и session factory.

    Используется как singleton через get_db_manager().
    Централизует пул соединений и параметры сессии в одном месте.
    """

    def __init__(self, database_url: str, debug: bool = False) -> None:
        self.engine = create_async_engine(
            database_url,
            pool_pre_ping=True,   # проверять соединение перед выдачей из пула
            pool_size=10,
            max_overflow=20,
            echo=debug,
        )
        self.session_maker = async_sessionmaker(
            bind=self.engine,
            class_=AsyncSession,
            expire_on_commit=False,
            autocommit=False,
            autoflush=False,
        )

    async def dispose(self) -> None:
        """Закрыть все соединения в пуле при завершении работы."""
        await self.engine.dispose()


_db_manager: DatabaseManager | None = None


def get_db_manager() -> DatabaseManager:
    """Вернуть singleton DatabaseManager, создав его при первом вызове."""
    global _db_manager
    if _db_manager is None:
        settings = get_settings()
        _db_manager = DatabaseManager(settings.database_url, settings.debug)
    return _db_manager
