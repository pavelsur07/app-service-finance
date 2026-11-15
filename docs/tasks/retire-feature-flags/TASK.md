# Удаление шести временных переменных

Источник намерения — утверждённый план в текущем запросе Владельца (15.11.2026).
Удалить AI_SERVICE_ENABLED, OZON_FETCHER_ENABLED, WB_FETCHER_ENABLED,
SOURCE_FACTS_COMPANIES, TELEGRAM_PAYMENT_INTAKE_ENABLED, TELEGRAM_PAYMENT_INTAKE_COMPANIES.
Постоянные сервисы, доступ к новым разборам через admin grant; ручные исправления
и Confirm прежних черновиков сохраняются при действующих финансовых правах.
Recovery: UUID или --all; компании с grant и незавершённые доставки без grant.
Nightly остаётся отдельным флагом/профилем. Без миграций и новых зависимостей.
Изменение deployment/cron входит в прямо запрошенный план реализации.
Merge/deploy и удаление GitHub Variables пока не разрешены: отдельный handoff.
