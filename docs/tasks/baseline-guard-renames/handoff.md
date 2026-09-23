# baseline-guard-renames — handoff

**Branch:** `chore/baseline-guard-renames` · **PR:** https://github.com/pavelsur07/app-service-finance/pull/2509 · **CI:** см. PR

## Summary
- CI-гейт роста baseline учитывает переименования: база переписывается по карте `git diff -M`,
  текущий baseline не трогается; склейка разных записей базы в один ключ — отказ (fail closed).
- Попутно: выход 141 (SIGPIPE) заменён штатным 1.

## Contract / CI changes
- `.github/workflows/deploy.yml`, шаг «📉 Baseline не должен расти»: строит карту
  переименований и передаёт guard'у. Условие `[baseline-grow]` не менялось.
- **Изменение поведения CI-гейта — отдельное согласие по AGENTS.md §3.3.**

## Checks
- Реальные переносы #2506 и #2507: с картой «не вырос»; без карты — отказ; с картой +
  искусственная запись — ровно 1 NEW.
- Тесты guard'а: 20 (12 прежних + 8 новых); unit 2979; phpstan OK (helper отдельно — level 8);
  cs, strict-types OK.

## Reviews
- internal: 1 итерация — IMPORTANT (склейка записей) исправлен, MINOR (уборка, тест на 141) исправлены
- external: 1 раунд — IMPORTANT (та же склейка) fixed without re-run; вторая находка
  (`site/bin/capture-wb-inventory.sh`) отклонена: неотслеживаемый файл Владельца вне ветки

## Risks, follow-ups
- Ложный красный возможен, если перенесённый файл объявляет класс с именем, уже
  встречающимся в базе под старым namespace, — безопасная сторона; выход — `[baseline-grow]`.

## Owner decision
Ready: PR #2509 "chore(ci): baseline-guard учитывает переименования файлов" — изменение CI-гейта (AGENTS.md §3.3) и merge into master with automatic production deploy?
Reply: "merge and deploy #2509, включая изменение CI-гейта"
