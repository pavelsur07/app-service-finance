# Активация OAuth MCP

Ветка `feat/mcp-oauth-production`. База `master`. [PR #3089](https://github.com/pavelsur07/app-service-finance/pull/3089)

- Workflow передаёт конфигурацию в PHP-FPM по SSH с закреплённым ключом сервера. Клиенты `codex-cli` и `claude-code` зарегистрированы на согласованных loopback callback. Пара RSA и ключ Defuse находятся в GitHub Actions Secrets.
- Локальные тесты release, deploy и Compose прошли. На локальном HTTPS-стенде с конфигурацией Nginx и PHP-FPM проверены 404 без ключей, оба discovery 200, challenge 401. Codex выполнил три инструмента, Claude Code получил каталог после OAuth. В production ещё предстоит проверить discovery после выпуска.
- Внутреннее ревью — 0 BLOCKER / 1 IMPORTANT. Внешнее ревью — 1 раунд, 0 BLOCKER / 3 IMPORTANT. Замечания закрыты. [Отчёт этапа 1](stages/stage-1.md) · [Отчёт этапа 2](stages/stage-2.md)
- Файлы выпуска — `.github/workflows/deploy.yml`, `docker-compose.prod.yml`, `docker/release/known_hosts`. [Подключение CLI](../mcp-cash/oauth.md)

Миграции MCP применены в #3088. Выпуск выполняется обычным merge + deploy. Доступ к данным по-прежнему выдаёт фактический владелец компании через кабинет.
