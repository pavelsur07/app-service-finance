# Внешний доступ к заглушкам account/admin

Источник: поручение владельца 2026-11-11 после merge/deploy #2965.
Опубликовать существующие SPA по HTTPS:
- https://account.vashfindir.ru
- https://admin.vashfindir.ru

Владелец подтвердил домены и поручил найти Traefik в проекте. Он уже описан
в docker-compose.prod.yml: Docker provider, сеть traefik-public, entrypoints
http/https, certresolver letsEncrypt, middleware redirect-to-https.

В scope явно входят необходимые production Docker/Traefik labels и CI/CD:
сборка двух static nginx images, их проверки, публикация GHCR, штатный deploy.
Сама выкатка — после отдельного merge and deploy этого PR. Ручные production
команды не требуются. Рабочий app.vashfindir.ru сохраняет прежний маршрут.

Границы: текущие заглушки home/about/404, без авторизации, API proxy, backend,
бизнес-данных, миграций, workers или новых секретов/разрешений. Админский
поддомен на этом этапе обслуживает только публичную заглушку.

Приёмка: TLS/HTTP→HTTPS, home/about/reload/404, CSS/JS, мобильный/desktop,
отсутствие запросов данных, контейнеры healthy, независимые images по SHA,
настоящий nginx в browser smoke, отсутствие node_modules/исходников/secrets
в runtime и Docker context. Изменение app/shared CSS корректно запускает CI.

DNS: на baseline оба поддомена не разрешаются. Владелец ответил «сейчас добавлю»
на предложение CNAME account/admin → app.vashfindir.ru. Проверить перед handoff.
