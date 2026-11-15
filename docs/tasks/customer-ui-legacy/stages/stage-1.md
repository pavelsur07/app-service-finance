### Stage 1: единый backend-режим — DONE

**Risk:** HIGH-LOCAL. **Stage base:** `0658c9fc6a3c4c3519f6e254d6669200b6a38753`.
**Work items:** 1.1, 1.2.

- `/finance` всегда использует `home/index`; выбранный период и KPI сохранены.
- Cookie app/vf не включают новый UI; совместимый POST принимает прежние
  допустимые значения, выставляет legacy. CSRF и same-origin redirect сохранены.
- Test helper научен создавать CSRF в HTTPS namespace: необязательный URI,
  поведение всех прежних вызовов без изменений.

**Tests:** regression red — 7 ожидаемых legacy-vs-app failures плюс ошибка
HTTPS fixture, затем исправлена (Symfony разделяет HTTP/HTTPS namespace).
Целевые проверки — OK: 25 tests, 1311 assertions. Модульный набор
`tests/Functional/Finance`, UiModeControllerTest, UiModeResolverTest — OK:
209 tests, 2922 assertions. Focused PHPStan — no errors. Focused fixer —
0/8 files. `git diff --check` — OK.

**Tool corrections:** первый fixer запуск требовал --config для нескольких
путей; следующий не смог писать default cache; с проектным cache-file — OK.
Проверки не объявлялись зелёными до успешного результата.

**Internal review:** полный Stage diff; BLOCKER 0, IMPORTANT 0. Проверены
CSRF, cookie flags, redirect, company scope, периоды и отсутствие изменения формул.
**External review:** на этом Stage не требуется: diff <500 строк; на handoff обязателен.

**Next:** Stage 2 автоматически; убрать переключатель из legacy-shell и перенести API UI.
