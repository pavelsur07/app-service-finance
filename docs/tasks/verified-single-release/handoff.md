# verified-single-release — сохранённое состояние

Branch feat/verified-single-release; Draft PR https://github.com/pavelsur07/app-service-finance/pull/3042, base master.
Код завершён, порядок выпуска удерживается до завершения part1 local acceptance.

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
PHP-набора отдельно GREEN. Local full PHPStan blocked by доказанный host global
OOM; три cold/warm/warm first task пока не получены. Экономия не заявляется.

Review metrics: internal B0/I4 fixed; external B2/I4 fixed, 3 rounds,
round3 REVIEW_GREEN; remaining minor fixed with targeted RED→GREEN.
Fresh final internal B0/I0/M0. Reviewer root-file path limitation закрыта context.

Риски: реальные production backup permissions/health не проверены без release
approval; backup root должен уже быть доступен workflow user, права не расширялись.
Backup retention/restore — отдельные задачи утверждённого плана. Upload и
successful previous configs очищаются; failed configs сохраняются для диагностики.

Не выполнено: три local full GREEN acceptance runs первой задачи и разрешённый
production acceptance. Merge/deploy не выполнялись и пока не запрашиваются.
