# Stage 2 — immutable preview

Base: `03bd475df`. Risk MEDIUM. DoD: TTL/binding/limits, no revision writes.

- DTO Preview/Item/Guard и ApplyResult/Item/Status (результаты нужны cache contract уже здесь).
- Dedicated Redis pool, scalar arrays; session-key LockFactory mutex; max3 previews/3 results; TTL900; explicit409/503.
- RED4 missing Storage/Guard → GREEN7/41, включая два независимых реальных Redis worker процесса (preview и result); отклонённый409 повторяется и подтверждённые payload не теряются.
- MockClock expiry; scope session/company/actor/connection/provider; immutable ID, eviction, stable requestId, cache outage.
- Worker bootstrap исправлен по existing Dotenv pattern после fixture failure. Focused PHPStan/CS PASS; initial Stan2 typing findings fixed.
- Internal review complete Stage diff: BLOCKER0/IMPORTANT0; payload bounded25, no credentials/Entity/PHP serialize, no raw session in HTML/logs.
- External review не требуется для MEDIUM Stage; будет на handoff.
- Module PASS449/1868. Next Stage3 guarded existing writer, без общей batch транзакции.
