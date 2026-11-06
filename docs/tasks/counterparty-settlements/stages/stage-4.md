### Stage4: интерфейс взаиморасчётов — DONE

Risk HIGH-LOCAL. Basea3b1ba860. Work items4.1–4.2.
Risk повышен с планового MEDIUM: новые финансовые HTTP-мутации и access-map
требуют HIGH-LOCAL по rootAGENTS; поэтому внешний round для diff>500 обязателен.

Реализованы setup/cutoff, сводка шести корзин и историческая карточка, отдельные
Документы (серверная пагинация/детали/проведение), ручные основания и opening,
Cash include, Finance explicit full-amount draft, M:N allocations, corrections,
classification and dated cancellation. Переходы из обеих навигаций, ДДС, ОПиУ,
контрагентов; в документе погашения ссылки на обе исходные позиции.

Controllers решают activeCompany до запросов, read/write через FINANCE и свежие
Action guards. CSRF, requestId, version, scoped choices, UUID validation. Setup CSRF
связан с компанией. Пустые поля, лишняя точность и overflow дают ошибки формы,
ложное округление не происходит. Отозванное право и чужая компания отклоняются.

Карточка/отчёт/документы пагинируются. Неограниченный второй список карточки убран;
подсказка формы ограничена50строками, подписан полный объём и ссылка на документы.
Нативные выборы сохраняют полный company scope; idempotent retry после исчерпания
оплаты достигает Action replay и не повторяет финансовый эффект.

Files: Settlement Controller/Form/Query/DTO/PreviewAction; routes/twig/ModuleAccessMap;
settlement templates, existing source/counterparty/sidebar templates; financial UI tests.
Source business logic не переносилась во frontend. JS/assets/dependencies не менялись.

Checks: baseline existingSourceUI2/17 PASS. Journey missing route red→green,
Money precision/overflow/bool/date/errors/access/revoked rights covered. Final targeted
module+UI+affected legacy views PASS144/725. Focused33PHPfiles PHPStan/CS PASS;
final reviewed controllers/tests PHPStan PASS, Twig21files PASS. Browser10screens
1440/390: no horizontal page overflow/unlabelled controls/JS errors, visible focus.
See ui-smoke.md/json; offline rendered fixtures rollback, no production data.

Internal: B0/I3/MINOR1 from fresh reviewer, all fixed with HTTP red/green evidence.
I1 blank choices caused500; I2 setup wrong company after switch; I3 unbounded items.
MINOR exact allocation repeat after consumption failed choice validation. Additional
local UI fixes: remove ignored counterparty filter in fixed-party card; adapt native
company-scoped choice styling to app layer. Bounded rereview no open findings.

External1round REVIEW_GREEN B0/I0/MINOR3. All fixed and verified, no rerun:
Finance reinclusion default date >=lastReversal; classification GET rejects non-payment
buckets; configured setup POST422 before disabled form handling/false success.
Classification guard follows bucket semantics of Action, preserving initial advances;
restricting kind to PAYMENT would silently narrow existing allowed financial behavior.

Rulings/follow-ups: GET+POST for reason/date cancellation/classification forms;
external legacy IDs use existing Ramsey validation. CounterpartyPickerType retains
company scope/browse=true; app layer uses kit native select because legacy picker
is tied to Tabler. TODO(ds): enhanced app picker adapter, intentionally out of scope.

Next: Stage5 fixed-date acceptance, migration acceptance, full gates once, fresh
whole-task reviews and release handoff. No merge/deploy permission has been requested.
