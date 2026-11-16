# Stage 2: репетиция и handoff — сохранённое состояние

Risk HIGH-LOCAL. Stage base: da7cef655 (исходный task diff).
Implementation DONE; delivery blocked by общий local acceptance environment.

Временная PostgreSQL15: реальный pg_dump app+postgres, непустые custom archives,
полное чтение pg_restore в /dev/null, checksum, unchanged row17, damaged archive
rejected. Проверка не выполняла восстановление БД. Контейнер/данные удалены.
Actual SSH/heredoc и оба stdin helper-entrypoint проверены заглушкой SSH/Docker.
Regression: 12 resolver/publisher, 8 production, 8 SSH, 1 real PG; existing
6 runtime, 6 frontend и shell-инварианты GREEN. Actionlint1.7.12 и diff-check GREEN.

Fresh final internal B0/I0/M0. Суммарно internal I3 (malformed manifest, enabled
nightly health, realistic registry404 fallback) исправлены; external 3 rounds,
B2/I4 исправлены; round3 REVIEW_GREEN, последний MINOR stdin fixed without rerun.
Ревьюер не исполнял production и не получил секретов; root-файлы вне фильтра
external wrapper были приложены целиком через context.

Обе задачи не меняют PHP application sources. Общие PHP проверки: cs/strict GREEN;
Unit4928, Integration3037, Functional1497 GREEN отдельно. Local PHPStan дважды
остановлен доказанным global OOM (worker1, затем main-only), три полных GREEN
cold/warm/warm не подтверждены. Это не ложная зелень и не пропуск замечаний.
Первая задача/порядок доставки остаётся незавершённой; PR сохраняется Draft.
Production acceptance SHA/digest/schema/health/timings — только после разрешения
владельца на выпуск, сейчас не выполнялась.

Follow-ups из утверждённого плана: backup retention/restore; cleanup неуспешных
staging/config; измерение экономии после сопоставимых успешных runs. Не добавлены
новые зависимости, права или автоматический rollback.
