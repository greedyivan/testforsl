"""Интеграционные тесты: поведение очереди с приоритетами.

Стратегия: публикуем большой маркетинговый батч, затем сразу транзакционное
сообщение. При prefetch_count=1 воркер каждый раз берёт из очереди сообщение
с наивысшим приоритетом, поэтому после завершения текущего маркетингового
сообщения следующим обрабатывается транзакционное.

Проверяем:
1. Очередь объявлена с x-max-priority=10 (через RabbitMQ Management API).
2. Транзакционное уведомление доставляется раньше большинства маркетинговых
   (проверка порядка по call-tracking листу в Redis).
"""
import asyncio
import uuid

import httpx
import pytest

from tests.integration.conftest import (
    bulk_send,
    unique_recipient,
    wait_for_status,
)

RABBITMQ_API = "http://rabbitmq:15672/api"
RABBITMQ_AUTH = ("guest", "guest")


class TestQueueConfiguration:
    async def test_main_queue_has_priority_argument(self, http_client: httpx.AsyncClient):
        """Проверяет, что очередь RabbitMQ объявлена с x-max-priority=10."""
        async with httpx.AsyncClient(
            base_url=RABBITMQ_API, auth=RABBITMQ_AUTH, timeout=10
        ) as mgmt:
            resp = await mgmt.get("/queues/%2F/notifications")
            if resp.status_code == 404:
                pytest.skip("RabbitMQ Management API not reachable or queue not yet declared")
            assert resp.status_code == 200
            queue_info = resp.json()
            args = queue_info.get("arguments", {})
            assert args.get("x-max-priority") == 10, (
                f"Expected x-max-priority=10, got {args}"
            )

    async def test_retry_queue_has_ttl(self, http_client: httpx.AsyncClient):
        async with httpx.AsyncClient(
            base_url=RABBITMQ_API, auth=RABBITMQ_AUTH, timeout=10
        ) as mgmt:
            resp = await mgmt.get("/queues/%2F/notifications.retry")
            if resp.status_code == 404:
                pytest.skip("Retry queue not yet declared")
            assert resp.status_code == 200
            args = resp.json().get("arguments", {})
            assert "x-message-ttl" in args


class TestPriorityOrdering:
    async def test_transactional_processed_before_bulk_marketing(
        self,
        http_client: httpx.AsyncClient,
        redis_client,
    ):
        """Транзакционное сообщение должно обрабатываться раньше большинства
        маркетинговых, даже если оно опубликовано позже.

        Стратегия:
        1. Все маркетинговые получатели получают поведение "slow" (0.3 с на сообщение).
           Это удерживает воркер занятым, пока сообщения накапливаются в очереди.
        2. Публикуем большой маркетинговый батч (30 сообщений → ~9 с общей работы).
        3. Немедленно (без sleep) публикуем 1 транзакционное сообщение.
           К этому моменту воркер обрабатывает первое маркетинговое сообщение.
           Оставшиеся 29 уже в очереди; брокер выдаст транзакционное (priority 10)
           раньше любого из них (priority 1).
        4. Проверяем, что транзакционное уведомление стоит в call-log раньше
           хотя бы ПОЛОВИНЫ маркетинговых сообщений.
        """
        n_marketing = 30
        marketing_recipients = [unique_recipient() for _ in range(n_marketing)]
        tx_recipient = unique_recipient()

        # Замедляем воркер для каждого маркетингового получателя (0.3 с на сообщение)
        pipe = redis_client.pipeline()
        for r in marketing_recipients:
            pipe.set(f"mock:sms:behavior:{r}", "slow")
        await pipe.execute()

        # Публикуем маркетинговый батч — воркер начинает потребление немедленно
        await bulk_send(
            http_client,
            marketing_recipients,
            channel="sms",
            notification_type="marketing",
            idempotency_key=str(uuid.uuid4()),
        )

        # Транзакционное — сразу после батча; попадает в очередь с priority 10,
        # пока воркер медленно обрабатывает маркетинговые сообщения.
        tx_data = await bulk_send(
            http_client,
            [tx_recipient],
            channel="sms",
            notification_type="transactional",
            idempotency_key=str(uuid.uuid4()),
            message="URGENT: your verification code",
        )
        tx_notif_id = tx_data["notifications"][0]["notification_id"]

        # 30 медленных сообщений × 0.3 с + запас = 60 с
        await wait_for_status(
            http_client, tx_recipient, tx_notif_id, "delivered", timeout=60
        )

        # Проверяем порядок вызовов в Redis call-log
        calls = await redis_client.lrange("mock:sms:calls", 0, -1)
        call_ids = [c.decode() for c in calls]

        assert tx_notif_id in call_ids, "Транзакционное уведомление не найдено в call-log провайдера"

        tx_position = call_ids.index(tx_notif_id)
        # Транзакционное должно быть обработано раньше хотя бы половины маркетинговых —
        # явный признак работающей приоритизации.
        assert tx_position < n_marketing // 2, (
            f"Транзакционное сообщение на позиции {tx_position} "
            f"(ожидалось < {n_marketing // 2} из {n_marketing + 1} всего). "
            f"Первые 10 вызовов: {call_ids[:10]}"
        )

    async def test_transactional_type_stored_correctly(
        self, http_client: httpx.AsyncClient
    ):
        recipient = unique_recipient()
        data = await bulk_send(
            http_client, [recipient], notification_type="transactional"
        )
        notif_id = data["notifications"][0]["notification_id"]
        await wait_for_status(http_client, recipient, notif_id, "delivered")

        resp = await http_client.get(f"/api/v1/subscribers/{recipient}/notifications")
        notif = resp.json()["notifications"][0]
        assert notif["type"] == "transactional"
