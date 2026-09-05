### Stage 1: Ozon accrual — сопоставить два кода и сделать ночные health-гейты достижимо зелёными — DONE

**Risk:** MEDIUM
**Owner gate:** yes — маппинг категорий меняет ОПиУ за август и сентябрь
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required — merge и срок деплоя

#### Stage scope
- Stage base commit: `63a8db2ad4d35059f43863a653a90416bccf26d2`
- Work items completed: `1.1` (каталог категорий), `1.2` (гейт daily-maintenance), `1.3` (гейт verify-rolling-refresh)

#### What was done

**Дефект подтверждён в данных прода до правки кода.** GlitchTip issues 237 (42 события) и 274
(30 событий) горели `error` каждую ночь. В `ingest_external_categories` для
`ozon`/`ozon_finance_accrual_by_day`: 155 mapped, 60 deprecated и ровно 2 `new` —
`LabelBrandVerified` (first_seen 2026-08-02, seen_count 2862) и `Installment`
(first_seen 2026-08-20, seen_count 109), обе видны до 2026-09-05.

Неклассифицированных канонических транзакций ровно 36: 35 строк `LabelBrandVerified`
(по одной в день с 2026-08-01 по 2026-09-04, суммарно 52 500 ₽) и 1 строка `Installment`
(2026-08-19, 88,88 ₽). Это сходится с `unclassifiedTransactions: 36` из issue 237 и с
`unknownCategoryRows` 1 и 35 из issue 274. Глобальный счёт за всё время тоже 36 — весь
объём внутри окна.

**Первоначальный диагноз был неверен и исправлен по данным.** Предполагалось, что гейт
считает накопленный объём вместо свежести. Окно в `daily-maintenance` уже было, а свежие
неклассифицированные строки приходят каждый день — гейт репортил реальный дефект.
Настоящая причина: двух кодов не было в каталоге `OzonAccrualCategory`, поэтому
сопоставить их было нечем, и состояние оставалось недостижимо зелёным.

Сделано:
1. Две категории добавлены в каталог — `ozon_brand_verification_labeling` («Маркировка
   проверенного бренда») и `ozon_installment` («Рассрочка»), обе в группе «Другие услуги и
   штрафы», `TransactionType::FEE`. Группа и тип выбраны Владельцем явно.
2. Health-гейт `daily-maintenance` разделён по смыслу. `error` остаётся только там, где
   строку нечем сопоставить: за ней нет зарегистрированной категории ни по внешнему коду,
   ни по `type_id`. `DiscoverExternalCategoriesAction` выполняется в том же прогоне до
   health-проверки и ставит в очередь всё, что может опознать, а его выборка требует
   непустой `_ingestion_type_id` — значит остаток и есть строки, которые не поставит в
   очередь никто. Строки с уже видимой категорией дают `warning`.
3. `verify-rolling-refresh` перестал валить цель из-за `unknownCategoryRows`. Команда
   сверяет сырьё с каноническими транзакциями; нераспознанная категория расхождением
   сверки не является — прод это и показывал: `countMismatches` и `amountMismatches` по
   нулям при красном гейте. Расхождения сумм и количеств и `nonDoneRaw` по-прежнему
   дают `error`. После цикла пишется один агрегированный `warning`.

#### Files changed
- `site/src/Ingestion/Application/Source/Ozon/OzonAccrualCategory.php` — modified
- `site/src/Ingestion/Infrastructure/Query/ExternalCategoryAdminQuery.php` — modified
- `site/src/Ingestion/Command/OzonAccrualDailyMaintenanceCommand.php` — modified
- `site/src/Ingestion/Command/OzonAccrualVerifyRollingRefreshCommand.php` — modified
- `site/tests/Unit/Ingestion/Application/Source/Ozon/OzonAccrualCategoryTest.php` — modified
- `site/tests/Integration/Ingestion/Infrastructure/Query/ExternalCategoryAdminQueryTest.php` — modified
- `site/tests/Integration/Ingestion/Command/OzonAccrualDailyMaintenanceCommandTest.php` — modified
- `site/tests/Integration/Ingestion/Command/OzonAccrualVerifyRollingRefreshCommandTest.php` — modified

#### Definition of Done
- [x] `LabelBrandVerified` и `Installment` резолвятся в собственные статьи группы «Другие услуги и штрафы»
- [x] строка с категорией в очереди на разбор даёт `warning`, а не ночной `error`
- [x] строка, которую discovery не поставит в очередь никогда, по-прежнему даёт `error`
- [x] `verify-rolling-refresh` не падает при сошедшейся сверке с нераспознанной категорией
- [x] расхождения сумм и количеств по-прежнему валят `verify-rolling-refresh`
- [x] изменение поведения доказано красным на коде до правки
- Исключено из Stage: issue 2 (единый fingerprint supercronic, P2), разведение GlitchTip с
  проектом conwix (P3), issue 287 (P4), запуск пересчёта на проде (Production Gate)

#### Baseline
- `php bin/phpunit --filter 'OzonAccrualCategoryTest|OzonAccrualDailyMaintenanceCommandTest|OzonAccrualVerifyRollingRefreshCommandTest|ExternalCategoryAdminQueryTest'` — OK (18 тестов, 560 assertions)
- красного baseline в репозитории нет: cs, strict-types и stan были зелёными до задачи

#### Checks
- targeted: `php bin/phpunit --filter 'ExternalCategoryAdminQueryTest|OzonAccrualDailyMaintenanceCommandTest|OzonAccrualVerifyRollingRefreshCommandTest|OzonAccrualCategoryTest'` — OK (25 тестов, 612 assertions)
- full relevant stage: `make site-test-unit` — OK (2292, 4 pre-existing deprecations);
  `make site-test-integration` — OK (1224); `composer test:functional` — OK (598, 2 pre-existing deprecations)
