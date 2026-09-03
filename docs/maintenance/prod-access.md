# PROD-доступ из Claude Code

Правила доступа, список wrappers, read-only и мутирующие команды, логи на проде и
порядок добавления разрешений — `AGENTS.md`, «Production access».
Ниже — специфика запуска именно из Claude Code.

## Канонические формы вызова

Вызывать wrappers только в этих формах. Правила `permissions.allow` в `.claude/settings.local.json` написаны под них; любое отклонение — другой алиас, потерянный `sudo`, одинарные кавычки вместо двойных — под правило не подпадает и снова упирается в запрос разрешения.

```bash
ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-psql-ro -c \"SELECT ...;\"" < /dev/null
ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-docker-ps" < /dev/null
ssh -o BatchMode=yes vf-prod-codex "sudo /usr/local/bin/codex-console <allowed-command> ..." < /dev/null
```

Обязательные элементы формы:
- алиас ровно `vf-prod-codex`; `vf-prod` — root-доступ, он под `ask` и для работы агента не используется;
- `-o BatchMode=yes` — без интерактивных запросов пароля;
- удалённая команда в двойных кавычках, SQL внутри — в экранированных двойных;
- обязательный префикс `sudo` и полный путь `/usr/local/bin/...`;
- `< /dev/null` на конце — без закрытого stdin `codex-psql-ro` не отдаёт результат и вызов висит до таймаута;
- допускается префикс `timeout <ms|s>` перед `ssh`.

Если агент запущен в песочнице Bash, эти формы висят на connect молча: исходящий TCP глушится, и
вместо `Connection refused` не приходит ничего до таймаута. Признак именно песочницы, а не прода —
`ssh -o BatchMode=yes vf-prod-codex "true"` тоже не завершается. Лечится запуском конкретного
wrapper-вызова вне песочницы (в Claude Code — `dangerouslyDisableSandbox` у этого вызова Bash);
расширять права в `settings.local.json` для этого не нужно и нельзя.

Диагностику `ssh -vv` снимать в файл, а не в пайп: при срабатывании `timeout` буферизованный вывод
пайпа теряется и видно только `Terminated`. Вывод прогонять через
`sed -E 's/([0-9]{1,3}\.){3}[0-9]{1,3}/<PROD-IP>/g'` — production IP не печатать и не коммитить.

Модель разрешений: приоритет `deny > ask > allow`. Широкий паттерн в `ask` перекрывает любые `allow`, поэтому маски вида `Bash(*ssh*vf-prod*)` в `ask` запрещены — они накрывают и `vf-prod-codex`. Ограничения на стороне прода (непривилегированный пользователь, allowlist внутри wrapper, роль БД `codex_ro`) остаются единственной настоящей защитой; правила в settings только убирают лишние запросы.

Read-only проверки можно выполнять после запроса Владельца на PROD-проверку:
- Docker process/status через `codex-docker-ps`.
- Messenger stats через `codex-console messenger:stats`.
- Marketplace category status через `codex-console app:ingestion:marketplace-categories:status`.
- Ozon preview/verification через `codex-console`.
- Живость SMTP через `codex-console app:mailer:healthcheck` — SMTP handshake с AUTH, письмо не отправляется, данные не меняются. Мутирующего режима и флагов у команды нет. Учитывать: сбой пишет `error` и заводит issue в GlitchTip, поэтому ручной прогон при мёртвой почте создаёт алерт.
- Сверка строк разбивки ДДС через `codex-console app:cash:verify-transaction-splits` — только чтение, ненулевой exit code при расхождении. Wrapper запрещает этой команде любые аргументы.
- Read-only SQL через `codex-psql-ro` и роль БД `codex_ro`.

Перед выполнением команд, которые меняют данные, обрабатывают очереди, вызывают внешние API или меняют состояние приложения, нужно явное подтверждение Владельца непосредственно перед запуском:
- `messenger:consume`.
- Любая команда с `--execute`.
- `app:cash:backfill-transaction-splits --execute` — переносит категорию ДДС в строки разбивки. Wrapper допускает у неё только пустой список аргументов или ровно `--execute`, другие флаги отвергает. Без `--execute` команда read-only и только считает объём. Запускать в тихом окне с остановленными воркерами, после бэкапа `cash_transaction`, и сверять результат `app:cash:verify-transaction-splits`.
- `messenger:failed:remove <id> [--force]` — безвозвратно удаляет сообщение из failed-очереди. Wrapper требует первым аргументом только числовой id и допускает единственный дополнительный флаг `--force`; `--all` отвергается, поэтому очередь целиком снести нельзя. До удаления посмотреть на цель: класс сообщения и текст ошибки читаются через `codex-psql-ro` из `messenger_messages`. Удаление выбрасывает задачу, а не выполняет её.
- Repair, prune, backfill, rebuild, refresh, maintenance.
- SQL write (`INSERT`, `UPDATE`, `DELETE`, DDL, migrations).
- Изменения production Docker, workers, scheduler, queues, secrets, config, deploy.

