# Удаление файлов сверки в объектном хранилище

Владелец одобрил удаление 2026-09-13. **Агент выполнить не может** — и это не
обходится: на проде драйвер `s3` (Timeweb, `docker-compose.prod.yml:48-56`),
ключи живут в GitHub Secrets; команды удаления произвольного пути в приложении
нет (`StorageHealthCheckCommand` удаляет только свой probe-ключ), а в allowlist
`codex-console` — только read-only команды. Добавление команды или wrapper'а —
расширение прав (AGENTS.md §3.3) и отдельный PR ради удаления 12 файлов.

Запускает Владелец. Порядок относительно миграции уже не важен: список путей
зафиксирован в `stage-2-prod-snapshot.md`, дроп таблицы его не уничтожит.

## Предусловия — выполнены

- 12 объектов, все под `marketplace/reconciliation/ozon/`.
- Сверка с «Закрытием месяца»: `0 rows` — ни один файл не используется
  сохранённой фичей. Запрос и результат — `stage-2-prod-snapshot.md`.
- Префикс удалять **нельзя**: в него пишет `ReconcileCostsAction`. Удаляются
  только перечисленные 12 ключей.

## Команда (одним вызовом, 12 точных ключей)

Требует `APP_OBJECT_STORAGE_S3_ACCESS_KEY` / `..._SECRET_KEY` в окружении как
`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`.

```bash
cat > /tmp/recon-delete.json <<'JSON'
{"Objects":[
{"Key":"marketplace/reconciliation/ozon/2026-01/5150c989-8ee4-43e5-92cc-4ef63da4343a.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/f1c7aff4-dedc-4422-9bfa-c9beddc6839f.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/f03db83b-04b7-4b48-8115-be71cf048caf.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/73fb7983-e6e2-4c8f-9317-54ce251f775d.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/3efdb154-c267-469f-90f9-fd3eef49639b.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/2ae9c389-aeba-4499-b018-0969874310e1.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/9cf33dca-9b1a-4dc2-80ad-2926c3eaf5b2.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/a0209fba-8f7f-43cb-a24c-5d409360a260.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/7366e8fe-8c9d-4e4f-9531-6c6ae77f5959.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/998ee518-e13f-479f-986d-9fa1b47e2db0.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-01/ddedd36d-1cdf-420c-b091-fee379280469.xlsx"},
{"Key":"marketplace/reconciliation/ozon/2026-02/829caac4-26e8-46c1-92bc-3f131fdd2658.xlsx"}
],"Quiet":false}
JSON

aws --endpoint-url https://s3.twcstorage.ru --region ru-1 \
  s3api delete-objects \
  --bucket ccd24eb8-1c82-4de4-bd69-9706a7b5443b \
  --delete file:///tmp/recon-delete.json

rm -f /tmp/recon-delete.json
```

Ожидаемый вывод: блок `Deleted` с 12 ключами, блок `Errors` отсутствует.

## Проверка после удаления

```bash
aws --endpoint-url https://s3.twcstorage.ru --region ru-1 \
  s3 ls s3://ccd24eb8-1c82-4de4-bd69-9706a7b5443b/marketplace/reconciliation/ --recursive
```

Должны остаться только файлы «Закрытия месяца» — те, чьи пути встречаются в
`marketplace_month_closes.settings -> 'costs_reconciliation' ->> 'file_path'`.
Любой объект, которого нет ни там, ни в списке из 12, — сирота окна до деплоя
(маловероятно: последняя загрузка в отчёт была 11.04.2026).
