## Stage 3: свод по тегам в расширенной юнит-экономике — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** yes (нужны Stage 1–2)
**Следующее действие:** задача закрыта, Final Release Gate — ждать решения Владельца

### Scope Stage

- Stage base commit: `4b34f728`
- Ветка: `codex/listing-tags`

### Что сделано

- `UnitExtendedQuery`: флаг `withTagSummary`. Когда включён — в цикле по ВСЕМ листингам (до
  search-фильтра, как totals) копятся компактные записи с абсолютами и тегами; `buildTagSummary()`
  раскладывает их по бакетам (tagId + «Без тегов») и пересчитывает отношения (profit, margin, ROI,
  ДРР, CAC) теми же хелперами, что и totals. Ключ ответа `tagSummary` (пустой без флага).
- Двойной счёт мультитегов решён **честной подписью**: листинг с N тегами учтён в N бакетах,
  «Без тегов» — отдельный бакет, в UI заметка «сумма по тегам может превышать итог».
  Взаимоисключающие группы не делались (вне scope).
- adSpend в бакете = сумма построчной атрибутированной рекламы (полный период по тегу неразделим —
  та же семантика, что фильтрованные totals в Stage 2).
- API `UnitExtendedController`: параметр `withTagSummary` (`getBoolean`).
- Фронтенд: тумблер «Свод по тегам»; при включении хук шлёт `withTagSummary=1`, рисуется карточка
  `TagSummaryTable` (Тег · Листингов · Выручка · Кол-во · Себест. · Реклама · Итого затрат ·
  Прибыль · Маржа%) + строка «Без тегов» + заметка про двойной счёт. Основная таблица остаётся.

### Затронутые файлы

- `src/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQuery.php` — modified (`buildTagSummary`)
- `src/MarketplaceAnalytics/Controller/Api/UnitExtendedController.php` — modified (`withTagSummary`)
- `assets/react/_legacy/marketplace-analytics/unit-extended/TagSummaryTable.tsx` — new
- `assets/react/_legacy/marketplace-analytics/unit-extended/{unitExtended.types.ts,useUnitExtended.ts,UnitExtendedWidget.tsx}` — modified
- `tests/Unit/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQueryTest.php` — modified (+3 теста)
- `tests/Functional/MarketplaceAnalytics/UnitExtendedTagFilterControllerTest.php` — modified (+1 тест)

### Ponytail-skip (сознательно)

- Свод не добавлен в XLSX-экспорт — в выгрузке есть колонка «Теги» (Stage 2), сводная делается
  в Excel одним pivot'ом.
- Взаимоисключающие группы тегов, версионирование, график по группам — вне scope.
- Агрегат считает бэкенд (переиспользуя формулы totals), а не фронт: производные поля — отношения,
  их нельзя суммировать, а вторая копия денежной математики в TS разъехалась бы.

### Self-review

- [x] Scope compliance — только свод по тегам; чужие модули не тронуты
- [x] Единый источник формул — `buildTagSummary` переиспользует `averagePerNetSoldQty` и те же
      формулы profit/margin/ROI/ДРР, что totals
- [x] Двойной счёт — покрыт тестом (2-тег листинг в обоих бакетах, «Без тегов» отдельно)
- [x] Свод по тому же набору, что totals (до search-фильтра), с учётом фильтра по тегам
- [x] Security (IDOR) — данные из уже company-scoped `execute`; новых запросов к БД нет
- [x] ESLint по изменённым TS/TSX — чисто; Vite build — OK; cs-fixer точечно — чисто; PHPStan в проекте нет
- [x] `make site-test` (полный прогон на свежей БД) — зелёный (2591 тест)

### External Claude Code review

- Iterations: 0
- Result: N/A — реализацию выполнял Claude Code; внешний review той же моделью своего diff
  не даёт независимости. Проведён полный внутренний review.

### Команды для проверки

- `docker compose run --rm site-php-cli vendor/bin/phpunit tests/Unit/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQueryTest.php`
- `docker compose run --rm site-php-cli vendor/bin/phpunit tests/Functional/MarketplaceAnalytics/UnitExtendedTagFilterControllerTest.php`
- `node_modules/.bin/eslint 'assets/react/_legacy/marketplace-analytics/unit-extended/*.tsx'`
- `make site-test`

### Риски / на что обратить внимание ревьюеру

- **Сумма по тегам ≠ итог** при пересекающихся тегах — это заявленное поведение (честная подпись),
  а не баг. Заметка в UI это проговаривает.
- **adSpend в своде — только атрибутированная реклама** (неатрибутированную нельзя разложить по
  тегам). Та же семантика, что у фильтрованных totals в Stage 2.
- **Включение тумблера перезапрашивает данные** с `withTagSummary=1` — лишний, но недорогой запрос
  при переключении; выключен — свод не считается вовсе.

### Открытые вопросы

- нет
