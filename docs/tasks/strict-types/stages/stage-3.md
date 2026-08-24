### Stage 3: Cash — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously → Stage 4 (мелкие модули, 37 файлов)

#### Scope Stage
- Stage base commit: `62fc78f6` (коммит Stage 2)
- Work items: 3.1 Enum+Exception (10), 3.2 Message+Handler+Subscriber (9),
  3.3 Entity (15), 3.4 Form+Controller (18), 3.5 Repository (14),
  3.6 Service+Application+Command (32)

#### Что сделано
`declare(strict_types=1)` добавлен во **все 98 файлов** модуля Cash.
Долг: 226 → 128. В `src/Cash` не осталось ни одного файла без объявления.

Диф: 98 файлов, ровно +196 строк, по две на файл. Посторонних строк — ноль,
проверено фильтром по всему диффу после каждого Work item.

#### Definition of Done
- [x] 98 файлов получили `declare(strict_types=1)`
- [x] полный набор тестов не хуже baseline
- [x] посторонних изменений нет
- [x] внешний review `REVIEW_GREEN`

#### Baseline
- полный `make site-test` **до** (дерево = Stage 2): `Tests: 3461, Assertions: 20360, Deprecations: 7`, 0 падений, exit 0

#### Checks
- после каждого из шести Work items — `make site-test-unit`: `Tests: 1927, Assertions: 10969`, exit 0
- Stage check — полный `make site-test` **после**: `Tests: 3461, Assertions: 20360, Deprecations: 7`, 0 падений, exit 0, 6:04, пик 339 МБ
- **идентично baseline до последней цифры**

#### Внутренний review
- Итераций: 1. BLOCKER: нет. IMPORTANT: нет.
- Диф сведён к одному классу изменений: 98 вставок `declare` в 98 файлах.

#### Внешний review
- Ревьюер: Codex CLI 0.148.0, `codex exec -s read-only --ephemeral`
- Итераций: **3**
- **Круг 1 — не ревью.** Песочница ревьюера упала:
  `bwrap: loopback: Failed RTM_NEWADDR: Operation not permitted`. Он успел
  проверить только переданные тела файлов и честно отказался выдавать вердикт.
  По протоколу `CLAUDE.md` это конфигурационный сбой, а не результат:
  `REVIEW_GREEN` при упавшей песочнице заявлять нельзя, нужен повтор.
- **Круг 2 — `REVIEW_GREEN`.** Повтор с полным диффом всех 98 файлов прямо в
  промпте, чтобы шелл был не нужен. Инвариант «ровно две строки на файл»
  подтверждён по диффу, позиция `declare` единообразна, посторонних правок нет.
  Проверены тела `DailyBalanceRecalculator`, `MoneyAccountDailyBalanceRepository`,
  `CashTransaction`: результаты `fetchOne()` и `fetchAllAssociative()`
  приводятся явно, опасных переходов `numeric` → `float`/`int` нет.
- **Круг 3 — `REVIEW_GREEN`.** Ревьюер во 2-м круге поймал **мою ошибку**: в
  промпте я обещал четыре полных тела, а вложил три. Пропущен был
  `CashTransactionRepository.php` — 35 КБ, самая широкая поверхность работы с БД
  в модуле. Его зелёный вердикт опирался на неполные данные по моей вине.
  Третий круг закрыл именно этот пробел: все `fetchOne()` приведены явно
  (`EXISTS` → `bool`, идентификаторы → `string`), числовые значения перед
  `bcadd()`, `round()`, `abs()` и возвратом `float` приведены явно.
- Отклонённых findings: нет.

#### Исправления по review
- не потребовались

#### Риски / на что смотреть
- Из 98 файлов ревьюер видел полные тела четырёх; остальные 94 — только как
  дифф. Он назвал это ограничением доказательной базы, а не находкой, и это
  корректная формулировка: скрытый caller-side `TypeError` в непросмотренных
  файлах исключён только зелёным полным сьютом, не чтением кода.
- Урок процедуры: сверять список файлов, реально вложенных в промпт, с тем, что
  в промпте заявлено. Ревьюер поймал расхождение, но мог и не поймать.

#### Checkpoint
- `docs/tasks/strict-types/checkpoint.md` обновлён
- следующее действие: Stage 4 — `Shared` (9, первым и с полным прогоном),
  `Telegram` (9), `Billing` (5), `Balance` (4), `Admin` (4), `Report` (3), `Twig` (3)

#### Открытые вопросы
- нет

#### Ожидаемый ответ Владельца
- не требуется; `owner_gate: no`
