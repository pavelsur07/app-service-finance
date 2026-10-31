"""Отчёты об ошибках в GlitchTip (Sentry-совместимый): только записи лога уровня ERROR+.

DSN, релиз и окружение — `SENTRY_DSN`, `SENTRY_RELEASE`, `SENTRY_ENVIRONMENT`; пустой DSN —
отчётов нет, только лог. Тела запросов, локальные переменные и ПДн не отправляются: во
входе задач — назначения платежей и контрагенты (services/AGENTS.md §6).
"""

import logging
import os

import sentry_sdk
from sentry_sdk.integrations.logging import LoggingIntegration


def configure_error_reporting() -> bool:
    dsn = os.environ.get("SENTRY_DSN", "").strip()
    if not dsn:
        return False
    sentry_sdk.init(
        dsn=dsn,
        release=os.environ.get("SENTRY_RELEASE") or None,
        environment=os.environ.get("SENTRY_ENVIRONMENT") or None,
        integrations=[LoggingIntegration(level=None, event_level=logging.ERROR)],
        send_default_pii=False,
        include_local_variables=False,
        max_request_body_size="never",
        traces_sample_rate=0.0,
    )
    return True