## Настройка алиаса на новой машине

Алиас `vf-prod-codex` живёт только в `~/.ssh/config` конкретной машины и в репозиторий не попадает.
Без него все формы выше падают ещё до аутентификации. Проверено 2026-09-03 на машине агента.

Блок конфига (IP и ключ не коммитить):

```
Host vf-prod-codex
    HostName <PROD-IP>
    User codex-prod
    IdentityFile ~/.ssh/vf-prod-codex
    IdentitiesOnly yes
```

Обязательно:
- пользователь ровно `codex-prod` — sudoers-правило `/etc/sudoers.d/codex-prod` выдано именно ему.
  Пользователь `codex` на проде не существует; если завести его, вход по ключу заработает, а
  `sudo` будет просить пароль, и wrappers останутся недоступны;
- ключ — отдельная пара `~/.ssh/vf-prod-codex` с правами `600`, не личный `id_ed25519`;
- `IdentitiesOnly yes`, чтобы ssh не перебирал чужие ключи и не упирался в лимит попыток.

Агент работает только через `vf-prod-codex`. Root-алиас `vf-prod` для работы агентов не
используется ни в каком виде: ни для проверок, ни для настройки, ни для «одной быстрой команды».
Он существует для Владельца; в `settings.local.json` он под `ask`, и агент не запрашивает на него
разрешение. Публичный ключ в `authorized_keys` пользователя `codex-prod` добавляет Владелец из
своего терминала. Команда для него:

```bash
ssh vf-prod 'H=$(getent passwd codex-prod | cut -d: -f6) && mkdir -p "$H/.ssh" && cat >> "$H/.ssh/authorized_keys" && chown -R codex-prod:codex-prod "$H/.ssh" && chmod 700 "$H/.ssh" && chmod 600 "$H/.ssh/authorized_keys"' < ~/.ssh/vf-prod-codex.pub
```

Проверка настройки — одна команда, она же первый вызов в сессии перед любой PROD-проверкой:

```bash
timeout 20 ssh -o BatchMode=yes vf-prod-codex "sudo -n /usr/local/bin/codex-docker-ps" < /dev/null
```

Диагностика по тексту ошибки — они различимы, лечить наугад не нужно:

| Симптом | Причина | Кто чинит |
|---|---|---|
| `Could not resolve hostname vf-prod-codex` | Блока `Host vf-prod-codex` нет в `~/.ssh/config` | Владелец на машине агента |
| `Permission denied (publickey,password)` | Ключ не прописан в `authorized_keys` у `codex-prod` | Владелец на проде |
| `sudo: a password is required` | Вход под другим пользователем, sudoers выдан `codex-prod` | Поправить `User` в алиасе |
| Висит до таймаута, `"true"` тоже висит | Песочница Bash глушит исходящий TCP | Вызов вне песочницы, см. выше |
| Висит только `codex-psql-ro` | Потерян `< /dev/null` | Поправить форму вызова |

Отказ по любой строке — не STOP-условие: доделать остальное и отдать Владельцу точную команду.

## Добавление PROD-разрешений

Если нужной команды нет в wrapper:
1. Описать точную production operation и зачем она нужна.
2. Классифицировать: read-only, mutating/processing, dangerous/general.
3. STOP и получить отдельное одобрение Владельца на изменение production permission. По умолчанию изменение применяет Владелец/DevOps; Codex меняет wrapper только по явному поручению для этой точной операции.
4. Allowlist должен проверять точное имя команды и допустимые аргументы/флаги; одного имени недостаточно, если команда имеет read-only и mutating режимы.
5. Не расширять sudoers и не выдавать прямой Docker-доступ.
6. Проверить wrapper: `bash -n /usr/local/bin/codex-console`.
7. Проверить от restricted user: `sudo -u codex-prod sudo /usr/local/bin/codex-console <command> --help` или другой безопасный read-only вызов.
8. Durable policy changes фиксировать в `AGENTS.md` и `CLAUDE.md`; временные one-off разрешения не документировать как постоянные.

Запрещено добавлять dangerous/general permissions: arbitrary shell, arbitrary `docker exec`, unrestricted `docker`, write-capable `psql`, file editing on production, package installation. Для one-off production writes предпочтителен временный narrowly scoped wrapper, который Владелец удаляет после использования.

---

