# Задача: OOM/SIGKILL подпроцессов при обновлении метаданных категорий Ozon accrual

## Источник

Обнаружено 2026-09-05 при ручном запуске `app:ingestion:ozon-accrual:daily-maintenance
--days-back=45 --execute` (владельцем, вручную, после мержа задачи ozon-accrual-taxonomy-gates).
GlitchTip:
- issue 252 «Ozon accrual category metadata raw record failed.» — `firstSeen: 2026-07-02`,
  18 событий за всё время, 16 из них за один этот прогон.
- issue 262 «ProcessSignaledException: The process has been signaled with signal "9".» —
  `firstSeen: 2026-07-03`, 11 событий за всё время.

**Дефект существует с июля, не создан текущей сессией.** Ручной прогон лишь сконцентрировал
обычно размазанную по ночам нагрузку в один заход и сделал частоту заметной.

## Подтверждено в данных

- Все упавшие подпроцессы — для одной пары `company_id=b57d7682-505f-4b74-86f8-953d32d59874`,
  `shop_ref=148a5bb6-02fa-40b6-a854-33d84efaa223`.
- Пять конкретных `raw_record_id` из лога владельца содержат **5 664–7 753 строк каждый**
  (проверено `SELECT count(*) ... GROUP BY raw_record_id` по `ingest_financial_transactions`).
  Это исключительно крупные суточные пачки начислений для одного шопа.
- Сигнал именно `9` (`SIGKILL`), а не PHP `Fatal error: Allowed memory size exhausted` —
  это подпись kernel OOM-killer, а не превышения `memory_limit` изнутри Zend.
- `still_unknown=0` для всех пяти raw-record — по факту неклассифицированных строк среди них
  не осталось. Итоговая цель прогона (0 неклассифицированных транзакций, обе новые категории
  `mapped`) достигнута, несмотря на сбои — см. `docs/tasks/ozon-accrual-taxonomy-gates/`.
- Контейнеры не перезапускались в момент сбоев (`codex-docker-ps` показывает непрерывный
  аптайм) — упал именно дочерний PHP-подпроцесс, не контейнер целиком.

## Причина (два независимых фактора)

**1. `site-php-cli` — единственный PHP-сервис в `docker-compose.prod.yml` без лимита памяти.**
У `site-php-fpm` (800M), `site-messenger-worker-sync` (256M), `-pipeline` (768M),
`-wb-finance` (256M), `-ads` (256M) есть явный `deploy.resources.limits.memory`.
У `site-php-cli` — контейнера, в котором выполняются ad-hoc команды через `codex-console` и
внутри которого `OzonAccrualCategoryMetadataBulkRunner` порождает подпроцессы, — лимита нет
вовсе. OOM здесь не предсказуем cgroup-ом контейнера, а зависит от того, сколько свободной
памяти на хосте есть в момент запуска — то есть от состояния совершенно посторонних сервисов.

**2. `RefreshOzonAccrualCategoryMetadataAction::refresh()` держит в памяти всю raw-запись
целиком, а не потоково.**
`site/src/Ingestion/Application/Action/RefreshOzonAccrualCategoryMetadataAction.php:112-117`:

```php
$rows = array_values(iterator_to_array($this->rawStorageFacade->read($rawRecord->getId(), $companyId), false));
$mappedTransactions = $this->mapper->mapForCategoryMetadataRefresh(rawRecord: $rawRecord, rows: $rows, ...);
unset($rows);
```

`rawStorageFacade->read()` отдаёт `Generator` (уже сделан потоковым), но здесь его материализуют
в массив `$rows`, а затем `mapForCategoryMetadataRefresh()` строит из него ещё один полный массив
`$mappedTransactions` — оба массива одновременно в памяти на пике, для raw-записи в 5–8 тыс.
строк это кратно увеличивает пиковое потребление относительно построчной обработки.

Каждый подпроцесс запускается с `memory_limit`, унаследованным от родителя (в проде — `1024M` из
`site/docker/common/php/conf.d/memory-limit.ini`), но это мягкий лимит Zend, а не cgroup —
он не защищает хост при нехватке физической памяти, только сам процесс от собственных утечек.

## Что НЕ является причиной

- Не гонка/конкурентность: `refreshRawRecordInSubprocess()` вызывается синхронно
  (`$process->run()` блокирует), подпроцессы для разных raw-записей не запускаются параллельно
  внутри одного вызова `daily-maintenance`.
- Не потеря данных: транзакция БД на уровне `RefreshOzonAccrualCategoryMetadataAction` откатывается
  при исключении; SIGKILL прерывает процесс до `commit()`, Postgres сам откатывает незакоммиченное
  соединение. Проверено — неклассифицированных строк по затронутым raw-записям не осталось.
- Не регрессия P1–P4 этой сессии: возраст issues (июль) и код `OzonAccrualCategoryMetadataBulkRunner`/
  `RefreshOzonAccrualCategoryMetadataAction` не менялись ни в одной из задач P1–P4.

## Варианты решения (нужно решение Владельца перед кодом — правки трогают prod-инфраструктуру)

**A. Дать `site-php-cli` явный лимит памяти в `docker-compose.prod.yml`.**
Плюс: предсказуемость — OOM (если случится) будет ограничен cgroup-ом контейнера и не заденет
другие сервисы на хосте; видимость через `docker stats`/лимиты, единообразно с остальными
PHP-сервисами. Минус: сам по себе не устраняет падение конкретной тяжёлой raw-записи — просто
делает отказ predictable вместо hostwide.

**B. Сделать `refresh()` потоковым: обрабатывать `rawStorageFacade->read()` построчно,
не материализуя `$rows`/`$mappedTransactions` целиком.**
Плюс: устраняет причину по существу — пиковая память перестаёт расти с размером raw-записи.
Минус: требует аккуратной переработки `OzonAccrualByDayMapper::mapForCategoryMetadataRefresh()`
(сейчас принимает `list<array>` целиком, а не Generator) — более крупная правка, не point-fix.

**C. Комбинация A и B** — рекомендую: A закрывает риск немедленно и дёшево (одна строка в
compose), B устраняет корень на уровне кода. Можно сделать A первым Work item, B — вторым.

## Риск

`docker-compose.prod.yml` — production-инфраструктура, HIGH-LOCAL как минимум для варианта A;
вариант B — прикладной код без архитектурных изменений контрактов, обычный LOW/MEDIUM.
Изменение лимита памяти контейнера требует проверки на проде после деплоя (не тестируется
функциональными тестами) — это Production Gate по духу, не Release Gate.

## Что нужно решить, прежде чем брать в работу

1. Какой вариант — A, B или C?
2. Если A: какое значение лимита? У `site-messenger-worker-pipeline` (тоже тяжёлые импорты) —
   768M. Раз здесь порождаются подпроцессы с собственным `memory_limit=1024M`, лимит контейнера
   должен быть выше этого (иначе будет резать раньше, чем сработает Zend), например 1536M–2G —
   нужна оценка реального пикового потребления хоста под текущей нагрузкой, которой у меня сейчас
   нет (не было доступа к `docker stats`/`free` в момент инцидента).
