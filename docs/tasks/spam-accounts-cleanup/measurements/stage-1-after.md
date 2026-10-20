# Этап 1 — замер до и после удаления на проде

2026-10-20, `codex-psql-ro` (только чтение). Бэкап до удаления:
`/var/backups/app-service-finance/spam-accounts-20261020.dump` (pg_dump -Fc, 460 773 байт;
`user`, `companies`, `project_directions`, `financial_responsibility_centers`,
`financial_responsibility_center_projects`).

Dry-run: в окне [2026-01-14, 2026-01-16) 1326 пользователей, кандидатов 1326, их кабинетов
1326, отсеяно 0. `--execute --expected-count=1326` запустил Владелец.

| Счётчик | До | После | Ожидалось |
|---|---|---|---|
| `user` | 1347 | 21 | 21 |
| `user` в окне атаки | 1326 | 0 | 0 |
| `companies` | 1344 | 18 | 18 |
| `company_members` | 10 | 10 | 10 |
| `project_directions` | 1361 | 35 | 35 |
| `financial_responsibility_centers` | 1348 | 22 | 22 |
| `financial_responsibility_center_projects` | 1356 | 30 | 30 |
| `money_account` | 37 | 37 | без изменений |
| `cash_transaction` | 13421 | 13421 | без изменений |
| `marketplace_connections` | 11 | 11 | без изменений |

Висячие ссылки после удаления: кабинетов без владельца — 0, `project_directions` без
кабинета — 0, `company_members` без пользователя — 0.
