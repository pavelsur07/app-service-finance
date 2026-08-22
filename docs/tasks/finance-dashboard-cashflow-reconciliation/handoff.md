## Handoff

### Результат

- Stage 1 и Stage 2 завершены, локальные проверки и оба обязательных review gate зелёные.
- Delivery branch: `task/finance-dashboard-cashflow-reconciliation`.
- Base PR обязан быть `master`.
- Миграций, зависимостей и ручных production-операций нет.

### Release Gate

- Требуется отдельное решение Владельца о переводе Draft PR в Ready и merge.
- Merge в `master` автоматически запускает production deploy, поэтому перед merge требуется явное одобрение и merge, и автоматического production deploy.
- Текущее одобрение задачи покрывает реализацию и Draft PR, но не Ready/merge/deploy.

### Delivery limitation

- Создание Draft PR ранее дважды блокировалось локальной execution policy до обращения к GitHub: `approval required by policy`, при этом approval mode установлен в `never`.
- После финального push создание Draft PR необходимо повторить; если ограничение сохранится, ветка остаётся опубликованной и готовой к ручному созданию Draft PR с base `master`.
