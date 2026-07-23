## Управление тегами листингов — DONE

**Риск:** 🟡 MEDIUM (новый UI + мутирующие операции над тегами, всё company-scoped, локально)
**Ветка:** `codex/listing-tags-management` (от `38979936`)
**Следующее действие:** Release Gate — ждать решения Владельца (merge/deploy)

### Что сделано

Справочник управления тегами — закрывает пробел «теги копятся, их нельзя чистить».

- Страница **`/marketplace/listings/tags`** — список тегов компании со **счётчиком листингов** под каждым.
  Ссылка «Управление тегами» добавлена в шапку реестра листингов.
- **Переименование** — inline-поле; slug пересчитывается; коллизия с другим тегом → 409 с подсказкой про слияние.
- **Удаление** — confirm с предупреждением «снимется с N листингов»; FK `ON DELETE CASCADE` чистит связи.
- **Слияние** (главное средство от накопленного) — dropdown «Слить в…»: все листинги источника
  перевешиваются на цель (`INSERT … SELECT … ON CONFLICT DO NOTHING`), источник удаляется.

### Важное наблюдение по семантике мусора

Одинаковый slug в одной компании **невозможен** (уникальный индекс `(company_id, slug)` + slugify при
создании). Значит варианты регистра/пробелов («Зима»/«зима»/«ЗИМА ») в дубли не расползаются — они
схлопываются ещё при создании. Реальный мусор, который чинит слияние, — это **разные названия одного
смысла** («Зима» vs «Зимняя коллекция») и опечатки, а также брошенные теги (0 листингов) под удаление.

### Затронутые файлы

- `src/Marketplace/Entity/MarketplaceListingTag.php` — вернул `rename()` (в Stage 1 удалял как мёртвый; теперь используется)
- `src/Marketplace/Repository/MarketplaceListingTagRepository.php` — `remove()`
- `src/Marketplace/Infrastructure/Query/ListingTagAssignmentRepository.php` — `countsByTag()`, `reassign()`
- `src/Marketplace/Application/{Rename,Delete,MergeListingTags}Action.php` — new
- `src/Marketplace/Exception/ListingTagNameConflictException.php` — new
- `src/Marketplace/Controller/ListingTagsManageController.php` — new (HTML)
- `src/Marketplace/Controller/Api/ListingTag{Rename,Delete,Merge}Controller.php` — new
- `templates/marketplace/listings/tags.html.twig` — new; `index.html.twig` — ссылка
- `tests/Functional/Marketplace/Controller/ListingTagsManageTest.php` — new (9 тестов)
- `tests/Unit/Marketplace/Entity/MarketplaceListingTagSlugTest.php` — +2 теста на rename

### Self-review

- [x] IDOR — rename/delete/merge через `findById(companyId, tagId)`; `countsByTag`/`reassign` фильтруют по `company_id` в SQL. Тесты на чужой тег → 404, чужой не тронут.
- [x] `flush()` только в Action; репозитории не флашат
- [x] Merge: `ON CONFLICT DO NOTHING` (нет дублей на цели), каскад чистит источник — тест на 3 листинга без дублей
- [x] Роуты не конфликтуют с `tags/assign|detach` (rename/delete — `{tagId}/…`, merge — статический сегмент)
- [x] Формат ошибок `{"error":{"code","message"}}`; 422/409/404 по смыслу
- [x] cs-fixer (точечно) — чисто; twig lint — OK; `schema.d.ts` — в синхроне (новые контроллеры без OA)
- [x] `make site-test` (свежая БД) — зелёный (2602 теста)

### External Claude Code review

- N/A — реализацию выполнял Claude Code; проведён полный внутренний review.

### Риски

- **CSRF на POST-эндпоинтах не проверяется** — консистентно с остальными tag-эндпоинтами и `ListingMappingController`.
- **Удаление и слияние необратимы** — есть confirm() в UI; undo не предусмотрен (вне scope).
- Слияние >2 тегов за раз не поддержано — делается повтором (вне scope).

### Открытые вопросы
- нет
