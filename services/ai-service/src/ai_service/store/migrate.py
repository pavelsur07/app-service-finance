"""Forward-only миграции: SQL-файлы `NNNN_name.sql`, прогон явный (`python -m ai_service migrate`)."""

from pathlib import Path

import psycopg
import structlog

log = structlog.get_logger(__name__)

# Произвольный ключ advisory-lock: два одновременных прогона не применяют одну миграцию дважды.
_LOCK_KEY = 7_311_640_001


def migrate(dsn: str, migrations_dir: Path) -> list[str]:
    applied: list[str] = []
    with psycopg.connect(dsn, autocommit=True) as conn:
        conn.execute("SELECT pg_advisory_lock(%s)", (_LOCK_KEY,))
        try:
            conn.execute(
                "CREATE TABLE IF NOT EXISTS schema_migrations ("
                "version text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())"
            )
            done = {row[0] for row in conn.execute("SELECT version FROM schema_migrations").fetchall()}
            for path in sorted(migrations_dir.glob("*.sql")):
                version = path.stem
                if version in done:
                    continue
                with conn.transaction():
                    conn.execute(path.read_text(encoding="utf-8").encode())
                    conn.execute("INSERT INTO schema_migrations (version) VALUES (%s)", (version,))
                applied.append(version)
                log.info("migration_applied", version=version)
        finally:
            conn.execute("SELECT pg_advisory_unlock(%s)", (_LOCK_KEY,))
    return applied
