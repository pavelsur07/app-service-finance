# Hermes для MCP

Ветка `feat/mcp-hermes-client`. База `master`.

- Production-реестр добавляет `hermes` с callback `http://localhost:48767/callback`. Тест проверяет доставку всех трёх клиентских регистраций до PHP-FPM.
- На синтетической компании Hermes 0.23.3 прошёл OAuth, получил два инструмента чтения и подтвердил доступ повторным подключением. Для токена на чтение применено сужение `scope` перед согласием. [Инструкция подключения](../mcp-cash/oauth.md)
- Проверки релиза, deploy, frontend routing и PostgreSQL backup read прошли. [Этап 1](stages/stage-1.md) · [Этап 2](stages/stage-2.md)

Релиз выполняется штатной выкладкой по «Делай для hermes». Схема OAuth уже в production. Доступ к компании выдаёт её владелец.
