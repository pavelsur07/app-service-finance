# Стабильная локальная приёмка

Источник: утверждённый approved-plan.md от 2026-11-16, часть 1.
Класс Large; отдельный PR, без production-действий. База: da7cef655.
Baseline: `make test-deploy-workflow` — shell-инварианты и 6 Python-тестов зелёные.
Эталоны: Makefile (test-env/prepare), bin/frontend-production-test.sh,
 tests/shell/frontend-release-test.py. Бизнес-контракты не меняются.

## Stage 1: Оркестрация и регрессионные тесты
Risk: MEDIUM. stage_base_commit: da7cef655.
Definition of Done: один временный контейнер; последовательные независимые
процессы; fail-fast и cleanup; fingerprint; журнал времени/памяти/exit codes.
Work items: 1.1 тесты RED; 1.2 runner и Make/config GREEN.
Checks: Python regression suite, существующие shell/workflow-тесты.
Reviewer focus: stdin, исключения и сигналы, подготовка БД, полнота fingerprint.

## Stage 2: Полная приёмка и handoff
Risk: MEDIUM. stage_base_commit: после Stage 1.
Definition of Done: инструкция; холодный и два тёплых полных прогона на одном
fingerprint; свежий internal review; external review; Ready PR.
Work items: 2.1 окружение и три прогона; 2.2 review, отчёт и PR.
Checks: make site-accept (cs, strict types, stan, unit, integration, functional).
Reviewer focus: реальная воспроизводимость и честные ограничения измерений.

## Решения
- Две задачи выполняются последовательно в отдельных ветках/PR от master.
  Вторая не требует слияния первой; production не вызывается.
- Python 3 уже используется существующими workflow-тестами; новых зависимостей нет.
- Полные гейты входят в site-accept; отдельный дубль тех же команд не нужен.
