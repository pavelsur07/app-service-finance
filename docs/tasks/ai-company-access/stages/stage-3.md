# Этап 3. Выкат

## Задача
Выкатить этапы 1–2 без перерыва ИИ у Вумджой и убрать GitHub variable.

## Результат
PR влит; миграция применена отдельным dispatch (`production_action=migrations`), затем
деплой (`production_action=deploy`); variable `AI_SERVICE_COMPANY_ALLOWLIST` удалена.

## Что считаем выполненным
Деплой зелёный, сверка на проде пройдена, отчёт Владельцу.

## Чек-лист приёмки
- [x] CI PR зелёный, внешнее ревью без блокеров
- [x] миграция на проде: `ai_company_access` — 1 строка, Вумджой
- [x] деплой зелёный до «Деплой завершён»
- [ ] cron ai-categorize после деплоя: «1 enabled companies» — не подтверждено, см. итог
- [ ] `/admin/users` на проде: у Вумджой «ИИ вкл» — проверяет Владелец (вход в админку агенту недоступен)
- [x] GitHub variable удалена (`gh variable list` без неё)

## Итог
05.11.2026: PR #2891 влит (4e986e4c); миграция — run 42088101745 (`Successfully migrated to
Version20261105120000`); деплой — run 42089705929, «Деплой завершён без даунтайма».
Прод: `ai_company_access` — 1 строка `19621cff-…`, `enabled_by_user_id` пуст (перенос).
`gh variable delete AI_SERVICE_COMPANY_ALLOWLIST` выполнен, осталась только `AI_SERVICE_ENABLED=true`.

Cron 22:25 МСК: подтвердить «1 enabled companies» нечем — команда идёт с `--quiet`, логи
scheduler агенту недоступны (`deploy-diagnostics` требует пароль), а новых операций у Вумджой
с 01.11 08:25 нет (0), поэтому новых запросов к ИИ быть и не должно (последний — 01.11 08:25).
Первая новая операция без статьи покажет работу: строка `requested` в `cash_category_suggestion`
в ближайший :25.
