# stable-local-acceptance — сохранённое состояние

Branch: feat/stable-local-acceptance. PR: https://github.com/pavelsur07/app-service-finance/pull/3040
Статус: Draft; acceptance заблокирован ресурсами общего локального хоста.

Добавлены site/bin/site-accept.py, site/phpstan.local.neon, regression tests,
Make targets/env, docs/workflow/local-acceptance.md. Один CLI-контейнер,
preflight, fail-fast, раздельные PHPUnit-процессы, cgroup/memory/time/exit
артефакты и fingerprint. Local PHPStan — один main process с cache/level8/2GB.

10 regression и все существующие PHP-наборы отдельно GREEN:
Unit4928, Integration3037, Functional1497. Стиль и strict-types GREEN.
Два full cold завершились host OOM (1 и 137), три GREEN cold/warm/warm не получены.
Подробности в measurements.md и checkpoint.md; приложение/финансовые формулы
не менялись. Новых миграций и зависимостей нет.

Fresh internal: B0/I0/M0; в ходе работы internal I2 исправлены, external B0/I0,
1 round REVIEW_GREEN, MINOR fixed. Отпечаток и cleanup проверены реальными runs.

Не выполнено: три последовательных полных GREEN прогона. Нужна свободная RAM;
увеличение ресурсов, остановка чужих процессов и изменения swap не выполнялись.
Merge/deploy не разрешены и не запрашиваются до закрытия acceptance.
