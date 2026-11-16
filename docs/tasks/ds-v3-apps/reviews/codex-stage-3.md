# Stage 3 — separate external Codex review

2026-11-16; base18fa02478; branch feat/ds-v3-stage-3.
Claude CLI отсутствует; автоматический fallback по AGENTS §7.2. Reviewer —
отдельная ephemeral read-only сессия Codex, effort medium, timeout900.
Штатный site/bin wrapper не охватывает apps; передан полный diff с новыми
файлами/lock + исходные Controls/Data/Feedback/ds-kit.css и task/checklist
из Git3882c5dd9. Никакой истории реализации/секретов/production данных.

Ограничения: stdin-only static review; bwrap shell недоступен (подтверждено
ранее в этой задаче). Инструменты запрещены, независимого запуска тестов нет.
Пакет/вывод локально: /tmp/vf-ds-v3-stage3/external.

## Раунд 1 — BLOCKER0, IMPORTANT1

> IMPORTANT — Toast рендерится в обычном потоке; позиционирование top-right
> и контейнер стека отсутствуют. Уведомление может оказаться вне viewport
> и исчезнуть через4s незамеченным.

Подтверждено: browser regression RED, top891.83px вместо24px.
Исправлено: экспорт ToastStack, один контейнер у root приложения вне
transform/scroll panels; fixed top/right24px, gap10px, z-toast200,
max-width viewport; все fixture Toast внутри stack. Пустой stack не создаёт
пустой region. README содержит обязательную композицию.

Проверено: browser12PASS (включая top24/right24/z200), SSR empty stack;
focused fresh internal review INTERNAL_REVIEW_GREEN, новых B/I0/0.
Результат: **fixed without re-run** — подтверждённый IMPORTANT исправлен
и проверен, BLOCKER не было, повтор по §7.2 не требуется. CLI не выдавал
REVIEW_GREEN; зелёный статус по правилу исправлений, а не по marker.

## Internal review

Независимая свежая session: только spec/full diff/checklist. B/I0/0,
MINOR1 Toast tone border/icon background; исправлен и проверен4tone Chromium
assertions. Два focused check после runtime fixes и внешнего finding — GREEN.

Review metrics: internal BLOCKER/IMPORTANT0/0, MINOR2fixed; external
BLOCKER/IMPORTANT0/1, confirmed IMPORTANT1fixed, open0, rounds1.

Финальный author MINOR: custom InputGroup action нормализован до26px по
листу; browser RED36→GREEN26, suite12PASS; targeted types/lint PASS.
