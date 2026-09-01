## Current checkpoint

**Задача:** загрузка каталога листингов Ozon (`docs/tasks/ozon-listing-catalog/plan.md`)
**Ветка:** `docs/ozon-listing-catalog`
**Обновлено:** 2026-09-01 (все три Stage закрыты)

### Состояние стадий

| Stage | Статус | Риск |
|---|---|---|
| 1 — схема и raw-seam | ✅ DONE, REVIEW_GREEN, ждёт Release Gate | 🟡 MEDIUM |
| 2 — загрузка каталога Ozon | ✅ DONE, ждёт Release Gate. REVIEW_GREEN **не достигнут** — закрыт решением Владельца с FOLLOW-UP | 🟠 HIGH-LOCAL |
| 3 — ручной запуск из UI | ✅ DONE, **REVIEW_GREEN**, ждёт Release Gate | 🟢 LOW |

### Сделано

- `97fcdc73` — план задачи, скрипт снятия фикстур `site/bin/capture-ozon-listings.sh`,
  `.gitignore` для снимков реальных данных.
- Stage 1 — в ветке `docs/ozon-listing-catalog`: две nullable-колонки
  `marketplace_created_at` / `last_seen_at` в `marketplace_listings`
  (`Version20260901090000`), поля и аксессоры в `MarketplaceListing`,
  `RawStorageFacade::storeAndGetIds()`, `ARCHITECTURE.md` 1.83.
  Отчёт: `docs/tasks/ozon-listing-catalog/stages/stage-1.md`.

### Следующее действие

🛑 Release Gate по всем трём Stage — решение Владельца. PR #2400.

**До первого прогона на продавце с крупным каталогом** — проверить потребление
памяти: FOLLOW-UP 1 в `stages/stage-2.md` (память `O(весь каталог)`).

### Stage 2 — что сделано

- Pipeline `app:marketplace:ozon-listing-catalog:sync` (cron `40 3 * * *`,
  либо `--company=<uuid>`) → `SyncOzonListingCatalogMessage` (`async_sync`) →
  `SyncOzonListingCatalogHandler` → `RefreshOzonListingCatalogAction`.
- Сырые ответы обоих эндпоинтов на S3 через `RawStorageFacade::storeAndGetIds`.
- Девять итераций внешнего ревью, 12 находок исправлено, 1 BLOCKER отклонён с
  доказательством. Оставшееся — в FOLLOW-UP отчёта Stage 2.
- Попутно исправлен регресс в соседнем пайплайне: записи без маппера теперь
  переводятся в `SKIPPED`, иначе каталожные raw навсегда занимали бы окно
  `app:ingestion:normalize-pending` и вытесняли финансовые.

### Stage 3 — что сделано

- Кнопка «Обновить каталог Ozon» на странице листингов, `MarketplaceJobLog` с
  `JobType::LISTING_CATALOG_SYNC_OZON`, показ итога последнего прогона.
- Закрыт FOLLOW-UP 2 из Stage 2: взаимное исключение прогонов через
  `LockFactory` по `(companyId, connectionId)`, TTL 300 c с продлением.
- Терминальный статус журнала при ошибке пишется через DBAL: сбой в чанковой
  транзакции закрывает EntityManager, и ORM-запись подменила бы исходное
  исключение техническим.
- Три итерации внешнего ревью, **REVIEW_GREEN**.

### Что нужно знать при возобновлении

- **Ключ сопоставления каталога с листингами — множество `sources[].sku`, а не
  верхнеуровневый `sku`.** На реальной выгрузке 50 товаров дали 78 SKU: у 28 товаров
  два источника (sds + fbs) со своим sku каждый. Матчинг по одному sku потерял бы 36%.
  Это главный риск Stage 2.
- Финансовый `OzonListingUpsertQuery` (`ON CONFLICT DO NOTHING`) **не трогать**.
  Каталогу — отдельный query с `DO UPDATE`. Иначе финансовый документ начнёт
  перезаписывать каталожное имя, то есть наоборот принятому решению.
- `is_active` каталог не меняет и `price` не трогает — решения Владельца, см. plan.md.
- Тестовая БД требует `make site-test-migrations` после появления новой миграции,
  иначе интеграционная сюита падает сотнями `Undefined column`.
- PHPStan (`make site-stan`) идёт дольше 10 минут вместе с прогревом кэша —
  запускать в фоне, не в foreground.
- Прод-доступ из окружения агента не работает: алиас `vf-prod-codex` не резолвится.
  Гипотеза «SKU 3732855303 — вторичный fbs-SKU» остаётся непроверенной.
- Снимки реального API лежат в `site/tests/Fixtures/Marketplace/Ozon/captured/`
  (под `.gitignore`). Обезличенную фикстуру с товаром на два источника делать
  в Stage 2, work item 2.2.
