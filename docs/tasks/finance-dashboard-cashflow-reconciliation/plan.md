# Сверка KPI финансового дашборда с ДДС

Цель: показывать точные даты вместо относительных «30 дней» и открывать из трёх оборотных KPI отчёт ДДС в специальном режиме с тем же периодом, валютой, видом деятельности и правилами исключения операций.

## Stage 1: Единый серверный контур сверки KPI и ДДС
Risk: HIGH-LOCAL
owner_gate: no
release_candidate: no
independently_deployable: no
stage_base_commit: `3fc4e02f427e042d801186fc3bbcaa63f315359f`

Definition of Done:
- KPI provider возвращает текущий и предыдущий точные 30-дневные интервалы и дату сравнения остатка.
- Авторизованные HTML/JSON маршруты ДДС принимают opt-in параметры `reconcile=dashboard`, `activity`, `currency`; публичные JSON/CSV их игнорируют.
- В режиме сверки ДДС применяет те же даты, валюту, вид деятельности, исключение переводов, удалённых операций и технических категорий, что и KPI.
- Для выбранного вида деятельности исключаются нераспределённые статьи; для `all` они сохраняются, как на дашборде.
- Payload содержит точные `inflow`, `outflow`, `net`, рассчитанные существующим агрегатором KPI без float-арифметики.
- Обычный ДДС и публичный контракт не изменяют поведение.
- Миграции, новые зависимости, изменения справочника категорий и production-действия отсутствуют.

Work items:
- 1.1 — добавить метаданные периодов в `FinanceDashboardKpiProvider` и покрыть их интеграционным тестом.
- 1.2 — расширить внутренние параметры и mapper безопасным opt-in режимом, сохранив публичный default.
- 1.3 — применить scope сверки и добавить точную сводку в `CashflowReportBuilder`/JSON formatter.
- 1.4 — добавить регрессионные тесты совпадения KPI и ДДС и совместимости обычного режима.

Stage checks:
- `php bin/phpunit tests/Integration/Finance/Application/Service/FinanceDashboardKpiProviderTest.php`
- `php bin/phpunit tests/Unit/Report/Cashflow/CashflowReportRequestMapperTest.php tests/Unit/Report/Cashflow/CashflowReportBuilderTest.php`
- `php bin/phpunit tests/Functional/Finance/CashflowJsonExportControllerTest.php tests/Functional/Finance/PublicCashflowReportControllerTest.php`
- lint изменённых PHP-файлов и Twig.

Reviewer focus:
- идентичность финансового scope KPI и режима сверки;
- отсутствие изменения обычного и публичного ДДС;
- tenant isolation и точная decimal-арифметика сводки.

## Stage 2: Точные периоды и переход к сверке в обоих UI
Risk: MEDIUM
owner_gate: yes
release_candidate: yes
independently_deployable: yes
stage_base_commit: будет зафиксирован после Stage 1

Definition of Done:
- В legacy и app UI текущий период отображается точными датами, например `24.07–22.08`.
- Сравнение оборотов подписано точным предыдущим периодом, например `24.06–23.07`; сравнение остатка — `На 23.07`.
- Для периода между разными годами подписи включают годы.
- В карточках «Приход», «Расход», «Чистый поток» есть ссылка «Сверить в ДДС» с теми же `from`, `to`, `activity`, `currency`.
- ДДС явно показывает режим сверки, scope и точную сводку; параметры сохраняются при смене периода/группировки и JSON-экспорте.
- В режиме сверки скрыты несовместимые фильтры проектов/ЦФО, показано пояснение про остатки; есть выход в обычный ДДС.
- Обычный интерфейс ДДС не меняет поведение.

Work items:
- 2.1 — передать метаданные периодов в оба dashboard Twig и заменить относительные подписи.
- 2.2 — добавить одинаковые ссылки из трёх оборотных карточек.
- 2.3 — оформить специальный режим и сохранение параметров в шаблоне ДДС.
- 2.4 — добавить functional/Twig регрессионные проверки обоих UI и экспорта.

Stage checks:
- релевантные unit/integration/functional тесты Stage 1 и Stage 2;
- `php bin/console lint:twig templates/home/index.html.twig templates/app/home/index.html.twig templates/report/cashflow.html.twig`;
- точечный PHP CS check изменённых файлов.

Reviewer focus:
- URL одинаков во всех трёх карточках и не появляется у остатка;
- даты включительны и корректны на границе года;
- a11y/совместимость существующей Twig-разметки;
- opt-in параметры не протекают в публичные endpoints.

## Release Gate

После Stage 2: один Draft PR в `master`, оба review зелёные, CI status известен. Требуется отдельное решение Владельца о переводе PR в Ready/merge.

## Production Gate

Merge и вызванный им автоматический production deploy — только после отдельного явного одобрения обоих действий. Миграций и иных production-мутаций задача не содержит.

## Области изменений

- `site/src/Finance/Application/Service/FinanceDashboardKpiProvider.php`
- `site/src/Report/Cashflow/*`
- `site/src/Finance/Controller/*Cashflow*`
- `site/src/Finance/Infrastructure/Normalizer/CashflowReportJsonFormatter.php`
- `site/templates/home/index.html.twig`
- `site/templates/app/home/index.html.twig`
- `site/templates/report/cashflow.html.twig`
- релевантные тесты и task-документация.

## Не менять

- формулы и знаковую конвенцию существующих KPI;
- данные/иерархию категорий, schema и migrations;
- project/ЦФО семантику обычного отчёта;
- публичный JSON/CSV контракт;
- UI Kit, Vite/React и зависимости.
