### Stage 0: документы и сверка API Ozon — DONE

**Risk:** LOW
**Stage base commit:** `62c34c1f5`
**Branch:** `docs/ozon-fetcher-ingestion-stage0`
**Внешнее ревью:** нет (план, §«Как читать этапы»)

#### Цель
Правила и контракты `services/` описывают живой API Ozon (by-day вместо
снятого `transaction-list`); лимиты и форма запросов ресурсов первой волны
известны до кода.

#### Результат
- обновлены `services/ozon-fetcher/AGENTS.md`, `services/ozon-fetcher/README.md`,
  README/CHANGELOG контрактов `source-fact-envelope` v1 и
  `marketplace-connections` v1 (статус «проект» — правка без v2)
- `services/ozon-fetcher/docs/ozon-api.md` — сверка 4 ресурсов первой волны и
  таблица дефолтных лимитов с источником каждого значения
- `plan.md` — решения Владельца 2026-10-17

#### Work items
- **0.1** — сверка API Ozon: для `accrual/by-day`, `accrual/types`,
  `v2/finance/realization`, `v1/finance/mutual-settlement` — тело запроса,
  форма ответа, пагинация, ошибки, опубликованные лимиты. Источники: текущий
  PHP-код Symfony (рабочий на проде) и документация Ozon Seller API.
- **0.2** — фактические 429 по методам Ozon: что видно в коде (ретраи,
  лимитеры) и в доступных read-only источниках (GlitchTip, логи) за 30 дней.
- **0.3** — таблица дефолтных лимитов: значение, источник, пометка
  «консервативно», где Ozon не публикует.
- **0.4** — правки документов: `transaction-list` → by-day и ресурсы первой
  волны; `source_key` для by-day — по рабочему PHP-нормализатору;
  `resource_types`; Roadmap README → ссылка на план; таблица env дополнена
  `MAX_PARALLEL_CONNECTIONS`; миграции — номер `00002_ingestion`.
- **0.5** — самопроверка + внутреннее ревью diff, коммит.

#### Definition of Done / чек-лист готовности
- [x] `grep -ri transaction-list services/` — только исторические упоминания (CHANGELOG)
- [x] для каждого из 4 ресурсов записаны: запрос, пагинация, форма ответа, ошибки, лимит + источник
- [x] таблица дефолтных лимитов в `docs/ozon-api.md`; непубликуемые значения помечены
- [x] частота 429 по методам записана с цифрой или с явной причиной, почему цифры нет
- [x] `source_key` by-day в README контракта совпадает с PHP-нормализатором (ссылка на класс)
- [x] `resource_types` в README `marketplace-connections` — новые значения
- [x] README сервиса: Roadmap → план, env, статус
- [x] CHANGELOG контрактов — запись о правке
- [x] внутреннее ревью: BLOCKER/IMPORTANT нет
- [x] коммит по pathspec, только файлы этапа

#### What was done
- 0.1 — сверены 4 ресурса по рабочему PHP-коду и истории git. **Находка:**
  `mutual-settlement` — асинхронный отчёт (заказ → `/v1/report/info` → файл
  XLSX); клиент Symfony на проде не работал (добавлен #1544, удалён
  27.09.2026 в `117887d77`), форма подтверждается на этапе 4.
- 0.2 — прод `perf-report` 17.09–17.10 (лог покрывает 10 из 31 дня):
  `SyncOzonAccrualByDayMessage` 339 сообщений, 167 повторов (49 %), 0
  провалов; 1 воркер `async_sync` на все кабинеты. Деление на 429/5xx в
  read-only инструментах недоступно — даст телеметрия сервиса (этап 12).
- 0.3 — Ozon лимит финансовых методов не публикует; дефолты консервативные
  (by-day 1 запрос/с на кабинет, пол 0.1, потолок 5) —
  `services/ozon-fetcher/docs/ozon-api.md`.
- 0.4 — `transaction-list` убран из правил и контрактов; `source_key` by-day
  — `ozon:accrual-by-day:{accrual_id}:{component}` (без состояния, паритет с
  Ingestion): прежний проект ссылался на ключи Marketplace, зависящие от БД
  Symfony; env-ключи Ozon удалены из README и `.env.example` (ключи — из
  Symfony); `services/AGENTS.md` — номер миграции `00002_ingestion`, триггер
  первичной загрузки до UI — поллингом.
- `plan.md` — классы `not_ready`, `invalid_request`; mutual-settlement в этапе 4.

#### Files changed
- `services/AGENTS.md`, `services/ozon-fetcher/{AGENTS.md,README.md,.env.example}`
- `services/ozon-fetcher/docs/ozon-api.md` (новый)
- `services/_contracts/{marketplace-connections,source-fact-envelope}/v1/{README.md,CHANGELOG.md,schema.json}`
- `docs/tasks/ozon-fetcher-ingestion/{plan.md,checkpoint.md,stages/stage-0.md}`

#### Checks
- `grep -rni transaction-list services/` — только CHANGELOG и историческая строка в правилах
- `schema.json` обоих контрактов — валидный JSON; структура схем не менялась
- ссылки на файлы проверены на существование

#### Internal review
- iterations: 1; BLOCKER/IMPORTANT: none
- MINOR fixed: «watermark'и из `checkpoint`» в §3 → журнал `fetch_task`
- FOLLOW-UP: ключи `realization`/`mutual-settlement`/`accrual-types` —
  в задаче выдачи фактов

#### Next
- Stage 1 (авторизация `/internal/*` в Symfony) автоматически
