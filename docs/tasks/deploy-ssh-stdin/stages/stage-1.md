# Этап 1 — деплой обрывается после ozon-fetcher, воркер wb-finance не обновляется

Ветка `fix/deploy-ssh-stdin-wb-finance`, дата 2026-10-20. Одобрение Владельца
на правку CI/CD: «почини воркер wb-finance в деплое».

## 1. Какую задачу решаем

После деплоя #2720 (19.10) `site-messenger-worker-wb-finance` на проде работал на
образе `62c34c1f` (#2705, 17.10), остальные сервисы — на `116c93928`.

Причина подтверждена логом job `deploy` (run 39304545996): вывод заканчивается
на `✅ ozon-fetcher healthy`, строк `[5/5]`, SMTP-пробы, `docker image prune` и
`✅ Деплой завершён` нет. Job `deploy` при этом зелёный.

Скрипт деплоя передаётся на сервер как `ssh … << 'EOF'`, удалённый bash читает
команды из stdin. `docker compose run --rm -T ozon-fetcher -migrate` наследует
этот stdin и выедает остаток скрипта. Bash к этому моменту уже прочитал блок
`if … fi` целиком, поэтому `up --wait ozon-fetcher` выполняется, а всё, что
идёт после `fi`, — нет. Началось с включения ozon-fetcher (#2706, 17.10).

С 17.10 при каждом деплое пропускалось:

- `[5/5] up -d --remove-orphans` — единственный шаг, обновлявший
  `site-messenger-worker-wb-finance`: в явном списке шага [4/5] его нет;
- SMTP-проба `app:mailer:healthcheck`;
- `docker image prune -af --filter until=336h` (inode-ы хоста подстраховывает
  ночная retention).

Тот же дефект есть в job `migrations`: после `run -T … migrate` строка
`✅ Миграции применены` не печатается. Миграции применяются, теряется только
вывод.

## 2. Что будет результатом

- В `.github/workflows/deploy.yml` у каждого `docker compose run|exec` внутри
  `ssh … << 'EOF'` stdin отвязан (`< /dev/null`) или явно перенаправлен из
  файла.
- `site-messenger-worker-wb-finance` — в явном списке шага [4/5] наравне с
  остальными воркерами, а не только в [5/5].
- Регрессионный тест `tests/shell/deploy-workflow-test.sh` (цель
  `make test-deploy-workflow`) проверяет оба инварианта: красный на старом
  `deploy.yml`, зелёный на новом.

## 3. Что считаем выполненной работой

PR в `master` с правкой workflow и тестом, CI зелёный. После «merge and
deploy» в логе job `deploy` есть `[5/5]`, SMTP-проба и `✅ Деплой завершён без
даунтайма`; `codex-docker-ps` показывает `site-messenger-worker-wb-finance`
на теге merge-коммита.

## 4. Чек-лист приёмки

- [ ] Тест красный на `master` (старый `deploy.yml`) и зелёный на ветке
- [ ] Все `docker compose run|exec` внутри ssh-heredoc отвязаны от stdin
- [ ] wb-finance в списке [4/5]; других сервисов приложения вне [1/5]–[4/5] нет
- [ ] YAML валиден (парсер), `actionlint` без новых замечаний
- [ ] В коммите только файлы задачи
- [ ] CI PR зелёный
- [ ] После деплоя: лог содержит `[5/5]` и `✅ Деплой завершён без даунтайма`
- [ ] После деплоя: wb-finance healthy на теге merge-коммита
- [ ] Итог дописан ниже

## Итог

_заполняется по завершении_
