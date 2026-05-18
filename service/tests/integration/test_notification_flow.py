"""Интеграционные тесты: полный pipeline доставки.

Каждый тест изолирован уникальными recipient_id — тесты не конфликтуют
при параллельном запуске.
"""
import asyncio
import json
import uuid

import aio_pika
import httpx
import pytest

from tests.integration.conftest import (
    bulk_send,
    unique_recipient,
    wait_for_status,
)


class TestBulkSend:
    async def test_returns_202_with_notification_list(self, http_client: httpx.AsyncClient):
        recipients = [unique_recipient() for _ in range(3)]
        data = await bulk_send(http_client, recipients)

        assert data["accepted"] == 3
        assert len(data["notifications"]) == 3
        statuses = {n["status"] for n in data["notifications"]}
        assert statuses == {"queued"}

    async def test_each_recipient_gets_own_notification(self, http_client: httpx.AsyncClient):
        r1, r2 = unique_recipient(), unique_recipient()
        data = await bulk_send(http_client, [r1, r2])

        recipient_ids = {n["recipient_id"] for n in data["notifications"]}
        assert recipient_ids == {r1, r2}

    async def test_batch_id_is_consistent(self, http_client: httpx.AsyncClient):
        data = await bulk_send(http_client, [unique_recipient(), unique_recipient()])
        batch_id = data["batch_id"]
        assert batch_id  # non-empty UUID string

        r = await http_client.get(
            f"/api/v1/subscribers/{data['notifications'][0]['recipient_id']}/notifications"
        )
        assert r.json()["notifications"][0]["batch_id"] == batch_id


class TestDeliveryFlow:
    async def test_sms_notification_reaches_delivered(self, http_client: httpx.AsyncClient):
        recipient = unique_recipient()
        data = await bulk_send(http_client, [recipient], channel="sms")
        notif_id = data["notifications"][0]["notification_id"]

        result = await wait_for_status(http_client, recipient, notif_id, "delivered")
        assert result["channel"] == "sms"
        assert result["delivered_at"] is not None
        assert result["sent_at"] is not None

    async def test_email_notification_reaches_delivered(self, http_client: httpx.AsyncClient):
        recipient = unique_recipient()
        data = await bulk_send(http_client, [recipient], channel="email")
        notif_id = data["notifications"][0]["notification_id"]

        result = await wait_for_status(http_client, recipient, notif_id, "delivered")
        assert result["channel"] == "email"

    async def test_transactional_notification_delivered(self, http_client: httpx.AsyncClient):
        recipient = unique_recipient()
        data = await bulk_send(
            http_client, [recipient], channel="sms", notification_type="transactional"
        )
        notif_id = data["notifications"][0]["notification_id"]
        result = await wait_for_status(http_client, recipient, notif_id, "delivered")
        assert result["type"] == "transactional"


class TestValidation:
    async def test_invalid_channel_returns_422(self, http_client: httpx.AsyncClient):
        resp = await http_client.post(
            "/api/v1/notifications/bulk",
            json={
                "channel": "telegram",
                "type": "marketing",
                "recipient_ids": ["r1"],
                "message": "Hi",
                "idempotency_key": "val-k1",
            },
        )
        assert resp.status_code == 422

    async def test_missing_recipient_ids_returns_422(self, http_client: httpx.AsyncClient):
        resp = await http_client.post(
            "/api/v1/notifications/bulk",
            json={
                "channel": "sms",
                "type": "marketing",
                "message": "Hi",
                "idempotency_key": "val-k2",
            },
        )
        assert resp.status_code == 422

    async def test_empty_recipient_ids_returns_422(self, http_client: httpx.AsyncClient):
        resp = await http_client.post(
            "/api/v1/notifications/bulk",
            json={
                "channel": "sms",
                "type": "marketing",
                "recipient_ids": [],
                "message": "Hi",
                "idempotency_key": "val-k3",
            },
        )
        assert resp.status_code == 422


