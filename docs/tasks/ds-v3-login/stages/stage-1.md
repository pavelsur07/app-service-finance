# Stage 1 — общее представление входа

Risk HIGH-LOCAL (auth UI). Base `92698d36a976d41500942ba58eda0206df7abd0c`.
Work items 1.1–1.3 DONE.

Сделано: shared AuthLayout/LoginForm, footer без фиктивных URL/реквизитов,
cookie180дней/event, spinner Button, auth InputGroup/eye18, валидация/focus,
loading/401, login вне AppShell; отдельная UI provenance-обёртка для expiry,
исключающая logout. Raw stores и safeReturnPath не изменены.

Тесты сначала: old login RED 6/10, old controls RED 2/36; expiry test RED до
создания нового UI adapter. После реализации account158/admin154 passed,
оба typecheck, оба штатных npm run lint (ESLint+Prettier) passed.
Focused login повторно12/12 на каждом app после проверки повторного 401.
Первый npm install упал из-за UID1000 вместо владельца1001: исправлены локальные
CONTAINER_UID/GID. Общий eslint account-config на двух apps дал 11 ложных path
ошибок, в том числе на старых тестах; правильные app-specific lint чистые.

Internal self-review: 1 проход полного Stage diff + фокусная проверка;
BLOCKER0/IMPORTANT0, MINOR1 — повторный одинаковый server error также инициирует
focus через номер ошибки. CompanyPicker и browser screenshots — следующие Stages.
External: Codex fresh read-only static packet (Claude CLI отсутствует), round1;
BLOCKER0/IMPORTANT1: expired recovery терял известный email и начальный focus password.
Подтверждено, исправлено: email только в памяти presentation, cleared logout/cancel;
LoginForm получает initialEmail и фокусирует пароль при expired. Regression сначала RED,
затем focused16/16 в обоих apps + оба typecheck GREEN. Fixed without re-run, по §7.2.
Второй self-review исправления: открытых BLOCKER/IMPORTANT нет.
Backend/PHP gates не применяются: site исключён явным поручением.
