### Stage 1: устранить причину SIGKILL/OOM при обновлении метаданных категорий Ozon accrual — DONE

**Risk:** HIGH-LOCAL (production-инфраструктура — `docker-compose.prod.yml`)
**Owner gate:** yes — изменение лимита памяти контейнера требует проверки на проде после деплоя
**Release candidate:** yes
**Independently deployable:** yes
**Next action:** STOP, owner action required — только для merge и последующей проверки на проде

#### Stage scope
- Stage base commit: `3880ea598e4bf9d5edc189599edc1adcf8a18ea2`
- Work items completed: `1.1` (лимит памяти `site-php-cli`), `1.2` (устранение двойной
  материализации в `RefreshOzonAccrualCategoryMetadataAction`)

#### What was done

**Дефект существовал с июля, не регрессия текущей сессии.** GlitchTip issues 252
(«raw record failed», `firstSeen: 2026-07-02`) и 262 (`ProcessSignaledException`, signal 9,
`firstSeen: 2026-07-03`). Проявился заметно при ручном запуске
`app:ingestion:ozon-accrual:daily-maintenance --days-back=45 --execute` для шопа с крупными
суточными пачками начислений — подтверждено прямым запросом к проду: пять конкретных
`raw_record_id` из лога владельца содержат 5 664–7 753 строк каждый.

Сигнал именно `9` (SIGKILL) — подпись kernel OOM-killer, не PHP `Fatal error: Allowed memory
size exhausted` (та ошибка не сигнальная). Причина — два независимых фактора:

1. `site-php-cli` был единственным PHP-сервисом в `docker-compose.prod.yml` без
   `deploy.resources.limits.memory` (у остальных пяти PHP-сервисов лимит есть). Внутри него
   `OzonAccrualCategoryMetadataBulkRunner` порождает подпроцессы для ad-hoc консольных команд —
   OOM здесь зависел от состояния всего хоста, а не своего cgroup.
2. `RefreshOzonAccrualCategoryMetadataAction::refresh()` материализовывал уже потоковый
   Generator (`RawStorageFacade::read()` → `RawNdjsonCodec::decodeCompressedRows()`, yield по
   строке) в полный `array` через `iterator_to_array()`, хотя маппер (`mapForCategoryMetadataRefresh()`)
   и без того принимает `iterable` и делает единственный проход. Многопроходность не требовалась —
   материализация была чистым излишком, державшим одновременно и полный набор decoded строк, и
   полный набор построенных `MappedTransaction`.

Сделано:
1. `site-php-cli` получил `deploy.resources.limits.memory: 1536M` — подпроцесс сам капается в
   1024M (`site/docker/common/php/conf.d/memory-limit.ini`), плюс родительский PHP-процесс и
   запас. OOM (если случится) теперь ограничен cgroup-ом контейнера, а не хостом целиком.
2. `refresh()` передаёт Generator из `rawStorageFacade->read()` напрямую в
   `mapForCategoryMetadataRefresh()` без промежуточного `array_values(iterator_to_array(...))`.

#### Files changed
- `docker-compose.prod.yml` — modified (лимит памяти `site-php-cli`)
- `site/src/Ingestion/Application/Action/RefreshOzonAccrualCategoryMetadataAction.php` — modified
- `site/tests/Integration/Ingestion/Application/RefreshOzonAccrualCategoryMetadataActionTest.php` — modified
- `site/phpstan-baseline.neon` — modified (одна запись удалена — код, вызывавший
  предупреждение, удалён целиком)

#### Definition of Done
- [x] `site-php-cli` имеет явный лимит памяти, согласованный по стилю с остальными PHP-сервисами
- [x] `refresh()` не материализует raw-запись дважды в памяти
- [x] обработка raw-записи в 6000 строк (масштаб реального инцидента) доказана корректной тестом
- [x] измерение показывает снижение пикового потребления памяти на синтетической фикстуре
- [x] PHPStan baseline сокращён, а не расширен
- Исключено из Stage: полностью потоковый вывод (`mapForCategoryMetadataRefresh()`/`mapRows()`
  по-прежнему возвращают `array`, а не `Generator`) — более крупный рефакторинг, затрагивающий
  все вызывающие места маппера; отмечен как FOLLOW-UP в `task.md`, не входил в согласованный
  scope (варианты A+C, не полный B)

#### Baseline
- `php bin/phpunit --filter RefreshOzonAccrualCategoryMetadataActionTest` — OK (4 теста, 23 assertions)
- красного baseline в репозитории нет: cs, strict-types и stan были зелёными до задачи

#### Checks
- targeted: `php bin/phpunit --filter RefreshOzonAccrualCategoryMetadataActionTest` — OK (5 тестов, 27 assertions)
- module: `php bin/phpunit --filter 'Ozon|Refresh'` — OK (836 тестов, 6617 assertions)
- full relevant stage: `make site-test-unit` — OK (2301, 4 pre-existing deprecations);
  `make site-test-integration` — OK (1230); `composer test:functional` — OK (599, 2 pre-existing deprecations)
