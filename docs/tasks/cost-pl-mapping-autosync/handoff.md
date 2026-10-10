# Handoff: cost-pl-mapping-autosync

## Что сделано
- `ApplyDefaultCostMappingCommand::$partial`: частичное применение шаблона (правило без
  статьи ОПиУ у компании уходит в `blocked`, остальные применяются). UI и ручная
  команда — без изменений («всё или ничего»).
- `app:marketplace:cost-pl-mapping:sync-default` — cron 04:35 (до пересборки ОПиУ
  04:45) и 06:45 (перед гейтом).
- `app:marketplace:cost-pl-mapping:unmapped-check` — cron 06:50, read-only гейт
  затрат текущего и прошлого месяца без решения по ОПиУ; один агрегированный `error`.
- Условие «нет решения по ОПиУ» — `PreflightCostsQuery::WITHOUT_PL_DECISION`, общее
  для preflight и гейта.
- Гвард «код каталога есть в шаблоне» уже существовал (`DefaultMappingConfigTest`).
- Baseline PHPStan сокращён на 1 запись.

## Проверки
- make site-stan — OK; site-cs-check — OK; site-cs-strict-types — OK
- make site-test-unit — OK, 3294 tests
- make site-test — OK, 5747 tests (до фиксов ревью; после — целевые 54 + 3 теста OK)

## Ревью
- Внутреннее (свежая сессия): 0 BLOCKER, 1 IMPORTANT (гейт краснел бы на категориях
  утренних загрузок → повтор sync-default 06:45) — исправлено; MINOR: тест на
  отключённое правило, выравнивание SQL — исправлено; INFO-лог на пару — оставлен.
- Внешнее (Codex), раунд 1: 1 IMPORTANT (HAVING по сумме прятал затрату и её сторно) —
  исправлено, регрессионный тест красный на старом коде; fixed without re-run.

## FOLLOW-UP
- FK-ошибка при удалении категории ОПиУ между preview и INSERT откатывает всю компанию
  (поведение до задачи), ARCHITECTURE.md обещает `skipped`.

## После деплоя
- Первый ночной прогон 11.10 04:35: для 19621cff… ожидается 15 правил (2 создано +
  13 заполнено); 06:50 гейт красный по 19621cff… (кросс-докинг, возврат со склада,
  корректировка услуг) — до решения Владельца по статьям.
