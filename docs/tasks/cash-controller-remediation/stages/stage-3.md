### Stage 3: Изоляция и валидация импорта — DONE

**Risk:** HIGH-LOCAL
**Stage base commit:** `32ba6693e`
**Work items:** 3.1–3.4

#### What was done
- Import sessions carry company/account IDs. A shared guard resets legacy, mismatched-company and inaccessible-account states before storage/preview/committed-job redirects. Guard returns payload + scoped account DTO, avoiding repeated queries.
- One mapping validator serves profile Actions and HTTP mapping/application; partial drafts are retained. Prepare/Confirm require a complete date + amount/full pair before any import plan can execute, preventing one-sided statements without changing row normalization/signs/precedence.
- Upload Action validates upload/readability, CSV/XLS/XLSX, nonempty ≤32 MiB and actual headers before storage write. Existing XLS conversion limits retained, technical storage failures stay distinct. One non-HTTP key builder serves writes/reads/legacy paths.
- Strict journal dates/range return 422, including impossible dates, null bytes and year zero; no DB date-cast failure.
- Prepare/Confirm Actions own plan/consent/job orchestration. Selection helper supports all/none/page and validates authorized rows. Import controllers split into final __invoke/constructor DI; sessions obtained from Request. Existing encoding conversion and byte escapes retained in services.
- 11 obsolete PHPStan baseline entries removed for deleted controllers; no baseline growth.

#### Definition of Done
- [x] Company switch/legacy/foreign account state reset before storage access.
- [x] Foreign profile/account/job routes remain scoped; no domain entities/flush/SQL in import controllers.
- [x] Malformed mapping rejected in forms and directly by Actions before mutation; partial drafts persist.
- [x] Actual preview/confirm cannot use an incomplete/half-pair configuration.
- [x] Real CSV/XLS/XLSX uploads reach mapping; TXT/corrupt/empty/failed/oversized uploads rejected.
- [x] Existing success, deduplication, description selection and repeated confirmation behavior retained.
- [x] 74 original route URL/method/requirement contracts unchanged.

#### Checks
- Baseline: Stage 2 Cash/Finance target module PASS 915 tests /3699 assertions.
- RED: 24 original functional cases (session/storage, invalid files/mapping, draft save, dates); real-upload follow-through exposed static callback/DI error; existing checkbox regression exposed session selection parity; null-byte and year-zero parameters reproduced 500; direct Action size mutation proved 32MiB guard independently of PHP upload limits.
- Initial integrated module PASS 973 tests /3787 assertions; after review fixes final module + shared storage/converter tests **984 tests /3800 assertions PASS**.
- Focused PHPStan: no errors. Focused CS Fixer clean. git diff --check clean. Route comparison 74/74 unchanged.

#### Internal review
- Four IMPORTANT fixed: static callback binding after service DI migration; preserving unsaved checkbox session selection; null-byte date rejection; PostgreSQL-incompatible year-zero date rejection. No BLOCKER/open IMPORTANT.
- Full Stage diff checked for company scope before data, safe error boundaries, unchanged financial normalization, immutable plan consent, no payload logging, preserved access/CSRF and route behavior.

#### External review
- Required: yes, HIGH-LOCAL >500 lines. Claude Code read-only, **1 round, fixed without re-run** per AGENTS §7.2; no BLOCKER.
- One IMPORTANT confirmed and fixed: half-pair draft could be planned/confirmed. Two functional entry points proved RED→GREEN; complete-import validator branches covered.
- Owner's draft-save requirement retained: incomplete mappings may be saved, but Prepare/Confirm reject them clearly before execution. No per-row financial parser change.
- MINOR fixed: original hex byte escapes/comments restored; guard returns scoped account instead of duplicate lookup; key builder centralized in existing import service.

#### Risks / follow-ups
- Immediate delivery failure window belongs to Stage 4; no deploy before all five stages.
- No migration/dependency/production schedule change, no historical data processing.

#### Next
- Continue to Stage 4 automatically.
