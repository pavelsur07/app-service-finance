### Stage 2: Telegram и recovery — checking

Risk: HIGH-LOCAL. Stage base: `b12c78cf1`. Work items: 2.1, 2.2.

- Pilot class/DI/guards удалены; new/edit/retry используют существующий AI grant.
- Manual/Confirm/delivery разрешены без AI grant при сохранении финансовых прав.
- Recovery: UUID или --all (оба/ни один → INVALID), sorted/deduplicated union
  admin grants + pending sessions; пакет 100, company-scoped; изоляция ошибок
  Cash/Telegram; закрытый EM → FAILURE, следующий cron начинает заново.
- RED: granted nonpilot без старых env создавал 0 вместо 1 draft;
  recovery --all отсутствовал, company-id был обязательным.
- GREEN: module Telegram/PaymentIntake 180 tests / 1269 assertions;
  multi-company integration 3 tests / 19 assertions.
- Focused CS и PHPStan PASS.
- Internal whole-stage review: BLOCKER 0 / IMPORTANT 0. Нет новых финансовых
  правил, grant bypass, legacy replay или записей вне компании.
- External: round 1 running (diff >500 changed lines с тестами).
- Stage 3 runtime tests подготовлены отдельно, в этот Stage не входят.

#### Review fix / completion
- External round 1: IMPORTANT 1 (pending Cash event after revoked grant), MINOR 2.
- IMPORTANT fixed without re-run: отдельный event-only Cash recovery + IDs через
  Facade; компания без grant/Telegram session включается при pending Cash event.
  Regression RED: cash_event_pending оставался true; GREEN: false.
- MINOR fixed: unselected sticky multi-binding test, sessionId in failure log.
- После исправления: 182 tests / 1278 assertions PASS, focused CS/PHPStan.
- Open BLOCKER/IMPORTANT: 0. Stage DONE; следующий Stage 3 автоматически.
