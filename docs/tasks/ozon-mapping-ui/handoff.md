# Ozon mapping UI — handoff

Branch fix/ozon-mapping-ui; PR https://github.com/pavelsur07/app-service-finance/pull/2830
Base c5485f18f; Stage1 a2ff06e8c; Stage2 c5af0617b.

## Scope / результат
Только экран настроек статей Ozon и необходимые handlers. Понятные labels до/после
скидки; source descriptions; отсутствие технических кодов в Ozon table/default modal;
единый конфликт, кнопка с перечнем изменений и подтверждением; monthly status.
Repair POST требует write+company-scoped CSRF, проверяет replacement на той же статье,
отключает только конфликтующие legacy. Lock общий с save Ozon action, повтор идемпотентен.
Save guard create/edit/toggle запрещает конфликт и неподходящий source на стандартной
статье с СПП. WB/остальные paths прежние. GET read-only.
Не менялись другие report pages, формулы, категории, документы истории, production config.

## Проверки
Baseline15/114; regression red5 before backend, green23/136; UI title red before label change.
Module62 tests/264 assertions; final UI12 tests/52 assertions.
Focused PHPStan/CS green; Twig4 files green; frontend lint/build/API types green.
Actual default-modal script smoke conflict/normal/error, no source codes, button/loading/sign
states green. BrowserKit DOM checks confirmation, labels, GET no writes, WB unchanged.
Реальный browser visual smoke не выполнен; новых зависимостей/entry/CSS нет.
Full make gates PASS: site-stan 0 errors; site-cs-check 0/3435 fixes;
site-cs-strict-types 0/3435 fixes; site-test-unit 4248 tests/24515 assertions.
Full make site-test PASS: 7603 tests/43343 assertions before post-review minor fixes;
post-review affected module64 tests/273 assertions and focused PHPStan/CS passed.
Installed local deps reused with -o site-composer-install to preserve shared owner vendor.
No PHPStan baseline/suppressions modified.

## Review
Fresh internal complete diff review: REVIEW_GREEN, B/I/M/F=0/0/0/0.
External Claude round1 REVIEW_GREEN; B/I=0/0; MINOR4 fixed without rerun:
shared readiness predicate; deactivated-rule flash preserved; neutral unavailable-repair hint;
positive valid-save/toggle tests. Notification regression proven red before fix.
Post-review: module64 tests/273 assertions, focused PHPStan/CS green;
Twig + actual JS smoke green; fresh internal follow-up REVIEW_GREEN 0 findings.

## Production / delivery
Production не изменён. Merge/deploy только после нового решения владельца этой задачи.
После deploy repair выполняется только после подтверждения кнопки пользователем
на экране настроек Ozon, не при загрузке страницы и не автоматически на deploy.
Миграций, новых зависимостей и изменений CI/CD нет. Rollback PR #2828 не трогали.

## Owner decision
Ready PR #2830, base master. Merge + deploy requires owner approval for this task.
After deployment: Настройка статей Ozon → Исправить двойной учёт → Применить исправление.
Это отдельный явный click пользователя; deployment itself never changes mapping rows.
