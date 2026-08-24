### Stage 4: мелкие модули — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** STOP → Stage 5 объявлен `owner_gate: yes`

#### Scope Stage
- Stage base commit: `574b07d4` (коммит Stage 3)
- Work items: 4.1 `Shared` (9, отдельно и с собственным полным прогоном),
  4.2 остальные шесть модулей — `Telegram` 9, `Billing` 5, `Balance` 4,
  `Admin` 4, `Report` 3, `Twig` 3

#### Что сделано
`declare(strict_types=1)` добавлен в 37 файлов семи модулей. Долг: 128 → 91.
Во всех семи модулях не осталось ни одного файла без объявления.

Диф: 37 файлов, ровно +74 строки, по две на файл. Посторонних строк — ноль.

#### Почему `Shared` шёл отдельным Work item
В нём лежит `ActiveCompanyService`, от которого зависит **161 файл** в `src` —
самый широкий охват в репозитории. Остальные файлы `Shared` локальны
(`CompanyContextService` 4 файла, `AuditLogRepository` 4, `FeatureFlagService` 3,
`SlugifyService` 2). Поэтому `Shared` получил собственный полный прогон до того,
как к диффу добавились остальные 28 файлов: иначе регресс от него был бы
неотличим от регресса шести других модулей.

#### Definition of Done
- [x] 37 файлов получили `declare(strict_types=1)`
- [x] полный набор тестов не хуже baseline
- [x] посторонних изменений нет
- [x] внешний review `REVIEW_GREEN`

#### Baseline
- полный `make site-test` **до** (дерево = Stage 3): `Tests: 3461, Assertions: 20360, Deprecations: 7`, 0 падений

#### Checks
- после `Shared` — юнит `Tests: 1927`, exit 0, затем **полный** `make site-test`:
  `Tests: 3461, Assertions: 20360, Deprecations: 7`, 0 падений, 5:33, пик 341 МБ — идентично baseline
- после остальных 28 файлов — юнит `Tests: 1927, Assertions: 10969`, exit 0
- Stage check — полный `make site-test` **после**: `Tests: 3461, Assertions: 20360, Deprecations: 7`, 0 падений, exit 0, 6:01, пик 339 МБ — **идентично baseline до последней цифры**

#### Внутренний review
- Итераций: 1. BLOCKER: нет. IMPORTANT: нет.
- Диф сведён к одному классу изменений: 37 вставок `declare`.
- Отдельно прочитан `CurrencyFormatExtension` — форматирование денег, самое
  вероятное место слома. Чист: `formatNumber()` явно ветвится на `is_string()`,
  нормализует разделители и приводит `(float)` до `number_format()`.
  Важный нюанс семантики: параметр `formatMinorCurrency(int $amountMinor)`
  строгим для внешних вызывающих **не становится** — режим задаёт файл-вызыватель,
  а это скомпилированный шаблон Twig, не строгий.

#### Внешний review
- Ревьюер: Codex CLI 0.148.0, `codex exec -s read-only --ephemeral`
- Итераций: 1 — `REVIEW_GREEN`
- В промпт сразу вложены полный дифф всех 37 файлов и тела пяти файлов
  (`ActiveCompanyService`, `CurrencyFormatExtension`, `CashflowReportBuilder`,
  `CashflowReportRequestMapper`, `TelegramIntegrationController`), плюс явный
  запрет запускать команды — на Stage 3 попытка ревьюера воспользоваться шеллом
  уронила его песочницу и стоила круга.
- Подтверждено: во всех 37 файлах ровно две добавленные строки, позиция
  единообразна, посторонних правок и смены режимов файлов нет; в приведённых
  телах DBAL-суммы приводятся к `float`, идентификаторы к `string`, денежная
  строка к `float` до `number_format()`.
- Ревьюер отдельно оговорил, что финальный прогон на момент вердикта не был
  завершён и его статус нужно проверить как часть Stage gate. Замечание принято:
  Stage закрыт только после фактической цифры, приведённой выше.

#### Исправления по review
- не потребовались

#### Риски / на что смотреть
- Из 37 файлов ревьюер видел тела пяти; остальные 32 — только как дифф. Он сам
  назвал это ограничением покрытия, а не находкой.

#### Checkpoint
- следующее действие: **Stage 5, `owner_gate: yes`** — `Deals` (31), `Analytics`
  (24), `Notification` (8). Там тестов почти нет: `Deals` 3 теста на 42 файла,
  `Analytics` 10 на 26, `Notification` 0 на 8. По плану сначала пишутся
  регрессионные тесты на денежные и парсящие пути, и только потом `declare`.

#### Открытые вопросы
- нет

#### Ожидаемый ответ Владельца
Stage 5 объявлен `owner_gate: yes`. Рекомендуемый ответ:
`продолжай Stage 5`