- `docker compose -f docker-compose.prod.yml config` — валиден; `deploy.resources` резолвится
  в `1610612736` байт (1536 MiB) и `134217728` байт (128 MiB) — проверено напрямую
- `make site-cs-check` / `site-cs-strict-types` — Found 0 of 2471
- `make site-stan` — No errors; baseline сократился на одну запись (не вырос)

**Измерение памяти (не в CI, зафиксировано здесь).** Тест с 6000 разнородных строк
(уникальные `accrual_id`/`type_id`/`amount` на каждой, без copy-on-write дублирования):
пиковая память (`memory_get_peak_usage(true)`) — **262.5MB на старом коде, 254.5MB на новом**
(~3%). Синтетическая строка — минимальная NON_ITEM-запись; в реальных production
POSTING-записях с вложенными `products`/`services` на строку разница ожидается больше, но
отдельно не измерялась — это честно помечено как неподтверждённая экстраполяция, а не факт.
Порог памяти НЕ закреплён как assertion в тесте: абсолютные цифры зависят от окружения
(PHP-версия, coverage-драйвер), закреплять их в CI значило бы вносить новый источник
нестабильности, прецедента такому в кодовой базе нет (`memory_get_peak_usage` нигде в тестах
не используется).

**Мутация фикстуры поймана в процессе.** Первая версия синтетических 6000 строк использовала
`amount = -i.{i%100}`, из-за чего строка `i=0` получала сумму `-0.00` — она законно
пропускается `OzonAccrualByDayPreviewMapper` как нулевое начисление (не баг прод-кода,
баг тестовой фикстуры). Тест упал на `5999 !== 6000`, фикстура исправлена на `-{i+1}.{i%100}`.

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: PHPStan поймал устаревшую запись в `phpstan-baseline.neon`, ссылавшуюся на
  удалённый `array_values()` — удалена (baseline сократился, не расширился)
- FOLLOW-UP: полностью потоковый `mapForCategoryMetadataRefresh()`/`mapRows()` (возврат
  `Generator` вместо `array`) устранил бы вторую половину пикового потребления памяти, но
  требует правки сигнатуры и проверки всех вызывающих мест (`controlSum`,
  `controlSumForRawRecord` и др.) — отдельная, более крупная задача

#### External Claude Code review
- Реализация — Claude Code, внешний ревьюер — Codex (`codex exec -s read-only --ephemeral`), по таблице ролей `CLAUDE.md`
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: none — находок BLOCKER/IMPORTANT/MINOR нет
- Rejected findings with reason: none
- Ограничение ревьюера: шелла у него не было, тесты и измерения не запускал, результаты принял
  из промпта. Факты о проде (issues GlitchTip, размер упавших raw-записей, отсутствие лимита
  памяти у site-php-cli) переданы в промпт

#### Review fixes applied
- нет: внешнее ревью вернулось зелёным с первой итерации

#### Risks / reviewer focus
- **Значение 1536M не измерено на реальном инциденте** — на момент разбора не было доступа к
  `docker stats`/`free` на проде в момент падения. Если оно окажется мало и контейнер начнёт
  падать по cgroup OOM на легитимной тяжёлой нагрузке — это будет видно сразу (SIGKILL самого
  консольного процесса, а не спорадическая ошибка), и значение можно поднять одной строкой без
  повторного код-ревью.
- Оптимизация памяти (work item 2) устраняет ПОЛОВИНУ причины на измеренных ~3% для минимальной
  синтетической строки; реальный эффект на production POSTING-записях не измерялся отдельно.
  Комбинация с лимитом контейнера (work item 1) — основная защита от повторения инцидента.
- Изменение не трогает финансовые формулы, суммы, знаки, периоды, категорийный маппинг — чисто
  оптимизация памяти и изоляция ресурсов для той же логики.

#### Checkpoint
- `docs/tasks/ozon-accrual-refresh-oom/checkpoint.md` обновлён
- exact next action: решение Владельца по merge; после деплоя — проверка `docker stats site-php-cli`
  при следующем тяжёлом ad-hoc прогоне

#### Open questions
- Точное пиковое потребление памяти на проде под реальной нагрузкой (POSTING-записи, не
  синтетическая NON_ITEM-фикстура) — не измерено, следующий инцидент (если случится) даст число.

#### Expected owner response
Recommended response:
`Мержи; проверю docker stats на следующем тяжёлом прогоне`

Alternative responses, when relevant:
- `Оставь в Draft, посмотрю сам`
- `Подними лимит до <N>M` (если есть данные о фактическом свободном объёме хоста)
