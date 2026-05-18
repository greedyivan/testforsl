"""Централизованные Redis ключи.

Единое место для всех ключей — при переименовании или смене схемы
достаточно поправить здесь, не искать строки по всему проекту.
"""


class RedisKeys:
    # ── Идемпотентность ──────────────────────────────────────────────────────
    IDEMPOTENCY_API = "ik:api:"
    IDEMPOTENCY_WORKER = "ik:worker:"

    # ── Mock SMS провайдер ───────────────────────────────────────────────────
    MOCK_SMS_BEHAVIOR = "mock:sms:behavior:"
    MOCK_SMS_FAIL_COUNT = "mock:sms:fail_count:"
    MOCK_SMS_CALLS = "mock:sms:calls"
    MOCK_SMS_CALL_COUNT = "mock:sms:call_count:"

    # ── Mock Email провайдер ─────────────────────────────────────────────────
    MOCK_EMAIL_BEHAVIOR = "mock:email:behavior:"
    MOCK_EMAIL_FAIL_COUNT = "mock:email:fail_count:"
    MOCK_EMAIL_CALLS = "mock:email:calls"
    MOCK_EMAIL_CALL_COUNT = "mock:email:call_count:"
