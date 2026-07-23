# Управление тегами листингов — план

Продолжение задачи `listing-tags`. Пробел: теги можно только навесить/снять, нельзя
переименовать, удалить, слить дубли → мусор копится. Делаем справочник управления.

```yaml
risk: MEDIUM       # новый UI + мутирующие операции над тегами, всё company-scoped, локально
owner_gate: no
```

## Definition of Done

- [ ] Страница `/marketplace/listings/tags` — список тегов компании со **счётчиком листингов** под каждым.
- [ ] **Переименование** тега (slug пересчитывается; коллизия с другим тегом → 409 с подсказкой про слияние).
- [ ] **Удаление** тега (FK `ON DELETE CASCADE` снимает связи; предупреждение, если тег на N листингах).
- [ ] **Слияние** тега в другой (все листинги источника перевешиваются на цель, источник удаляется).
- [ ] Все операции company-scoped в SQL/через `findById(companyId,...)`; чужой тег не тронуть.
- [ ] Ссылка на справочник с реестра листингов.
- [ ] Целевые тесты + `make site-test` зелёные.

## Внутри модуля Marketplace — Facade не нужен (потребитель тот же модуль).

## Реализация

**Repository:**
- `MarketplaceListingTagRepository::remove(tag)` — `em->remove`.
- `ListingTagAssignmentRepository::countsByTag(companyId): array<tagId,int>` — `GROUP BY tag_id`.
- `ListingTagAssignmentRepository::reassign(companyId, sourceTagId, targetTagId): int` —
  `INSERT … SELECT listing_id, :target … WHERE company_id AND tag_id=:source ON CONFLICT DO NOTHING`.

**Actions:**
- `RenameListingTagAction(companyId, tagId, name)` — коллизия slug с другим тегом → `ListingTagNameConflictException`.
- `DeleteListingTagAction(companyId, tagId)` — remove + flush (cascade чистит связи).
- `MergeListingTagsAction(companyId, sourceTagId, targetTagId)` — reassign → remove source → flush.

**Controllers:**
- `ListingTagsManageController` — GET `/marketplace/listings/tags` (HTML со списком + счётчиками).
- `ListingTagRenameController` — POST `/api/marketplace/listings/tags/{tagId}/rename` `{name}`.
- `ListingTagDeleteController` — POST `/api/marketplace/listings/tags/{tagId}/delete`.
- `ListingTagMergeController` — POST `/api/marketplace/listings/tags/merge` `{sourceTagId, targetTagId}`.

Ошибки — стандарт `{"error":{"code","message"}}`. Валидация uuid/имени → 422, коллизия → 409, нет тега → 404.

**UI:** Twig `templates/marketplace/listings/tags.html.twig` (Tabler + UI Kit чипы), vanilla JS:
переименование inline, удаление confirm()+fetch, слияние — dropdown «Слить в…» + confirm. Кнопка
«Управление тегами» в шапке реестра листингов.

**Тесты (functional):** rename happy/collision-409/IDOR; delete + снятие связей/IDOR;
merge reassign+удаление источника/идемпотентность цели/IDOR; страница рендерит счётчики.

## Вне scope
Цвета, группы/иерархия, история, undo слияния, объединение >2 тегов за раз (делается повтором).