class TestStatusHistory:
    async def test_get_subscriber_notifications_returns_all(
        self, http_client: httpx.AsyncClient
    ):
        recipient = unique_recipient()
        key = str(uuid.uuid4())
        sms_data = await bulk_send(http_client, [recipient], channel="sms", idempotency_key=key)
        email_data = await bulk_send(
            http_client,
            [recipient],
            channel="email",
            idempotency_key=key + "-2",
        )
        await wait_for_status(
            http_client, recipient, sms_data["notifications"][0]["notification_id"], "delivered"
        )
        await wait_for_status(
            http_client, recipient, email_data["notifications"][0]["notification_id"], "delivered"
        )

        resp = await http_client.get(f"/api/v1/subscribers/{recipient}/notifications")
        data = resp.json()
        assert data["subscriber_id"] == recipient
        assert data["total"] >= 2

    async def test_status_filter_works(self, http_client: httpx.AsyncClient):
        recipient = unique_recipient()
        data = await bulk_send(http_client, [recipient])
        notif_id = data["notifications"][0]["notification_id"]
        await wait_for_status(http_client, recipient, notif_id, "delivered")

        resp = await http_client.get(
            f"/api/v1/subscribers/{recipient}/notifications",
            params={"status": "delivered"},
        )
        notifications = resp.json()["notifications"]
        assert all(n["status"] == "delivered" for n in notifications)

    async def test_channel_filter_works(self, http_client: httpx.AsyncClient):
        recipient = unique_recipient()
        key = str(uuid.uuid4())
        sms_data = await bulk_send(http_client, [recipient], channel="sms", idempotency_key=key)
        email_data = await bulk_send(
            http_client,
            [recipient],
            channel="email",
            idempotency_key=key + "-email",
        )
        await wait_for_status(
            http_client, recipient, sms_data["notifications"][0]["notification_id"], "delivered"
        )
        await wait_for_status(
            http_client, recipient, email_data["notifications"][0]["notification_id"], "delivered"
        )

        resp = await http_client.get(
            f"/api/v1/subscribers/{recipient}/notifications",
            params={"channel": "sms"},
        )
        notifications = resp.json()["notifications"]
        assert all(n["channel"] == "sms" for n in notifications)

    async def test_total_count_reflects_actual_rows(self, http_client: httpx.AsyncClient):
        recipient = unique_recipient()
        data = await bulk_send(http_client, [recipient])
        notif_id = data["notifications"][0]["notification_id"]
        await wait_for_status(http_client, recipient, notif_id, "delivered")

        resp = await http_client.get(f"/api/v1/subscribers/{recipient}/notifications")
        data = resp.json()
        # При отсутствии пагинации total совпадает с числом элементов в ответе
        assert data["total"] == len(data["notifications"])

    async def test_pagination_with_limit_and_offset(self, http_client: httpx.AsyncClient):
        recipient = unique_recipient()
        base_key = str(uuid.uuid4())
        notif_ids = []
        for i in range(3):  # создаём 3 уведомления через разные idempotency_key
            d = await bulk_send(http_client, [recipient], idempotency_key=f"{base_key}-{i}")
            notif_ids.append(d["notifications"][0]["notification_id"])
        for notif_id in notif_ids:
            await wait_for_status(http_client, recipient, notif_id, "delivered")

        # limit=2 → возвращает 2 записи, но total=3
        resp = await http_client.get(
            f"/api/v1/subscribers/{recipient}/notifications",
            params={"limit": 2, "offset": 0},
        )
        data = resp.json()
        assert data["total"] == 3
        assert len(data["notifications"]) == 2

        # offset=2 → остаток: 1 запись, total по-прежнему 3
        resp = await http_client.get(
            f"/api/v1/subscribers/{recipient}/notifications",
            params={"limit": 2, "offset": 2},
        )
        data = resp.json()
        assert data["total"] == 3
        assert len(data["notifications"]) == 1


class TestIdempotency:
    async def test_duplicate_api_request_returns_same_response(
        self, http_client: httpx.AsyncClient
    ):
        recipients = [unique_recipient(), unique_recipient()]
        key = str(uuid.uuid4())
        payload = {
            "channel": "sms",
            "message": "Idempotency test",
            "type": "marketing",
            "recipient_ids": recipients,
            "idempotency_key": key,
        }
        r1 = await http_client.post("/api/v1/notifications/bulk", json=payload)
        r2 = await http_client.post("/api/v1/notifications/bulk", json=payload)

        assert r1.status_code == 202
        assert r2.status_code == 202
        assert r1.json()["batch_id"] == r2.json()["batch_id"]
        assert r1.json()["accepted"] == r2.json()["accepted"]

    async def test_duplicate_api_request_creates_no_extra_notifications(
        self, http_client: httpx.AsyncClient
    ):
        recipient = unique_recipient()
        key = str(uuid.uuid4())
        payload = {
            "channel": "sms",
            "message": "Idempotency dedup test",
            "type": "marketing",
            "recipient_ids": [recipient],
            "idempotency_key": key,
        }
        r1 = await http_client.post("/api/v1/notifications/bulk", json=payload)
        await http_client.post("/api/v1/notifications/bulk", json=payload)
        notif_id = r1.json()["notifications"][0]["notification_id"]
        await wait_for_status(http_client, recipient, notif_id, "delivered")

        resp = await http_client.get(f"/api/v1/subscribers/{recipient}/notifications")
        assert resp.json()["total"] == 1  # ровно одна запись, не две


