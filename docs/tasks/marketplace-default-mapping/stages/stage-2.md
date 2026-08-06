## Stage 2: автонастройка маппинга продаж и возвратов — DONE

**Риск:** 🟠 HIGH-LOCAL (новый публичный endpoint, запись правил, влияющих на суммы ОПиУ)
**Owner gate:** no
**Release candidate:** yes
**Independently deployable:** yes
**Следующее действие:** Final Release Gate

### Scope Stage
- Stage base commit: `746c1bfa`
- Work items completed: `2.1` конфиг, `2.2` провайдер и DTO, `2.3` preview/apply, `2.4` writer и query, `2.5` контроллер, `2.6` UI, `2.7` тесты

### Что сделано

- `config/marketplace/default_sale_mapping.yaml` — 12 правил (6 на маркетплейс) по эталонному кабинету, **со знаком, исправленным относительно эталона**: у `ozon / return_realization` и `ozon / return_cost_price` в проде стоит `is_negative=false`, что завышает выручку с СПП и себестоимость за первое полугодие 2026. В конфиге у всех правил возврата `is_negative: true`.
- Провайдер `DefaultSaleMappingYamlProvider` валидирует версию, неизвестный маркетплейс, неизвестный и дублирующийся `amount_source`, а также ограничение источника по маркетплейсу (`sale_realization`/`return_realization` — только Ozon).
- `PreviewDefaultSaleMappingAction` → статусы `WILL_CREATE` / `SKIPPED_EXISTING` / `MISSING_PL_CATEGORY` / `INVALID_TARGET_CATEGORY`. Существующее активное правило не трогается никогда. Отключённое правило с той же целью тоже даёт `SKIPPED_EXISTING` — иначе preview обещал бы создание, а apply молча упирался бы в уникальный ключ.
- `ApplyDefaultSaleMappingAction` читает preview **внутри той же транзакции**, что и пишет.
- `DefaultSaleMappingWriter` — одна атомарная инструкция: `INSERT … SELECT … FROM pl_categories WHERE pl.company_id = :company_id AND pl.code = :pl_code AND pl.type = 'LEAF_INPUT' AND NOT EXISTS (активное правило на этот amount_source)`. Категория перепроверяется внутри вставки, потому что между preview и записью она может исчезнуть, сменить код или тип. `ON CONFLICT DO NOTHING` без указания ключа — чтобы срабатывали оба уникальных индекса.
- Инвариант «одно активное правило на источник суммы» **закреплён в схеме**: миграция `Version20260805090000` добавляет частичный индекс `uniq_active_sale_mapping_source`. Старый `uniq_sale_mapping` его не покрывал, так как включает `pl_category_id`.
- Preview показывает **реальный** знак существующего правила и отдельно помечает расхождение с эталоном: настроенный возврат с «плюсом» — это и есть прод-дефект, автонастройка его не правит, но обязана показать.
- Кнопка «Настроить автоматически» на `/marketplace/pl-mappings` рядом с бейджем «Не настроено: N», модалка с таблицей preview.

### Затронутые файлы
- `site/config/marketplace/default_sale_mapping.yaml` — new
- `site/src/Marketplace/Application/DTO/DefaultSaleMapping{Rule,RuleSet,PreviewItem,PreviewResult,ApplyResult}.php` — new
- `site/src/Marketplace/Application/Command/{Preview,Apply}DefaultSaleMappingCommand.php` — new
- `site/src/Marketplace/Application/Action/{Preview,Apply}DefaultSaleMappingAction.php` — new
- `site/src/Marketplace/Application/Exception/DefaultSaleMappingConfigException.php` — new
- `site/src/Marketplace/Enum/DefaultSaleMappingPreviewStatus.php` — new
- `site/src/Marketplace/Infrastructure/Provider/DefaultSaleMappingYamlProvider.php` — new
- `site/src/Marketplace/Infrastructure/Query/SaleMappingsByAmountSourceQuery.php` — new
- `site/src/Marketplace/Infrastructure/Writer/DefaultSaleMappingWriter.php` — new
- `site/src/Marketplace/Controller/SaleMappingDefaultSetupController.php` — new
- `site/templates/marketplace/pl_mappings/_default_mapping_modal.html.twig` — new
- `site/templates/marketplace/pl_mappings.html.twig` — modified
- `site/config/services.yaml` — modified (путь к конфигу)
- `site/tests/Unit/Marketplace/Infrastructure/Provider/DefaultSaleMappingYamlProviderTest.php` — new
- `site/tests/Functional/Marketplace/Controller/SaleMappingDefaultSetupControllerTest.php` — new
- `site/tests/Fixtures/Marketplace/SaleProvider/*.yaml` — new
- `ARCHITECTURE.md` — modified

- `site/migrations/Version20260805090000.php` — new (частичный уникальный индекс, `down()` — `DROP INDEX IF EXISTS`, строки не изменяются)

### Доказательство тестами (не «тест написан», а «красный на дефекте»)

