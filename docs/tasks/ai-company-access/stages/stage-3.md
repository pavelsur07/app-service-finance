# Этап 3. Выкат

## Задача
Выкатить этапы 1–2 без перерыва ИИ у Вумджой и убрать GitHub variable.

## Результат
PR влит; миграция применена отдельным dispatch (`production_action=migrations`), затем
деплой (`production_action=deploy`); variable `AI_SERVICE_COMPANY_ALLOWLIST` удалена.

## Что считаем выполненным
Деплой зелёный, сверка на проде пройдена, отчёт Владельцу.

## Чек-лист приёмки
- [ ] CI PR зелёный, внешнее ревью без блокеров
- [ ] миграция на проде: `ai_company_access` — 1 строка, Вумджой
- [ ] деплой зелёный до «Деплой завершён»
- [ ] cron ai-categorize после деплоя: «1 enabled companies»
- [ ] `/admin/users` на проде: у Вумджой «ИИ вкл»
- [ ] GitHub variable удалена (`gh variable list` без неё)

## Итог
_дописывается после выполнения_
