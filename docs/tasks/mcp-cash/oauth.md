# Подключение Codex CLI, Claude Code и Hermes

Пользовательский MCP работает на каноническом HTTPS-адресе кабинета по пути `/mcp`. Для выдачи доступа владелец входит в обычный кабинет ВашФинДир и выбирает компанию на странице согласия. В [подключениях MCP](/integrations/mcp) владелец видит свои разрешения и может сразу их отозвать.

## Настройка на стенде

| Переменная | Значение |
| --- | --- |
| `MCP_OAUTH_ORIGIN` | Канонический HTTPS origin приложения, без пути и завершающего слеша |
| `MCP_OAUTH_PRIVATE_KEY` | Закрытый ключ RSA в PEM или доступный PHP абсолютный путь к файлу |
| `MCP_OAUTH_PUBLIC_KEY` | Парный открытый ключ RSA в PEM или доступный PHP абсолютный путь к файлу |
| `MCP_OAUTH_ENCRYPTION_KEY` | Ключ Defuse в ASCII-safe формате, создаётся штатным `generate-defuse-key` |
| `MCP_OAUTH_CLIENTS` | JSON-словарь публичных client ID со списком точных loopback или HTTPS callback URL |

Пример регистраций CLI. Порты относятся к машине, на которой запущен клиент.

```json
{
  "codex-cli": ["http://127.0.0.1:48765/callback"],
  "claude-code": ["http://localhost:48766/callback"],
  "hermes": ["http://localhost:48767/callback"],
  "hermes-web": ["https://hermes.example.test/api/mcp/oauth/callback/vashfindir"]
}
```

Для стенда применяются миграции `Version20261121103000` и `Version20261121110000`. Секреты хранятся в конфигурации окружения. Пока обязательные настройки отсутствуют, маршруты MCP и OAuth отвечают 404. Запросы привязаны к origin из конфигурации. Для публичных клиентов используются заранее зарегистрированный redirect URI и PKCE S256.

## Production

Канонический адрес — `https://app.vashfindir.ru/mcp`. Для production в примерах ниже используйте хост `app.vashfindir.ru`. Client ID и callback совпадают со списком выше.

Production-выкладка передаёт параметры MCP только в PHP-FPM. В GitHub Actions Secrets используются имена `MCP_OAUTH_PRIVATE_KEY`, `MCP_OAUTH_PUBLIC_KEY` и `MCP_OAUTH_ENCRYPTION_KEY`. Пара RSA хранится в PEM. Ключ Defuse хранится в ASCII-safe формате. Адрес и список публичных клиентов заданы в workflow. При передаче конфигурации проверяется закреплённый SSH-ключ production-хоста. При смене хост-ключа его обновляют в `docker/release/known_hosts` после сверки по доверенному каналу.

Пустой набор из трёх ключей отключает маршруты MCP и OAuth. При неполном наборе выкладка останавливается до передачи конфигурации. После включения проверяются оба документа discovery и ответ 401 с OAuth challenge на запрос к `/mcp` без токена. Для доступа к данным владелец компании завершает согласие в кабинете.

## Codex CLI 0.171.0

Задайте параметры callback на верхнем уровне `config.toml` Codex.

```toml
mcp_oauth_callback_port = 48765
mcp_oauth_callback_url = "http://127.0.0.1:48765/callback"
```

Подставьте адрес стенда. Команда зарегистрирует сервер и откроет вход OAuth.

```bash
codex mcp add vashfindir --url https://app.example.test/mcp \
  --oauth-client-id codex-cli
```

В стендовом прогоне Codex 0.171.0 взял `resource` из discovery и успешно обменял код на токен. Добавление `--oauth-resource` в этой версии привело к повтору параметра и HTTP 400. Codex также сверяет `iss` в OAuth callback с issuer из discovery. [Исходный код Codex CLI 0.171.0](https://github.com/openai/codex/blob/rust-v0.171.0/codex-rs/rmcp-client/src/oauth_callback.rs)

## Claude Code 2.1.326

```bash
claude mcp add --transport http --callback-port 48766 --client-id claude-code \
  vashfindir https://app.example.test/mcp
claude mcp login vashfindir --no-browser
```

Claude Code использует `http://localhost:PORT/callback` для фиксированного порта и принимает публичный client ID без секрета. [Документация Claude Code](https://code.claude.com/docs/en/mcp#use-pre-configured-oauth-credentials)

## Hermes Web Dashboard

[Инструкция по подключению в веб-панели Hermes](hermes-web.md) описывает настройку профиля, вход кнопкой **Authenticate** и диагностику ошибки `invalid_request`.

## Hermes Agent 0.23.3 с локальным callback

Добавьте запись в `~/.hermes/config.yaml` в раздел `mcp_servers`. Параметр `tools.include` оставляет модели два инструмента чтения.

```yaml
mcp_servers:
  vashfindir:
    url: https://app.vashfindir.ru/mcp
    auth: oauth
    oauth:
      client_id: hermes
      redirect_host: localhost
      redirect_port: 48767
    tools:
      include: [list_reference_items, list_cash_transactions]
```

```bash
hermes mcp login vashfindir
```

Hermes 0.23.3 запрашивает разрешения из OAuth discovery, включая создание черновиков. Для подключения только на чтение скопируйте исходную ссылку OAuth из терминала и замените в ней только параметр `scope` на `scope=accounts.read%20cash_categories.read%20cash_transactions.read`. Откройте изменённую ссылку в браузере, пока команда входа ожидает ответ.

Войдите как владелец компании, выберите компанию и убедитесь, что на экране согласия запрошены только три права чтения. После подтверждения Hermes получит токен с этими правами. Для запуска Hermes на удалённой машине воспользуйтесь описанным в документации способом возврата OAuth. [Документация Hermes](https://hermes-agent.nousresearch.com/docs/user-guide/features/mcp#oauth-authenticated-http-servers) · [Вход с удалённой машины](https://hermes-agent.nousresearch.com/docs/guides/oauth-over-ssh)

## Проверка

- Вход с аккаунтом фактического владельца открывает согласие. Компания без тарифа недоступна. Для статуса «только чтение» запросите только scopes с окончанием `.read`.
- После согласия клиент видит только инструменты, соответствующие scopes. Черновик создаётся с `cash_drafts.create` и требует отдельного подтверждения в разделе ДДС.
- Отзыв в кабинете закрывает доступ по текущему bearer-токену и по refresh token. Для повторного доступа нужен новый вход.
- Живая проверка Codex CLI через `app-server` проходит OAuth, все три инструмента, повтор черновика, подтверждение владельцем, чтение одной проведённой операции с `importSource=mcp` и отмену второго черновика.
- Живая проверка Claude Code проходит OAuth и получение трёх инструментов через `server/discover` и `tools/list`. Полный финансовый сценарий покрыт интеграционными HTTP-тестами Symfony и прогоном Codex CLI.
- Для диагностики discovery и схем можно использовать MCP Inspector. Регрессию транспорта и доменных правил проверяют автоматические тесты, для совместимости используются закреплённые версии CLI. [MCP Inspector](https://php.sdk.modelcontextprotocol.io/get-started/inspector/)
