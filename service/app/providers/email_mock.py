from app.providers.mock_base import MockNotificationProvider


class EmailMockProvider(MockNotificationProvider):
    CHANNEL_NAME = "Email"
    BEHAVIOR_KEY_TEMPLATE = "mock:email:behavior:{recipient_id}"
    FAIL_COUNT_KEY_TEMPLATE = "mock:email:fail_count:{recipient_id}"
    CALLS_KEY = "mock:email:calls"
    CALL_COUNT_KEY_TEMPLATE = "mock:email:call_count:{notification_id}"
