# Контекст для внешнего ревью

Задача и согласованная схема — docs/tasks/cost-pl-mapping-autosync/plan.md.

## Cron (docker/cron/app.cron, вне диффа site/)
```
35 4 * * * ... app:marketplace:cost-pl-mapping:sync-default --no-interaction   # до пересборки ОПиУ 04:45
45 6 * * * ... app:marketplace:cost-pl-mapping:sync-default --no-interaction   # повтор перед гейтом
50 6 * * * ... app:marketplace:cost-pl-mapping:unmapped-check --no-interaction # read-only гейт
```
04:45 `month-preliminary-rebuild` диспатчит async-сообщения; sync-default синхронный и
заканчивается за секунды.

## Уже найдено и исправлено внутренним ревью
- IMPORTANT: гейт краснел бы на категориях утренних загрузок 05:15–06:20, которые
  чинит следующий ночной прогон → добавлен повтор sync-default в 06:45.
- MINOR: регрессионный тест — отключённое правило (`include_in_pl=false`) не
  заполняется ночным прогоном; выравнивание SQL в PreflightCostsQuery.

## Принятые решения (не находки)
- Action пишет INFO на каждую пару (компания, маркетплейс) за прогон — существующий
  лог, пар единицы-десятки; оставлено.
- Удаление категории ОПиУ между preview и INSERT даёт FK-ошибку и откат всей
  компании (поведение существовало до задачи) — FOLLOW-UP; в ночном прогоне
  это FAILURE одной компании, остальные применяются.
- Компания без статьи шаблона держит гейт красным, пока человек не назначит
  статью, — так задумано и согласовано с Владельцем (починка достижима).
- Схема: SELLER-подключение уникально по (company_id, marketplace, connection_type);
  маппинг уникален по (company_id, cost_category_id).
