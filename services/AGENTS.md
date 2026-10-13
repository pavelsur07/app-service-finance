# services/AGENTS.md — общие правила Go/Python-сервисов

Распространяется на всё дерево `services/`. Иерархия (корневой AGENTS.md §2):
корневой `AGENTS.md` → этот файл → `services/<name>/AGENTS.md`. Ближайший к
файлам выигрывает.

| Document | Contents | When to read |
|---|---|---|
| `services/go.md` | Go: раскладка, паттерны, зависимости, гейты, Docker, монорепо | код в `services/*/*.go` |
| `services/<name>/AGENTS.md` | Специфика сервиса: провайдер, ключи, запуск | работа внутри сервиса |

## 1. Неизменяемые границы (marketplace-аудит 05/08)

Эти правила наследуются из `docs/architecture/marketplace-audit/` и не
переопределяются per-service:

1. **Только Symfony пишет** `marketplace_*`, Finance, Financial Core,
   `documents`, `pl_daily_totals`, `cash_transaction`, `fincore_*`, Balance.
   Сервисы публикуют source facts; Symfony принимает идемпотентно.
2. Сервисы **не назначают** признание, проводки, категорию ОПиУ, период; не
   читают и не создают справочники компании; не знают о закрытых периодах.
3. **Деньги — точные десятичные строки + `currency`** (ISO 4217). Никаких
   float/double в расчётах и контрактах.
4. Неизвестная `schema_version` / категория / валюта → **карантин, не skip**
   (решение принимает принимающая сторона в Symfony).
5. Коррекция источника — **новая `source_revision`** с причинной ссылкой,
   бесконтрольный overwrite запрещён.

## 2. Контракты

- Единственный источник правды — `services/_contracts/`. Версии — каталоги
  `v<N>/` со schema и CHANGELOG.
- **Опубликованная версия неизменна.** Изменение полей или семантики = новая
  версия; миграция producer и consumer — независимые задачи.
- Типы на обеих сторонах генерируются/проверяются по schema; ручные
  расхождения с контрактом запрещены.
- Consumer обязан принимать все версии, которые заявлены как поддерживаемые;
  producer шлёт ровно одну версию.

## 3. Транспорт: HTTP push + durable inbox (вариант A, утверждён 2026-10-13)

- Producer → `POST /internal/source-facts`, заголовок `Idempotency-Key:
  <batch_id>` (см. `source-fact-envelope` v1).
- Symfony отвечает ACK **только после долговечной записи inbox**; повтор того
  же `batch_id` возвращает прежний результат (idempotent replay).
- Backpressure: на `429`/`503` producer повторяет с экспоненциальным backoff +
  jitter; курсор сохраняется локально у producer.
- Endpoint — только внутри docker-сети / за IP-allowlist. Наружу не
  публикуется (прецедент allowlist: `tg-gateway/docker-compose.yml`).
- Замена транспорта (вариант B: Redis Streams) допустима **без смены
  контракта envelope** — отдельным решением.

## 4. Структура сервиса

```text
services/<name>/
├── AGENTS.md            # правила сервиса: стек, границы, запуск, тесты
├── README.md            # назначение, статус, как запускать
├── cmd/<name>/          # Go: точка входа (только wiring)
├── internal/            # Go: пакеты, наружу не экспортируются
├── <package>/           # Python: пакет (или src/<name>/)
├── go.mod | pyproject.toml   # пин версии языка, зависимости с lock
└── docker/Dockerfile    # multi-stage, non-root, pinned base images
```

## 5. Стек и зависимости

- Языковая специфика — в документах по таблице выше: для Go — `services/go.md`
  (stdlib-first как культура, проверенный стек, гейты). PHP-гейт §3.3 на новые
  зависимости сервисов `services/` не распространяется; осмысленность
  зависимости проверяется ревью.
- Python: `ruff`/`mypy` — при появлении первого Python-сервиса правила
  фиксируются в `services/python.md` по аналогии с `go.md`.
- **Секреты только через env.** В репозитории — имена переменных, не значения.

## 6. Наблюдаемость

- Structured logs: JSON или `slog`. Обязательные поля: `service`, `job_id`,
  `trace_id`, `stage`, `duration_ms`, `outcome`, `retry_count`, безопасный
  идентификатор кабинета (`connection_ref`, не ПДн).
- **Запрещено в логах:** токены, raw payload, ПДн, финансовые значения
  (правила M1 §2, `docs/tasks/marketplace-m1-perf-diagnostics/TASK.md`).
- Сбой телеметрии не прерывает бизнес-операцию.

## 7. Тесты и паритет

- Парсинг, ключи, сборка envelope — table-driven тесты на обезличенных
  фикстурах (без реальных payload: ПДн, суммы, токены).
- Перед cutover — **dual-run** параллельно с Symfony-путём: паритет сумм до
  копейки на архивном raw. Расхождение кода категории или копейки = отказ
  cutover (аудит §10).
- Cutover — по кабинету, за feature flag с возвратом на прежний путь.

## 8. Запуск и деплой

- Dev: compose-профиль `services` (`make <name>-build`, `make <name>-up`).
- Гейты: `make <name>-lint` / `-vet` / `-test` (конфиги и порядок —
  `services/go.md` «Гейты»).
- Prod (`docker-compose.prod.yml`), CI/CD, новые ingress — только через
  §3.3 корневого AGENTS.md (отдельное одобрение).
- Каждый сервис обязан иметь `GET /health` и graceful shutdown.

## 9. Состояние и хранение (решения владельца, 2026-10-13)

### БД сервиса

- Отдельная **логическая БД на общем инстансе** PostgreSQL (второй инстанс не
  нужен); **одна роль на сервис** (`svc_<name>`), права только на свою БД.
- В БД сервиса — **только техническое состояние**: курсоры, чекпоинты, outbox,
  статусы job'ов. Без денег, сумм, ПДн.
- Миграции — в репозитории сервиса, **forward-only**, прогон явный
  (`make <name>-migrate` / команда бинарника), не авто-при старте в prod.
- Публикации фактов — через **outbox**: батч и checkpoint пишутся в одной
  локальной транзакции; publisher шлёт из outbox; после `acked` payload
  обнуляется (retention), остаются hash и статус.
- Запрещено: доступ к БД/таблицам Symfony, cross-БД join/FK, секреты в git.
- Dev: контейнер `services-postgres` (профиль `services`), init-скрипты в
  `services/docker/postgres/init/`.

### S3

- Отдельный **бакет на монорепо** (владелец — `services/`):
  `vashfindir-services-raw` (Timeweb). Доступ — через доп. пользователя
  хранилища с правами только на этот бакет; главная пара ключей аккаунта
  сервисам не выдаётся.
- Raw-объекты **immutable**, коррекция источника — новым ключом/ревизией;
  `storage_uri`, sha256 и размер — по контракту `source-fact-envelope` v1.
- Endpoint/region — в README сервиса; секреты — только env.
- Текущее хранение Marketplace raw в PostgreSQL **не меняется до cutover**
  (аудит 08); retention-политика raw — отдельное решение владельца.
