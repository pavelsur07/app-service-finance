# verified-single-release — сохранённое состояние

Branch feat/verified-single-release; Draft PR https://github.com/pavelsur07/app-service-finance/pull/3042, base master.
Код и проверки завершены. Порядок выпуска: сначала #3040, затем #3042.

Файлы: .github/workflows/deploy.yml; site/bin/release-bundle.py;
docker/release/{production,installed-sha,infrastructure-digests}.sh; Makefile;
regression tests и docs/workflow/release.md. Контракты приложения и финансовая
логика не менялись, новых DB migrations/dependencies нет.

Trusted manifest/SHA, все gates, 12 immutable image digests/20 services,
перезапуск без сборки готового комплекта и partial rerun; production job/flock;
backup→migration→schema→rolling→health; anti-downgrade и idempotent same-SHA retry.
Инфраструктура фиксируется по установленным digest, не обновляется/не рестартует.

Проверки: 12+8+8+1 real PG новых тестов и 6+6+4 существующих Python + shell GREEN;
actionlint/diff-check GREEN. Общие PHP источники неизменны: cs/strict и все три
PHP-набора отдельно GREEN. Полный cold site-accept первой задачи прошёл после отдельного разрешения
владельца на постоянный swap8GiB; два warm выполняются на тех же исходниках.
Экономия реального production release ещё не измерена.

Review metrics: internal B0/I4 fixed; external B2/I4 fixed, 3 rounds,
round3 REVIEW_GREEN; remaining minor fixed with targeted RED→GREEN.
Fresh final internal B0/I0/M0. Reviewer root-file path limitation закрыта context.

Риски: реальные production backup permissions/health не проверены без release
approval; backup root должен уже быть доступен workflow user, права не расширялись.
Backup retention/restore — отдельные задачи утверждённого плана. Upload и
successful previous configs очищаются; failed configs сохраняются для диагностики.

Ожидается: завершение двух warm acceptance runs первой задачи; разрешённый
production acceptance SHA/digest/schema/health/timings. Merge/deploy не выполнялись.

Owner decision at delivery:
Ready: PR #3042 "ci: единый проверенный релиз без повторной сборки" — merge into master
with automatic production deploy, after #3040?
Reply: "merge and deploy #3042"
