"""Централизованная конфигурация логирования.

Единый вызов вместо дублирования basicConfig в main.py и worker.py.
"""
import logging


def setup_logging(level: str = "INFO") -> None:
    logging.basicConfig(
        level=level,
        format="%(asctime)s %(levelname)-8s %(name)s  %(message)s",
    )
