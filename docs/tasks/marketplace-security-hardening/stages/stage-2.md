### Stage 2: H4 — tenant-сверка внутри Actions обработки raw-документов — DONE

**Risk:** HIGH-LOCAL
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Next action:** continue autonomously (Stage 3)

#### Stage scope
- Stage base commit: `787e49cc`
- Work items completed: 2.1, 2.2, 2.3, 2.4

#### What was done
- `ProcessOzonRealizationAction::__invoke` — guard: rawDoc обязан принадлежать `companyId` аргумента, иначе `RuntimeException` (до чтения данных и любых мутаций)
- `ProcessMarketplaceRawDocumentAction::__invoke` — аналогичный guard vs `command.companyId`; тип исключения `UnrecoverableMessageHandlingException` (детерминированная ошибка, ретраи бессмысленны — прецедент `ProcessRawDocumentStepMessageHandler:48-52`)
- Регрессионные integration-тесты `RawDocumentTenantGuardTest` (3 теста: чужой документ отвергается обоими Actions, свой пустой realization-документ — no-op без исключения)
- Существующий unit-тест `ProcessMarketplaceRawDocumentActionTest` адаптирован к новому контракту (mocks company.getId = command.companyId)

#### Files changed
- `site/src/Marketplace/Application/ProcessOzonRealizationAction.php` — modified
- `site/src/Marketplace/Application/ProcessMarketplaceRawDocumentAction.php` — modified
- `site/tests/Integration/Marketplace/Application/RawDocumentTenantGuardTest.php` — new
- `site/tests/Unit/Marketplace/ProcessMarketplaceRawDocumentActionTest.php` — modified

#### Definition of Done
- [x] Оба Actions отвергают raw-документ чужой компании исключением при прямом вызове
- [x] Регрессионные тесты: чужой документ → исключение; свой → обрабатывается (существующие тесты зелёные)
- [x] Аудит вызывающего кода: легитимных путей с mismatched company/doc нет (подтверждено внешним review)

#### Baseline
- Stage 1 final: unit 1722 OK; functional Marketplace+Admin 119 OK

#### Checks
- targeted: `RawDocumentTenantGuardTest` + `ProcessMarketplaceRawDocumentActionTest` — OK (9 tests)
- module: `tests/Integration/Marketplace tests/Functional/Marketplace` — OK (337 tests)
- full stage: `make site-test-unit` — OK (1722 tests)

#### Internal automatic review
- Iterations: 1
- BLOCKER: none
- IMPORTANT: none
- MINOR fixed: тип исключения → UnrecoverableMessageHandlingException (по MINOR внешнего review)
- FOLLOW-UP: none

#### External Claude Code review
- Iterations: 1
- Result: REVIEW_GREEN
- Confirmed findings fixed: MINOR (retry-семантика) — исключение заменено на UnrecoverableMessageHandlingException
- Rejected findings with reason: none

#### Review fixes applied
- `UnrecoverableMessageHandlingException` в ProcessMarketplaceRawDocumentAction (skip ретраев при tenant-mismatch)

#### Risks / reviewer focus
- Guard — defense-in-depth; все текущие вызовы tenant-consistent (проверено трейсом вызовов)
- Сообщения исключений статичны, без чужих идентификаторов

#### Checkpoint
- `docs/tasks/marketplace-security-hardening/checkpoint.md` updated
- exact next action: Stage 3 — M5 пагинация productsIndex + M10 транзакция AssignListingTagAction

#### Open questions
- none

#### Expected owner response
- not required; continuing autonomously