class TestWorkerDeduplication:
    async def test_worker_deduplication_on_redelivery(
        self,
        http_client: httpx.AsyncClient,
        redis_client,
        amqp_channel,
    ):
        """При повторной доставке того же notification_id воркер вызывает провайдера ровно один раз.

        Воспроизводит at-least-once сценарий: RabbitMQ доставляет одно и то же
        сообщение дважды (например, после краша воркера до ACK). Ожидаемое поведение:
        второй вызов отклоняется Redis SET NX, провайдер остаётся на счётчике 1.
        """
        recipient = unique_recipient()

        # Отправляем один батч и ждём успешной доставки
        data = await bulk_send(http_client, [recipient], channel="sms")
        notification_id = data["notifications"][0]["notification_id"]
        batch_id = data["batch_id"]

        await wait_for_status(http_client, recipient, notification_id, "delivered")

        # Убеждаемся, что провайдер вызван ровно один раз
        call_count_key = f"mock:sms:call_count:{notification_id}"
        count_before = await redis_client.get(call_count_key)
        assert int(count_before or 0) == 1, "Провайдер должен быть вызван ровно 1 раз при первой доставке"

        # Публикуем то же сообщение повторно в основную очередь, имитируя redelivery
        payload = json.dumps(
            {
                "notification_id": notification_id,
                "batch_id": batch_id,
                "recipient_id": recipient,
                "channel": "sms",
                "type": "marketing",
                "message": "Test message",
            }
        ).encode()

        await amqp_channel.default_exchange.publish(
            aio_pika.Message(
                body=payload,
                delivery_mode=aio_pika.DeliveryMode.PERSISTENT,
                priority=1,
            ),
            routing_key="notifications",
        )

        # Ждём, чтобы воркер успел обработать повторное сообщение.
        # wait_for_status неприменим: статус не меняется (дубль должен быть отклонён).
        await asyncio.sleep(3)

        # Счётчик вызовов не должен вырасти — дубликат отклонён Redis SET NX
        count_after = await redis_client.get(call_count_key)
        assert int(count_after or 0) == 1, (
            f"Провайдер вызван {count_after} раз(а) вместо 1 — дедупликация не сработала"
        )


class TestRetryMechanism:
    async def test_temporary_failure_retried_and_delivered(
        self,
        http_client: httpx.AsyncClient,
        redis_client,
    ):
        recipient = unique_recipient()

        # Настраиваем SMS-заглушку: 2 сбоя, затем успех
        await redis_client.set(f"mock:sms:behavior:{recipient}", "temporary_fail")
        await redis_client.set(f"mock:sms:fail_count:{recipient}", 2)

        data = await bulk_send(http_client, [recipient], channel="sms")
        notif_id = data["notifications"][0]["notification_id"]

        # RETRY_TTL_MS=5000 в тестах → два ретрая занимают ~10 с
        result = await wait_for_status(
            http_client, recipient, notif_id, "delivered", timeout=60
        )
        assert result["retry_count"] >= 2

    async def test_permanent_failure_marks_dropped(
        self,
        http_client: httpx.AsyncClient,
        redis_client,
    ):
        recipient = unique_recipient()
        await redis_client.set(f"mock:sms:behavior:{recipient}", "permanent_fail")

        data = await bulk_send(http_client, [recipient], channel="sms")
        notif_id = data["notifications"][0]["notification_id"]

        result = await wait_for_status(
            http_client, recipient, notif_id, "dropped", timeout=20
        )
        assert result["error_message"] is not None

    async def test_max_retries_exceeded_marks_dropped(
        self,
        http_client: httpx.AsyncClient,
        redis_client,
    ):
        recipient = unique_recipient()
        # Сбоить всегда (без fail_count → бесконечный сбой), превысит MAX_RETRIES (3)
        await redis_client.set(f"mock:sms:behavior:{recipient}", "temporary_fail")

        data = await bulk_send(http_client, [recipient], channel="sms")
        notif_id = data["notifications"][0]["notification_id"]

        # 3 ретрая × 5 с TTL = минимум 15 с; даём запас по времени
        result = await wait_for_status(
            http_client, recipient, notif_id, "dropped", timeout=90
        )
        assert result["retry_count"] >= 3
