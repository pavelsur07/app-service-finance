# Stage1 ТЗ и чек-лист
stage_base_commit: ae0b812c4438495139bc94bdaecbf6817ad53b3b. Risk HIGH-LOCAL.
Implement plan1.1–1.3, exact requirements in TASK.md. No Cash/Gateway cross-module changes needed: pilot scope Telegram; already admitted parsing may finish after rollback, money still manual.
- [ ] policy strict list/off/empty/invalid/wildcard coverage
- [ ] participant creates draft, nonparticipant legacy retained
- [ ] selected/known source/session/reply do not reach legacy on revoke/off
- [ ] no session persist before eligibility during Cash source recovery
- [ ] callback, queued poll, queue publication, recovery blocked when removed
- [ ] actorless path requests rebind without guessing owner
- [ ] --skip-cash-recovery tested; default existing behavior preserved
- [ ] targeted RED→GREEN, module tests/style/Stan, whole-diff internal review
- [ ] report/commit/push/Draft PR update, no owner stage gate
