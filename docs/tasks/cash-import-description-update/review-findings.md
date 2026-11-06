# Итог ревью

## Internal: свежая независимая сессия

Code findings исправлены, проверены regression/red→green и повторным независимым просмотром:

- BLOCKER6: повторное вычитание legacy-квоты из approved creates; document wildcard назначал неверный target; financial conflict скрывался другой document-группой; counterparty wildcard назначал неверный target; относительная дата менялась между approved/fresh/actual parsing; пустая строка расходовала произвольного кандидата с другим исходным текстом.
- IMPORTANT8: календарная дата при UTC преобразовании; сброс согласия при profile/mapping; целостность исходных bytes; недостаточная проверка financial snapshot/audit/chunk recovery; N+1 контрагентов; error/skipped счётчики; Unicode-пробелы; отображение реквизитов существующего кандидата.
- Отдельный **release BLOCKER1 исправлен**: старый consumer способен получить новый job между обновлениями FPM/worker. Владелец явно разрешил consumer-first patch; он применён и независимо проверен. YAML/b​ash-n41scripts/order assertions PASS; см. release.md.

Метрики internal: **7 BLOCKER found/fixed;8 IMPORTANT found/fixed**. Code review open B/I:0; delivery open BLOCKER:0. Ошибки baseline/style tools не считаются review findings.

## External: Claude read-only

- Stage2, base9f6d94a81,1 раунд:0B/2I (HTTP confirmation/error lifecycle; transaction per row). Исправлено и проверено — fixed without re-run по AGENTS.md §7.2.
- Handoff, basecfb6728d, round1:0B/1I (managed snapshot memory). Scalar projection и bounded managed graph, тесты/benchmark. MINOR: read-permission result, pager boundaries, error details/filter reset — исправлены.
- Handoff round2: точный `REVIEW_GREEN`; MINOR1024 Unicode limit исправлен без truncation, regression GREEN.
- Handoff round3 после actual-create guard и empty-row ambiguity fix: точный `REVIEW_GREEN`; MINOR UUID route и malformed UTF8 friendly error исправлены targeted regression.

Метрики external: **0 BLOCKER /3 IMPORTANT found/fixed**, Stage2 rounds1 + handoff rounds3. Rejected confirmed findings:0. Reviewer не имел write/secret/production доступа. Отчёты runtime: `site/var/external-review/{9f6d94a8,cfb6728d}/`; каталог var не коммитится. Этот документ сохраняет вывод и принятые исправления. Consumer-first patch применён после named approval, focused independent review PASS; external rounds выше относятся к финансовому коду до этой согласованной инфраструктурной правки.
