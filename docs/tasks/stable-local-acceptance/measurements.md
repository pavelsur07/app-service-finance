# Проверки и измерения

Все команды выполнялись в изолированном worktree, Docker project vf-acceptance,
на локальной БД; production и реальные внешние API не использовались.

| Проверка | Результат |
|---|---|
| Regression с SITE_ACCEPT_TEST_IMAGE | 10/10 GREEN (включая реальный PHP preflight и PHPStan config/Scheduler) |
| Existing workflow Python + shell | 6 GREEN + SSH/worker invariants GREEN |
| Frontend release contracts | 6 GREEN |
| Unit | 4928 tests / 27251 assertions, 14.344s, 254 MB |
| Integration | 3037 tests / 14508 assertions, 400.164s, 559 MB |
| Functional | 1497 tests / 11504 assertions, 291.242s, 486 MB |
| Global schema:validate (diagnostic only) | mapping OK, database out of sync; unrelated legacy state |

| Полный cold start | Стиль | Strict types | PHPStan |
|---|---:|---:|---|
| max subprocess workers=1 | 161.341s / 0 | 23.849s / 0 | 436.349s / 1, worker lost |
| main analyser only (workers=0) | 158.514s / 0 | 27.031s / 0 | 683.001s / 137 |

Оба полных запуска сохраняли один fingerprint (summary.valid=true).
Оба остановились fail-fast, CLI-контейнер удалён; автоматических повторов не было.
Первый OOM подтверждён kernel task_memcg и совпадающим container ID, убит PHP
с anon RSS ~868 MiB. Второй подтверждён code137 и kernel PHP OOM в то же время
(~897 MiB RSS). При первом сбое swap практически исчерпан (180 KiB из 2 GiB).
Хост: около 5.8 GiB RAM; доступно перед stan около 1.6 GiB, позднее ~1.1 GiB.

До этих запусков были два диагностических прерывания для исправлений review;
они корректно получили invalid fingerprint и не считаются полными прогонами.

Три последовательных GREEN прогона (cold+warm+warm) НЕ подтверждены.
Экономия времени не оценена: нет сопоставимого успешного полного baseline,
состояние занятого общего хоста менялось. RAM/права/чужие контейнеры не менялись.
Артефакты команд/памяти/выходов находятся в игнорируемом site/var/accept/.

## Контрольные прогоны после разрешённого увеличения swap

2026-11-16: отдельный swap6GiB добавлен к исходному2GiB (итого8GiB),
root:root0600; /etc/fstab проверен, systemd daemon-reload выполнен.
Старый swap оставлен активным; новых пакетов и расширения прав нет.

| Run | Всего | cs, s | strict, s | stan, s | unit, s | integration, s | functional, s |
|---|---:|---:|---:|---:|---:|---:|---:|
| cold | 24:59 | 160.169 | 22.740 | 650.251 | 14.687 | 347.211 | 283.624 |
| warm-1 | 11:07 | 1.318 | 1.182 | 9.159 | 15.457 | 361.722 | 272.932 |
| warm-2 | 10:40 | 1.336 | 1.159 | 5.073 | 14.198 | 344.369 | 268.707 |

Каждый run: preflight/prepare/gates/cleanup exit0, summary.valid=true.
Unit4928 + Integration3037 + Functional1497 = 9462 tests/run, 28386 executions
за три прогона. Все start/end fingerprint идентичны:

```text
1c949561cdb9c5756ef914931407261e667bcf90e8a66c2193749e384c855507
```

Артефакты:
- site/var/accept/20261116T170916Z-vf-accept-24337357b6ac/
- site/var/accept/20261116T173415Z-vf-accept-43f88a06496d/
- site/var/accept/20261116T174522Z-vf-accept-1775692fc4c3/

Кодовые source files между прогонами не менялись; после приёмки обновлены
только task-документы. Конкретная экономия относительно прежнего полного
runner не заявляется: прежние runs падали от OOM. Разница cold/warm измерена,
но относится к кешу и текущему состоянию хоста, не к причинному A/B benchmark.
