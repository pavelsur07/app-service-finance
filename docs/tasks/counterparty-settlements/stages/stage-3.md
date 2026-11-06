### Stage3: погашения, авансы и отчёты — DONE

Risk HIGH-LOCAL. Base81ba2ffa5. Work items3.1–3.3.

Реализованы частичные M:N распределения, классификация свободного остатка,
датированные отмены с повтором. Доступные суммы Money перечитываются под книгой
и отсортированными позициями; корректировка может увеличить capacity.
Все последующие остатки проверяются отдельно по шести корзинам.

Отчёт/карточка: открытие, прирост, уменьшение, закрытие, просрочка и отсутствие срока.
Страница и полные totals из одного SQL-снимка, подписи контрагентов пакетно.
Opening не становится обычным оборотом; SUM проверяется на overflow до int-cast.
Порядок движений семантический date/sequence/item/bucket, стабилен после rebuild.

Независимая проекция из первичных документов, позиций и распределений позволяет
verify обнаружить неверное/пропущенное/лишнее движение или source link. Rebuild
dry-run по умолчанию; execute атомарно меняет только производные. Исторические
суммы, активная связь источника и lastReversalDate сохраняются. Активные источники
заблокированы и проверены по fingerprint; противоречивые первичные данные отвергаются.
Production execute не запускался.

Files: src/Settlement Application/DTO/Domain/Enum/Repository/Query/Facade/Command;
Company batch labels + maintenance lock; Finance batch Cash hints + prelocked allowlist;
services_test.yaml; Settlement unit/integration/concurrency fixtures; ARCHITECTURE.md.
Ни tests/Architecture, ни frontend в этом этапе не менялись.

Checks: перед Stage baseline предыдущего набора119/608; red capacity/allocation,
report and verifier tests recorded in checkpoint. Final module PASS87/441.
Focused PHPStan41 changed PHP files PASS, project focused CS PASS.
Две независимые PG-сессии доказали отсутствие двойной траты и двойной отмены.
EXPLAIN10000 synthetic movements:100 parties, page50, total100.00RUB,42.728ms.
Evidence performance.md + report-explain.json.

Internal review: B0/I1/MINOR2; все исправлены, bounded rereview B0/I0.
I1 primary projection: direction, due date, reversal identity/date and dependency
chronology checked; red/green refusal regressions preserve journal unchanged.
External: one round, REVIEW_GREEN, B0/I0/MINOR4. Minors1–3 fixed and verified:
stronger corruption assertions, UUID input guards, avoid discarded card query.
MINOR4 not applied: outer write authorization is retained before reading allocation;
inner authorization takes fresh locks before mutation. Removing the former would
allow unauthorized callers to distinguish a missing allocation from an existing one.
This is a recorded technical rejection, not an open must-fix. No repeat external round.

Next: Stage4 UI automatically, then final handoff gates once in Stage5.
