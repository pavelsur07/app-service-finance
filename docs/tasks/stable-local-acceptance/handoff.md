# stable-local-acceptance — handoff

Branch feat/stable-local-acceptance; PR https://github.com/pavelsur07/app-service-finance/pull/3040,
base master. Реализация и local acceptance завершены, release approval pending.

Файлы: Makefile, site/bin/site-accept.py, site/phpstan.local.neon,
tests/shell/site-accept-test.py, docs/workflow/local-acceptance.md и task docs.
Один CLI-контейнер, preflight, fail-fast, отдельные PHPUnit processes,
память/cgroup/time/exit artifacts, fingerprint и надёжный signal cleanup.
Local PHPStan main-only с исходными level8/baseline/paths/2GiB; Xdebug off,
Composer timeout0, существующие отдельные Make targets сохранены.

Проверки: 10 regression incl real PHP/config/Scheduler GREEN, существующие
workflow/frontend contracts GREEN; full site-accept трижды GREEN на одном
fingerprint: cold24:59, warm11:07, warm10:40. Каждый run: Unit4928,
Integration3037, Functional1497 + cs/strict/PHPStan, cleanup0. Raw artifacts
игнорируются Git, безопасные aggregate measurements — measurements.md.
Кодовый CI9c5fc24cc GREEN; последующий commit меняет только task docs.

Review metrics: internal B0/I2 fixed; final fresh internal B0/I0/M0.
External1 round REVIEW_GREEN B0/I0, minor fixed with regression evidence.
Новых migrations/dependencies/application code changes нет. Исторический
schema:validate mapping drift не добавлен в gates и не исправлялся вне scope.

Resource action отдельно разрешён владельцем: swap2+6GiB, total8GiB,
root:root0600, fstab0 errors и daemon-reload. Production не менялся.
Сравнимого старого полного baseline нет (OOM), поэтому причинная оценка
ускорения не заявляется. Production acceptance — после release approval.

Ready: PR #3040 "feat: стабильная последовательная локальная приёмка" —
merge into master with automatic production deploy?
Reply: "merge and deploy #3040"
Далее выпуск #3042 согласно утверждённому порядку двух PR.
