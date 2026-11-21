# Как подключить ВашФинДир в веб-панели Hermes

Для панели `hermes.vashfindir.com`. Если в разделе MCP уже есть `vashfindir` с клиентом `hermes-web`, начните с входа. Выдать доступ может фактический владелец компании с действующим тарифом.

## Войти и проверить подключение

Вход в ВашФинДир и согласие выполняет владелец в своём браузере. Если нужен email или пароль, введите их на странице входа с адресом `app.vashfindir.ru`.

1. В своём браузере войдите в [ВашФинДир](https://app.vashfindir.ru/) под учётной записью владельца компании.
2. В другой вкладке того же браузера откройте [веб-панель Hermes](https://hermes.vashfindir.com/mcp). В карточке `vashfindir` нажмите **Authenticate** (значок ключа).
3. Перейдите в открывшуюся вкладку ВашФинДир, выберите компанию, проверьте список запрошенных разрешений и нажмите **Разрешить доступ**.
4. Когда появится **Authorization received**, вернитесь на вкладку Hermes. Дождитесь **OAuth authentication complete**. Кнопка **Test connection** (значок молнии) покажет доступные инструменты.

Hermes может запросить чтение справочников и операций ДДС, а также создание черновиков. Список прав виден перед подтверждением. Черновик становится операцией после отдельного подтверждения в ДДС. Доступ можно отозвать в [подключениях MCP ВашФинДир](https://app.vashfindir.ru/integrations/mcp).

## Настроить профиль Hermes один раз

Если записи ещё нет или она использует старый клиент `hermes`, администратор добавляет или обновляет эти поля в конфигурации нужного профиля. Выберите тот же профиль в веб-панели.

```yaml
mcp_servers:
  vashfindir:
    url: https://app.vashfindir.ru/mcp
    auth: oauth
    oauth:
      client_id: hermes-web
```

При обновлении старой записи удалите из неё `oauth.redirect_host`, `oauth.redirect_port` и `oauth.redirect_uri`. Остальные настройки профиля сохраните.

Hermes может подготовить настройки по одному сообщению в чате. После сохранения владелец продолжает вход сам в браузере.

> В текущем профиле добавь или обнови MCP-запись vashfindir для URL https://app.vashfindir.ru/mcp. Установи auth: oauth и oauth.client_id: hermes-web. Удали из этой записи oauth.redirect_host, oauth.redirect_port и oauth.redirect_uri. Остальные настройки сохрани. После сохранения сообщи о результате. Авторизацию я начну кнопкой Authenticate в веб-панели.

У развёртывания Hermes должен быть настроен вход в веб-панель. Публичный адрес `https://hermes.vashfindir.com` задаётся через `dashboard.public_url` или `HERMES_DASHBOARD_PUBLIC_URL`. Переменная окружения имеет приоритет. Панель строит адрес возврата из публичного адреса и имени MCP-записи. Код попадает в тот экземпляр Dashboard, который начал вход. [Обработчик кнопки в Hermes 0.23.3](https://github.com/NousResearch/hermes-agent/blob/v0.23.3/web/src/pages/McpPage.tsx#L185-L203) · [OAuth-обработчик Dashboard](https://github.com/NousResearch/hermes-agent/blob/v0.23.3/hermes_cli/web_routers/mcp.py#L254-L296)

## Если возникла ошибка

| Что видно | Что проверить |
| --- | --- |
| **Reading the tool list** | Это статус чтения списка инструментов в Hermes Desktop. Для веб-входа откройте `hermes.vashfindir.com/mcp` в своём браузере и нажмите **Authenticate**. Перейдите во вкладку ВашФинДир, которая открылась после нажатия. [Интерфейс Hermes Desktop 0.23.3](https://github.com/NousResearch/hermes-agent/blob/v0.23.3/apps/desktop/src/i18n/en.ts#L241-L247) |
| **OAuth popup was blocked** | Разрешите всплывающие окна для `hermes.vashfindir.com` и нажмите **Authenticate** ещё раз. [Обработка всплывающего окна в Hermes](https://github.com/NousResearch/hermes-agent/blob/v0.23.3/web/src/lib/mcp-dashboard-oauth.ts#L24-L46) |
| `invalid_request`, адрес возврата начинается с `127.0.0.1` или `localhost` | Закройте эту попытку и начните новую кнопкой **Authenticate** в браузерной панели. Если после нажатия кнопки адрес снова локальный, администратору нужно сверить активный профиль и его настройки OAuth с примером выше. |
| `invalid_request` с HTTPS-адресом | ВашФинДир ожидает точный адрес `https://hermes.vashfindir.com/api/mcp/oauth/callback/vashfindir`. Проверьте имя записи `vashfindir`, клиент `hermes-web` и публичный адрес панели. Для другого домена администратор регистрирует его точный HTTPS-адрес в `MCP_OAUTH_CLIENTS` ВашФинДир. |
| На экране согласия нет нужной компании | Проверьте, что в ВашФинДир вошёл фактический владелец компании и у компании действует тариф. |
| **OAuth flow expired** | Вернитесь в Hermes и снова нажмите **Authenticate**. Продолжите вход по только что открытой вкладке. |

Для диагностики достаточно названия профиля, версии Hermes, текста ошибки и значения `redirect_uri`. Полную ссылку OAuth не пересылайте.
