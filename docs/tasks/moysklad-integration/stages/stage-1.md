# Stage 1: Backend и безопасность

Risk: HIGH-LOCAL. Work items: 1.1, 1.2, 1.3.
Review base:238dd997b27ecca5a1ae205fb417534e79de3439; integrated upstream base0b4206db373fa89cff0d67146641093bdafc8461.
Status: implementation and internal review complete; final checks tracked in handoff.

API client проверяет пользовательский Bearer token и accountId без редиректов/повторов. Подключения изолированы по компании, accountId уникален глобально. Новые секреты зашифрованы Shared codec. Реализованы создание/редактирование/проверка/замена токена/отключение/включение/удаление с CSRF, cooldown и concurrency. Миграция аддитивна, ARCHITECTURE.md обновлён.

Files: site/src/MoySklad, site/config, site/migrations/Version20260914143000.php, site/tests/{Unit,Integration,Functional,Builders}/MoySklad, ARCHITECTURE.md. Удалены только заменённые старые CRUD actions/commands.

Checks: baseline Shared security18/45, Marketplace functional25/100 PASS; module73/356 PASS до дополнительного mixed-route теста. Full PHPStan2620files PASS, focused static/style PASS. Итоговые результаты handoff включают исправление READ/POST WRITE.

Review focus: credentials/profiler, company scope/IDOR, global account uniqueness, database transaction race, HTTP stale result. Internal findings исправлены; внешний интегрированный review round1 REVIEW_GREEN и MINOR fixed without re-run. Details in review-findings.md.

Delivery note: Stage scopes implemented concurrently and integrated in checkpoint7100b57b, followed by upstream merge and review fixes; separate pre-implementation stage_base commits for Stage2/3 were not recorded. This is a workflow deviation, not a claim of sequential Stage closure.
