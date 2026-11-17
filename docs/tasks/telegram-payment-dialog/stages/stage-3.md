### Stage 3: компактная проверка списка — DONE

Risk MEDIUM. Stage base `dc1ae96c7`. Work items 3.1, 3.2.

Доставка показывает общие вопросы и сводку по пять строк; детали, следующие
страницы и длинные вопросы открываются явно. Каждая часть общей сводки имеет
revision/scope. Главное действие строки — отдельный ряд, текстовые правки
показывают изменённую строку и регистрируют ID новой карточки для reply.
Суммы отдельно по валюте/направлению; целые и дробные суммы поддерживаются.
Меню позволяет открыть/отменить разбор без прокрутки истории.

DoD выполнено. Общие ошибки реквизитов исправляются соответствующим полем,
их нельзя скрыть общей кнопкой. Неизвестные финансовые вопросы batch approval
не снимает. Сохраняются права, идемпотентность и отдельное сохранение пакета.

Checks: module 161 tests /1164 assertions PASS; targeted PHPStan предыдущего
состояния PASS, после финальных fixes running; canonical CS PASS, strict PASS.
Полный make site-test: 9482 tests /53407 assertions, 1 failure в незатронутом
InviteRegistrationFlowTest:67 (company count 2 vs1). Изолированный test PASS1/8;
исследуется зависимость от порядка, CI финального SHA должен подтвердить gates.
Полный unit gate 4935/27299 PASS до последних regression additions; далее
финальный module PASS и CI. Полный PHPStan gate PASS, baseline не увеличен.

Internal review fresh session: initial I5, follow-up I3, fixed; REVIEW_GREEN.
Stage 2 self-review I1 fixed; task tracked internal B0/I9, open0.
External round1: Claude CLI отсутствует → Codex read-only. Первая попытка не
смогла читать repo (bwrap loopback RTM_NEWADDR). Одна исправленная попытка:
полный diff/spec/rules/surrounding sources в stdin, все tools запрещены.
Static-only review: B0/I3. Все исправлены и проверены targeted tests, результат
fixed without re-run (§7.2 IMPORTANT-only). Findings: incomplete header pair
не теряет project; unknown financial questions не снимаются batch; каждый
long-question chunk сохраняет revision. Независимый internal reviewer после
этих fixes снова REVIEW_GREEN. Ограничение внешнего review: без запусков тестов.

React/Vite/Twig не затронуты; frontend build не требуется. Mobile smoke через
проверку Telegram payload (короткие кнопки/строки, main action alone,
plain text, chunks≤3500, callbacks≤64); live Telegram/LLM не вызывались.
Next: handoff/CI/release pipeline.

Post-handoff regression: повтор текстового /cancel после нового сообщения
мог отменить следующий draft (RED1 failure). Исправлено через тот же receipt:
отмена+identity commit одной транзакцией; выбор из нескольких drafts также
помечает команду. Тест rollback проверяет отсутствие receipt при ошибке отмены.
Module162/1169 PASS; focused fresh internal REVIEW_GREEN. Internal total B0/I10.
CI915bf471d SUCCESS; финальный retry-guard SHA проходит отдельный CI перед merge.
