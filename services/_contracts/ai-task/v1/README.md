# ai-task v1

Конверты API задач `services/ai-service`. Определения — `$defs` в `schema.json`:

| Определение | Где |
|---|---|
| `task_request` | тело `POST /internal/tasks` |
| `task_accepted` | ответ 202 на постановку |
| `task_status` | `GET /internal/tasks/{task_id}` |
| `results_page` / `task_result` | `GET /internal/tasks?status=done&after=&limit=` |
| `ack_request` / `ack_response` | `POST /internal/tasks/ack` |
| `echo_payload` / `echo_output` | диагностическая задача `echo` |

Правила:

- `Idempotency-Key` в заголовке равен `idempotency_key` в теле; повтор с тем же
  ключом возвращает прежний `task_id` (`duplicate=true`), даже если в повторе
  другой `task_id`; тот же ключ с другими `task_type`, `company_id` или
  `payload` — 409 `idempotency_conflict`;
- `output` обнуляется после ack;
- деньги в payload/output задач — десятичные строки + `currency`
  (`services/AGENTS.md` §1.3);
- `examples/` — фикстуры для тестов контракта с обеих сторон.
