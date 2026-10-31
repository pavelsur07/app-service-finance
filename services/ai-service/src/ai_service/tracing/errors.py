"""Отчёты об ошибках в GlitchTip (Sentry-совместимый): только записи лога уровня ERROR+.

DSN, релиз и окружение — `SENTRY_DSN`, `SENTRY_RELEASE`, `SENTRY_ENVIRONMENT`; пустой DSN —
отчётов нет, только лог. Во входе задач — назначения платежей и контрагенты
(services/AGENTS.md §6), а текст исключения может их содержать: стандартные и
автоматические интеграции (FastAPI, Starlette, excepthook) выключены — в GlitchTip уходят
только записи лога, уже очищенные приложением; тела запросов и локальные переменные — нет.
"""

import logging
import os
from typing import Any

import sentry_sdk
from sentry_sdk.integrations.logging import LoggingIntegration


def configure_error_reporting(transport: Any = None) -> bool:
    dsn = os.environ.get("SENTRY_DSN", "").strip()
    if not dsn:
        return False
    sentry_sdk.init(
        dsn=dsn,
        release=os.environ.get("SENTRY_RELEASE") or None,
        environment=os.environ.get("SENTRY_ENVIRONMENT") or None,
        default_integrations=False,
        auto_enabling_integrations=False,
        integrations=[LoggingIntegration(level=None, event_level=logging.ERROR)],
        send_default_pii=False,
        include_local_variables=False,
        max_request_body_size="never",
        traces_sample_rate=0.0,
        transport=transport,
    )
    return True