| Гвард | Как доказан |
|---|---|
| Знак возвратов | `DefaultMappingConfigTest::testEveryReturnRuleInvertsSign` прогнан на конфиге с воспроизведённой прод-настройкой `return_realization: is_negative=false` → красный с точным сообщением. После возврата значения — зелёный |
| Второе активное правило на источник | `testWriterRefusesSecondActiveRuleForSameAmountSource` прогнан со снятым `NOT EXISTS` → «Failed asserting that 1 is identical to 0» |
| Чужая категория ОПиУ | `testWriterIgnoresPlCategoryOfAnotherCompany` прогнан со снятым фильтром по компании → красный так же |
| Частичный индекс | `testDatabaseRejectsSecondActiveRuleWithAnotherCategory` прогнан со снятым индексом на тестовой БД → «Failed asserting that exception … is thrown» |

### Self-review
- [x] Scope compliance
- [x] Structure / naming / модификаторы классов
- [x] Forbidden actions — none (нет `dump()`, `new Service()`, `flush()` в репозитории, хардкода секретов)
- [x] Security: каждый запрос принимает `string $companyId`; контроллер берёт компанию через `getActiveCompany()`; CSRF на обоих endpoint'ах; вставка проверяет принадлежность категории ОПиУ компании на уровне SQL; в лог идут только id и счётчики
- [x] Нет N+1: два запроса на preview независимо от числа правил
- [x] Пагинация — N/A (12 правил максимум, не списочный endpoint)
- [x] CS-Fixer по новым файлам — чисто (baseline репозитория красный: 308 из 513 файлов)
- [x] ARCHITECTURE.md updated

### External review
- Reviewer: Codex CLI 0.146.0 (`codex exec -s read-only --ephemeral`, дифф и контекст переданы через stdin)
- Iterations: 5
- Result: `REVIEW_GREEN` на пятой итерации
- Confirmed findings fixed:
  - IMPORTANT — окно между preview и apply позволяло создать второе активное правило на тот же источник → проверка перенесена внутрь INSERT (`NOT EXISTS`), preview читается внутри транзакции, добавлены тесты, доказанные красными.
  - IMPORTANT — `NOT EXISTS` не спасает от настоящей гонки двух транзакций → инвариант перенесён в схему частичным индексом `uniq_active_sale_mapping_source`. Перед миграцией проверены боевые данные: нарушений 0.
  - IMPORTANT — категория ОПиУ могла сменить тип или код между preview и вставкой → writer перепроверяет `company_id`, `code` и `type` внутри INSERT.
  - IMPORTANT — в дифф задачи попали два посторонних PNG старого UI-kit-аудита (подхвачены `git add -N site` из ранее существовавших untracked-файлов) → убраны из индекса.
  - IMPORTANT — preview рисовал ожидаемый знак поверх реального у настроенного правила, пряча прод-дефект → показывается фактический знак плюс явная пометка расхождения; добавлен тест `testExistingRuleWithWrongSignIsShownAsIsAndFlagged`.
  - IMPORTANT — в документацию задачи попадали имя реального ИП, точные суммы расхождения и настройки прод-периода → тексты обезличены, цифры переданы Владельцу вне репозитория.
  - MINOR — `ON CONFLICT` указывал только старый пятиколоночный ключ → заменён на `ON CONFLICT DO NOTHING`.
  - MINOR — кнопка apply оживала на секунду до перезагрузки страницы → добавлен флаг `applied`.
  - MINOR — гвард конфига принимал любой код `wb_*` → список динамических слагов закрыт явным перечислением.
  - MINOR — расхождения комментариев с фактическим поведением (блокировка apply, порядок SQL в комментарии миграции, имя тест-класса) → поправлены.
  - MINOR — для статуса «Уже настроено» UI печатал «pl_code — не найдена», хотя правило исправно → модалка показывает реально настроенную категорию.
  - MINOR — не покрыты ветка отключённого правила и изоляция компаний → добавлены `testDisabledRuleWithSameTargetIsReportedAndNotResurrected` и `testApplyTouchesOnlyActiveCompany`.
- Rejected findings with reason:
  - Регрессия контракта экрана затрат — находка фактически неверна: `CostPLMappingDefaultSetupController` никогда не возвращал `total`/`groupedItems`, поэтому старый JS всегда рисовал пустой стейт. Правка чинит неработавший preview.
  - `FOR SHARE OF pl` и откат всего apply при исчезновении категории во время записи — отклонено: строка отчитывается как `skipped`, повреждения данных нет, а откат из-за чужого удаления сделал бы поведение менее предсказуемым.
  - Конкурентный тест двух соединений — в текущем стенде нечем воспроизвести гонку; инвариант закрыт индексом БД.
- Ограничения ревьюера: без доступа к шеллу и к схеме БД. Факты, которые он не мог добыть сам (состав `uniq_sale_mapping`, длина колонки `code`, размер каталога Ozon, красный baseline cs-fixer, результат прогона тестов), переданы в промпте.

### Команды для проверки
- `make site-test-unit`
- `docker compose run --rm -T site-php-cli php bin/phpunit -c phpunit.xml --testsuite functional`
- `make site-cs-check` (точечно по изменённым файлам — baseline красный)

### Риски / на что обратить внимание ревьюеру
- Правила создаются сразу активными и немедленно влияют на следующее закрытие месяца.
- Блокировка apply при отсутствии категории ОПиУ действует на весь набор, а не на отдельную строку — поведение унаследовано от механизма затрат ради единообразия.
- Ветка кодирует правильный знак только для **новых** правил. Ошибочный знак в эталонном кабинете автонастройка не исправляет — это отдельное решение Владельца (см. handoff).

### Открытые вопросы
- нет
