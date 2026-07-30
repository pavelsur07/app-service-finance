## Stage 2: Backfill производных полей + отчёт по кандидатам-дублям — DONE

**Риск:** 🟠 HIGH-LOCAL (локально) / 🔴 прогон на PROD — Production Gate
**Owner gate:** yes (Release Gate 1)
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** continue autonomously (Stage 3), PROD-прогон — только в Production Gate

### Scope Stage
- Stage base commit: `89d0e5365e5cc5e8b5acb762afcccae45bd2c0ce`
- Work items completed: `2.1`, `2.2`, `2.3`, `2.4`

### Что сделано
- `BackfillCounterpartyNamesAction` — идемпотентный пересчёт производных полей:
  строка обновляется только если `core`/`legalFormHint` реально изменились.
  `updatedAt` не трогается вообще: для этого в Entity есть отдельный
  `refreshNormalizedName()`, который отказывается менять название (защита от того,
  чтобы backfill не превратился в тихое переименование).
- Ненормализуемое название (пустое, только пробелы) не роняет прогон: строка
  попадает в список «требуют ручного разбора» и логируется `warning`, не `error` —
  это ожидаемое доменное условие, алерт в GlitchTip не нужен.
- Один проход и один `flush()` (Lite, D1: 317 строк на PROD). Messenger-батч,
  батчи по 500 и `clear()` не вводились.
- Команда `app:counterparty:backfill-names [--dry-run] [--company-id] [--similarity]`.
- `CounterpartyDuplicateCandidatesQuery` — только отчёт, ничего не меняет:
  - пары с `similarity(name_core) > 0.6` **при совпадающей ОПФ**;
  - группы с одинаковым ИНН внутри компании;
  - строки с ИНН, который не пройдёт валидацию при следующей правке карточки.
- Фильтр по компании собирается условием, а не через `:companyId IS NULL`:
  PostgreSQL не выводит тип параметра, используемого только в `IS NULL` (эта ошибка
  была поймана интеграционными тестами, не review).

### Ключевое уточнение против ТЗ
ТЗ предлагало искать дубли по похожести `name_search`. Замер PROD (plan.md §0) показал,
что так склеиваются `ООО "Балтийский лизинг"` и `АО "Балтийский лизинг"` — разные
юрлица с разными ИНН. Поэтому пара считается кандидатом только при совпадающей ОПФ
(`legal_form_hint IS NOT DISTINCT FROM`), и на это есть отдельный тест.

### Затронутые файлы
- `src/Company/Application/BackfillCounterpartyNamesAction.php` — new
- `src/Company/Application/DTO/CounterpartyBackfillResult.php` — new
- `src/Company/Command/BackfillCounterpartyNamesCommand.php` — new
- `src/Company/Infrastructure/Query/CounterpartyDuplicateCandidatesQuery.php` — new
- `src/Company/Repository/CounterpartyRepository.php` — modified (`findAllForBackfill`)
- `tests/Integration/Company/BackfillCounterpartyNamesActionTest.php` — new
- `tests/Integration/Company/CounterpartyDuplicateCandidatesQueryTest.php` — new

### Self-review
- [x] Scope compliance
- [x] Идемпотентность — тест «второй прогон ничего не меняет» + ручной прогон на dev-БД
      (21 строка: первый прогон 21 обновлено, второй — 0 обновлено, 21 без изменений)
- [x] `--dry-run` не пишет в БД — отдельный тест
- [x] `updatedAt` не изменился — отдельный тест
- [x] Уровни логов: `warning` на ожидаемом, `info` на итог; `error` нет
- [x] Security — отчёт CLI-only, есть фильтр по компании; SQL с явным перечислением колонок
- [x] CS-Fixer — чисто
- [x] Tests — `make site-test`: OK (2820 tests)

### External Claude Code review
- См. `stage-3.md`: прогон на полный diff Stage 1–3; на момент коммита
  `REVIEW_GREEN` не получен.

### Команды для проверки
- `make site-test`
- `docker compose run --rm site-php-cli bin/console app:counterparty:backfill-names --dry-run`

### Риски / на что обратить внимание ревьюеру
- `findSimilarNamePairs()` — self-join O(n²), помечен `ponytail:`-комментарием с
  указанием потолка. На 317 строках это миллисекунды; при десятках тысяч нужен
  GIN-индекс и постраничный обход.
- Backfill грузит все строки через `toIterable()` и делает один `flush()` в конце —
  на текущем объёме нормально, при росте на порядок потребуется батчинг.

### Открытые вопросы
- Порядок на PROD: миграция → `--dry-run` → прогон → проверка нулевого остатка.
  Каждый шаг — отдельное разрешение Владельца (Production Gate).
