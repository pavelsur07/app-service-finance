### Stage 1: тривиальные файлы без бизнес-логики — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously → Stage 2

#### Stage scope
- Stage base commit: `db63e91ceae0f5adaf61e5abb743ba56d2ed20ee`
- Work items completed: 1.1 (`src/Exception`), 1.2 (`src/Util`, `src/Kernel.php`), 1.3 (`src/DataFixtures`)

#### Что сделано
`declare(strict_types=1)` добавлен в 13 файлов. Долг: 309 → 296.

#### Файлы изменены
- `site/src/Exception/{ForbiddenCompanyAccess,NegativeAmount,SnapshotIntegrity}Exception.php` — modified
- `site/src/Util/StringNormalizer.php` — modified
- `site/src/Kernel.php` — modified
- `site/src/DataFixtures/*.php` (8) — modified
- `docs/tasks/strict-types/plan.md`, `stages/stage-1.md`, `checkpoint.md` — new

Итог диффа: 13 файлов, +29 −3. Из них 26 строк — вставки `declare` с пустой
строкой, 3 пары `use` — сортировка по алфавиту правилом `ordered_imports` из
конфига проекта (каждая удалённая строка имеет идентичную добавленную).

#### Definition of Done
- [x] 13 файлов получили `declare(strict_types=1)` — проверено: 0 файлов без него в этих путях
- [x] тесты не хуже baseline
- [x] посторонних изменений в диффе нет

#### Baseline
- `make site-test-unit` **до** изменений: `Tests: 1927, Assertions: 10969, Deprecations: 5`, exit 0
- `make site-test` (полный) **до** изменений: прерван на 2623/3461 (75%), до прерывания
  ноль падений. `make` вернул 130 (SIGINT), не OOM.

#### Checks
- targeted: `make site-test-unit` **после** — `Tests: 1927, Assertions: 10969, Deprecations: 5`, exit 0. Идентично baseline.
- style: `php-cs-fixer fix <path>` повторно на всех четырёх путях — `Fixed 0 of N` (идемпотентно, конформно)
- потребители `StringNormalizer` (`PaymentPlanServiceTest`, `CashTransactionAutoRuleServiceTest`) лежат в `tests/Unit/` и входят в прогон

#### Внутренний review
- Итераций: 1
- BLOCKER: нет
- IMPORTANT: нет
- Весь диф сведён к двум классам изменений: вставка `declare` и сортировка `use`. Проверено построчно.

#### Внешний review
- Ревьюер: Codex CLI 0.148.0, `codex exec -s read-only --ephemeral`
- Итераций: 1
- Результат: `REVIEW_GREEN`
- Подтверждённых находок: нет. Отклонённых: нет.
- **Ограничение ревьюера:** запускался без шелла, дифф и контекст переданы через
  stdin. Репозиторий самостоятельно не читал, поэтому его вердикт покрывает
  только показанный дифф, а не окружающий код.

#### Риски / на что смотреть
- Полный `make site-test` на этом хосте (3 ГБ RAM, ~0 свободно) не дошёл до конца
  ни разу. Для Stage 2+ либо поднимать память, либо принять `site-test-unit`
  + целевые интеграционные как Stage check и записать это решение.
- Stage 1 сознательно выбран нулевым по риску: ни один файл не содержит
  бизнес-логики, кроме `StringNormalizer`, а тот покрыт юнит-тестами.

#### Checkpoint
- `docs/tasks/strict-types/checkpoint.md` обновлён
- следующее действие: Stage 2 — Marketplace (32), Company (25), Finance (10), Catalog (2), MarketplaceAnalytics (1)

#### Открытые вопросы
- нет

#### Ожидаемый ответ Владельца
- не требуется; `owner_gate: no`, продолжаю автономно
