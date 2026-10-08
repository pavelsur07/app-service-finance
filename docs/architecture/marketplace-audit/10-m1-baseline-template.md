# Marketplace M1: baseline — шаблон фактических результатов и решение о M2

Заполняется по данным production после деплоя M1 ([09](09-m1-diagnostics.md)). Источник цифр —
`app:marketplace:perf-report --format=json` через `codex-console` и read-only SQL через
`codex-psql-ro` (`docs/maintenance/prod-access.md`). Пустая ячейка = не измерено; так и писать,
без оценок. Stage M1 не считается подтверждённым до заполнения разделов 1–2.

## 0. Паспорт наблюдения

| Поле | Значение |
|---|---|
| Деплой M1 (commit, дата/время МСК) | |
| Период наблюдения (from … to, дней) | цель 7–14 |
| Кабинеты (число Ozon / WB, без названий) | |
| Флаг в период | включён / выключен (интервалы) |
| Неполные дни (деплои, рестарты, инциденты) | |
| Отчёт обрезан (`truncated`) / `dropped_events` | |

## 1. Production sanity сразу после деплоя

| Проверка | Результат | Доказательство |
|---|---|---|
| Воркеры sync / pipeline / wb-finance / ads healthy, без рестартов | | `codex-docker-ps` |
| Синк Ozon by-day за день деплоя завершён | | статусы дней |
| Синк WB за день деплоя завершён | | статусы дней |
| Redis: длина/в обработке/отложенные/старейшее — без роста к дню до деплоя | | снимок отчёта |
| `failed`: глубина и возраст без роста | | `app:messenger:failed-queue-check` |
| Новых финансовых расхождений нет (reconciliation Ozon, гейт нераспознанных затрат WB, суммы дня sales/returns/costs vs raw) | | |
| `Performance diagnostics write failed` в логах | нет / есть | |
| Объём `performance-*.jsonl` за сутки | | `ls -l` |

## 2. Накладные расходы в production

Сопоставимость: одинаковые дни недели, близкое число сообщений и строк. Если нагрузка несопоставима —
записать это и не делать вывода.

| Метрика (за сутки) | Флаг выключен | Флаг включён | Δ | Сопоставимо? |
|---|---|---|---|---|
| Время шага WB sales, мс / 1000 строк | | | | |
| Время шага Ozon by-day sales, мс / 1000 строк | | | | |
| Пик памяти воркера pipeline (cgroup, `codex-cgroup`) | | | | |
| CPU воркеров (cgroup) | | | | |
| Число обработанных сообщений | | | | |

С выключенным флагом событий нет — длительность «выключено» берётся из существующих логов
обработчиков (`… processed`, `Costs processing completed`) или из окна до деплоя.

## 3. Этапы по провайдерам

Скопировать раздел «Этапы» отчёта. Для каждой строки отметить объём выборки (events).

| stage | provider | backend | events | p50 | p95 | p99 | max | rows/s | bytes/s | errors | max peak mem |
|---|---|---|---|---|---|---|---|---|---|---|---|
| api_fetch | ozon | | | | | | | | | | |
| api_fetch | wb | | | | | | | | | | |
| source_parse | wb | | | | | | | | | | |
| storage_read | * | postgres | | | | | | | | | |
| storage_write | * | postgres | | | | | | | | | |
| storage_read/write | * | s3 | | | | | | | | | |
| source_normalize | * | | | | | | | | | | |
| financial_mapping | * | | | | | | | | | | |
| financial_posting | * | | | | | | | | | | |
| processor_total | * | | | | | | | | | | |

**Доли времени шага** (по `handler` сообщений pipeline): normalize / mapping / posting / остаток
`processor_total` / storage_read — в процентах от суммы `handler`.

## 4. Очереди и обработчики

| transport | messages | lag p50 | lag p95 | lag p99 | lag max | lag n/a | redelivered |
|---|---|---|---|---|---|---|---|
| async_sync | | | | | | | |
| async_pipeline | | | | | | | |
| async_wb_finance | | | | | | | |
| async_ads | | | | | | | |

| job | messages | p50 | p95 | p99 | max | failed | retried | max growth mem |
|---|---|---|---|---|---|---|---|---|
| SyncOzonAccrualByDayMessage | | | | | | | | |
| SyncWbFinancialReportDayMessage | | | | | | | | |
| ProcessDayReportMessage | | | | | | | | |
| ProcessRawDocumentStepMessage | | | | | | | | |
| ProcessOzonRealizationMessage | | | | | | | | |
| CloseMonthStageMessage | | | | | | | | |

## 5. Полнота

| Показатель | Значение | Объяснение |
|---|---|---|
| Сообщений без события завершения | | OOM / деплой / граница периода |
| Событий без длительности (lag n/a) | | failed-retry |
| Отброшено лимитом | | |
| Этапы без rows | | ожидаемо: storage_write/postgres |

## 6. Выводы: где bottleneck

Для каждого вывода — цифра из разделов 3–4 и ссылка на строку отчёта. Гипотезы из
[03](03-performance-bottlenecks.md) отметить: подтверждена / опровергнута / не проверена.

| Гипотеза | Данные | Статус |
|---|---|---|
| Узкое место — ожидание в очереди (конкуренция транспортов) | lag p95 vs handler p95 | |
| Узкое место — внешний API (fetch, 429) | api_fetch доля, errors | |
| Узкое место — гидрация/запись большого JSON raw в PostgreSQL | storage_* postgres | |
| Узкое место — сопоставление (себестоимость, категории) | financial_mapping доля | |
| Узкое место — запись строк учёта (flush) | financial_posting доля | |
| Узкое место — память воркера (OOM, memory-limit) | max growth, неполные сообщения | |

## 7. Решение о M2

Критерии из [05](05-target-architecture.md) и [07](07-risks-and-open-questions.md): переход к выносу
загрузки/парсинга/нормализации в Go/Python оправдан, только если измеренный SLO miss объясняется
этапами, которые технически выносимы по [08](08-normalization-boundary.md), и не устраняется
bounded work/раздельной обработкой в Symfony.

| Вопрос | Ответ с цифрой |
|---|---|
| Есть ли SLO miss (какой SLO, на сколько) | |
| Какие этапы дают ≥ 50% времени шага | |
| Выносимы ли они ([08](08-normalization-boundary.md), колонка «Go/Python») | |
| Устранимы ли они в Symfony (батчи, предзагрузка, раздельные воркеры) | |
| Решение | M2 в Symfony / пилот выноса fetch+parse / пилот выноса normalization / нет действий |
| Владелец решения, дата | |