- `make site-cs-check` — Found 0 of 2466 (перепроверено с `--using-cache=no`)
- `make site-cs-strict-types` — Found 0 of 2466
- `make site-stan` — No errors; `phpstan-baseline.neon` не менялся

**Красное доказательство изменения поведения.** На коде до правки:
- `testExecuteWarnsInsteadOfFailingWhenUnclassifiedCodeIsQueuedForMapping` — падает
- `testExecuteWarnsWhenUnclassifiedTransactionIsDiscoverableByTypeId` — падает
- `testUnknownCategoryRowsWarnInsteadOfFailingWhenParityHolds` — падает
  (фикстура воспроизвела прод один в один: `unknownCategoryRows 1`, `amountMismatches 0`,
  `countMismatches 0`, `failedTargets 1`)
- `testAugust2026OzonCodesResolveToOwnCategories` и `testFindsObservedInternalOzonExternalCodes` — падают
- guard `testExecuteStillFailsWhenUnclassifiedRowCannotBeQueuedForReview` — зелёный и до, и после

**Мутационная проверка guard-тестов**, добавленных по находке ревьюера (иначе тест,
который не может упасть, бесполезен):
- замена `NOT EXISTS` на `LEFT JOIN` → `testRowMatchingTwoQueuedCategoriesIsCountedOnce`
  падает с «Failed asserting that 2 is identical to 1», то есть строка действительно размножается
- снятие фильтра `ec.status IN (:pendingStatuses)` → `testRowMatchingOnlyMappedCategoryStaysOrphan` падает

#### Internal automatic review
- Iterations: 2
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: выражение identity дублировалось в запросе трижды, а подзапрос `EXISTS` — дважды;
  переписано через внутренний SELECT, где и идентичность строки, и признак `is_orphan` определены
  по одному разу. Список целей в контексте лога `verify-rolling-refresh` ограничен срезом из 20
  записей плюс полный счётчик — целей может быть до 500. Рост PHPStan-baseline из-за
  `Company::getId(): ?string` снят локальной переменной с `assertNotNull`, baseline не менялся
- FOLLOW-UP: предикат «неклассифицировано» существует в двух местах — в
  `ExternalCategoryAdminQuery` и в `DiscoverExternalCategoriesAction::unknownOzonAccrualRows`,
  и они уже слегка расходятся (`LIKE 'Ozon accrual%'` по `_ozon_category_label` против
  `COALESCE(ft.description, '')`). Сведение в одно определение — отдельная задача, в scope не входило

#### External Claude Code review
- Реализация — Claude Code, внешний ревьюер — Codex (`codex exec -s read-only --ephemeral`), по таблице ролей `CLAUDE.md`
- Iterations: 3
- Result: REVIEW_GREEN
- Confirmed findings fixed: итерация 1 дала одну находку MINOR — guard-тесты не поймали бы
  замену `EXISTS` на `JOIN` и снятие фильтра по статусам. Находка принята, добавлены
  `testRowMatchingTwoQueuedCategoriesIsCountedOnce` и `testRowMatchingOnlyMappedCategoryStaysOrphan`,
  их чувствительность доказана мутациями (выше). Итерации 2 и 3 — без находок
- Rejected findings with reason: none. В первой итерации я предупредил ревьюера о риске
  повторного раскрытия list-параметра `:pendingStatuses`; ревьюер справедливо указал, что в
  итоговом SQL параметр встречается один раз, и риск неактуален
- Ограничение ревьюера: шелла у него не было, тесты и мутации он не запускал и результаты
  принял из промпта. Факты о проде (issues GlitchTip, содержимое `ingest_external_categories`
  и `ingest_financial_transactions`, поведение `DiscoverExternalCategoriesAction`) переданы
  в промпт — самостоятельно проверить их ревьюер не мог

#### Review fixes applied
- Добавлены два guard-теста по находке MINOR первой итерации, чувствительность доказана мутациями
- После второго REVIEW_GREEN применена одна правка стиля, потребованная `php-cs-fixer`
  (правило `single_quote` на последнем конкатенируемом литерале SQL); текст SQL не изменился,
  третья итерация ревью прогнана на итоговом диффе

#### Risks / reviewer focus
- Маппинг меняет ОПиУ: 52 588 ₽ переедут из «Требует классификации» в две новые статьи за
  август и сентябрь. Пересчёт согласован Владельцем.
- Гейт стал мягче в одну сторону: категория может лежать в очереди на разбор сколь угодно
  долго, и ночного `error` за это не будет. Видимость сохранена — `warning` в логах,
  строка в `ingest_external_categories` со статусом `new`, счётчики в выводе команды.
  Порог «висит в очереди дольше N дней → error» сознательно не вводился: это политика,
  а не дефект, и её выбор за Владельцем.
- Расхождения сумм и количеств в сверке не затронуты.

#### Checkpoint
- `docs/tasks/ozon-accrual-taxonomy-gates/checkpoint.md` обновлён
- exact next action: решение Владельца по merge и сроку деплоя

#### Open questions
- Ночной `daily-maintenance --days-back=45` пересчитает исторические строки сам, но окно
  это `сегодня−45 дней`. Строки от 1 августа выпадут из окна после ~15 сентября 2026.
  При деплое позже понадобится разовый прогон с `--from=2026-08-01 --to=<вчера> --execute`,
  а это Production Gate.

#### Expected owner response
Recommended response:
`Мержи и деплой до 15 сентября`

Alternative responses, when relevant:
- `Оставь в Draft, посмотрю сам`
- `Деплой будет позже — подготовь разовый прогон пересчёта`
