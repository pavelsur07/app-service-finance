"""Настройки из переменных окружения (pydantic-settings), без значений секретов в коде."""

from pathlib import Path

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict

_PACKAGE_ROOT = Path(__file__).resolve().parents[2]


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_prefix="AI_SERVICE_", extra="ignore")

    db_dsn: SecretStr
    # Токен направления «Symfony → сервис» (service-gateway.md §2.9).
    api_token: SecretStr = Field(min_length=16)
    http_host: str = "0.0.0.0"  # noqa: S104 — только внутренняя docker-сеть, наружу не публикуется
    http_port: int = 8080
    routes_file: Path = _PACKAGE_ROOT / "config" / "routes.yaml"
    migrations_dir: Path = _PACKAGE_ROOT / "migrations"
    log_level: str = "INFO"

    worker_poll_seconds: float = 1.0
    worker_lease_seconds: int = 300
    worker_max_attempts: int = 3
    worker_retry_backoff_seconds: int = 30
    trace_ttl_days: int = 30
    trace_cleanup_interval_seconds: int = 3600

    llm_timeout_seconds: float = 60.0
    llm_retries_per_model: int = 1
