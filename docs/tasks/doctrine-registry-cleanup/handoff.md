# Выпуск очистки реестра Doctrine

Миграция `Version20261119150000` удаляет четыре устаревшие строки реестра. Doctrine отдельно добавляет запись о выполненной миграции. Автоматического `down()` нет. Точное восстановление возможно из экспорта по отдельному согласованию.

## Перед выпуском

1. Повторить сверку через `codex-psql-ro` и зафиксировать результаты для конкретного `release_sha`. До merge код `master` должен давать ровно четыре утверждённые недоступные версии и ноль ожидающих. У итогового SHA выпуска ожидающая версия только одна — очистка реестра. Обе обязательные версии должны быть зарегистрированы, устаревших таблиц должно быть ноль. При расхождении остановить выпуск.
2. Сохранить из результата запроса массив `obsolete` в отдельный JSON-файл вне репозитория. Проверить точное множество четырёх имён и наличие полей `version`, `executed_at` и `execution_time` с исходными значениями, включая `null`. Каталог — `0700`, файл — `0600`. Сохранить и проверить `sha256sum` экспорта. Зафиксировать также `surviving_sha256` для сверки после релиза.
3. После согласования запустить штатный `production_action: release` с полным SHA из `master`. Workflow выполняет проверяемый полный backup PostgreSQL перед миграциями. Правила и команда запуска — [Production release](../../workflow/release.md).

## Снимок реестра

Запускать через `codex-psql-ro` по [канонической форме](../../maintenance/prod-access.md#канонические-формы-вызова) с выводом в защищённый локальный файл. Список `registered` сравнивать в обе стороны с классами `doctrine:migrations:list` в локальном checkout соответствующего SHA. У двух исторических классов после 14 цифр есть суффикс.

```sql
WITH obsolete(version) AS (
    SELECT 'DoctrineMigrations' || chr(92) || suffix
    FROM (VALUES ('Version20260114130328'), ('Version20260201090000'),
                 ('Version20260405000001'), ('Version20260405000002')) AS v(suffix)
)
SELECT jsonb_build_object(
    'registered', (SELECT coalesce(jsonb_agg(version ORDER BY version), '[]'::jsonb)
                   FROM doctrine_migration_versions),
    'obsolete', (SELECT coalesce(jsonb_agg(to_jsonb(m) ORDER BY m.version), '[]'::jsonb)
                 FROM doctrine_migration_versions m JOIN obsolete o USING (version)),
    'surviving_sha256', (SELECT encode(sha256(convert_to(jsonb_agg(to_jsonb(m) ORDER BY m.version)::text, 'UTF8')), 'hex')
                         FROM doctrine_migration_versions m
                         WHERE m.version NOT IN (SELECT version FROM obsolete)
                           AND m.version <> 'DoctrineMigrations' || chr(92) || 'Version20261119150000'),
    'legacy_relations', ARRAY(SELECT name FROM unnest(ARRAY[
        'bank_connection', 'bank_import_cursor', 'marketplace_raw_processing_step_runs',
        'balance_categories', 'balance_category_links'
    ]) AS t(name) WHERE to_regclass(name) IS NOT NULL)
)::text AS registry_audit;
```

## После выпуска

- Повторить снимок реестра. Сравнение с классами из выпущенного SHA должно дать ноль недоступных и ноль ожидающих версий. Проверить пустой массив `obsolete`, наличие обеих обязательных версий и точное совпадение `surviving_sha256` со снимком до релиза.
- Подтвердить успешный backup, итоговый SHA и здоровье сервисов по результатам release и read-only wrapper `codex-docker-ps`.
- При согласованном восстановлении вернуть из проверенного экспорта ровно четыре строки с исходными датами и длительностями в одной транзакции. Отдельный PR на строгий гейт `--fail-on-unregistered` готовится после успешной сверки.
