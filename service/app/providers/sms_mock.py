import asyncio

from app.providers.mock_base import MockNotificationProvider


class SmsMockProvider(MockNotificationProvider):
    CHANNEL_NAME = "SMS"
    BEHAVIOR_KEY_TEMPLATE = "mock:sms:behavior:{recipient_id}"
    FAIL_COUNT_KEY_TEMPLATE = "mock:sms:fail_count:{recipient_id}"
    CALLS_KEY = "mock:sms:calls"
    CALL_COUNT_KEY_TEMPLATE = "mock:sms:call_count:{notification_id}"

    async def _apply_slow_behavior(self) -> None:
        await asyncio.sleep(0.3)
