# Этап 2: рекламная статистика по листингам для предпросмотра (без цикла модулей)

stage_base_commit: `d2e8e44f8`

## План этапа

### Решения

1. **Направление зависимостей.** MarketplaceAds уже зависит от Marketplace (`MarketplaceFacade`,
   `MarketplaceType`); прямой вызов `MarketplaceAdsFacade` из Marketplace дал бы цикл модулей
   (запрет `CLAUDE.md`). Поэтому инверсия: Marketplace объявляет порт в открытом слое `Facade`,
   MarketplaceAds его реализует. Это изменение общего плана (там был «новый метод
   `MarketplaceAdsFacade`»).
2. **Порт** — `App\Marketplace\Facade\PlPreviewAdSpendProviderInterface::spendForPeriod(companyId,
   MarketplaceType, from, to): PlPreviewAdSpendDTO`.
3. **DTO** — `App\Marketplace\Application\DTO\PlPreviewAdSpendDTO(byListing: array<listingId,
   string>, total: string)` + `unallocated(): string` = total − Σ byListing. Суммы — положительный
   расход, bcmath-строки.
4. **Реализация** — `App\MarketplaceAds\Facade\PlPreviewAdSpendProvider` (`final readonly`) поверх
   существующих `getAdSpendByListingForPeriod()` и `getTotalAdCostForPeriod()` — те же числа, что
   в отчёте эффективности рекламы и Unit-экономике. Автовайринг по единственной реализации.
5. Ozon-строки статистики без найденного листинга сейчас не сохраняются вовсе
   (`ProcessAdRawDocumentAction`); на проде их доля ничтожна (этап 0: непривязанное — 133 ₽). Правка
   загрузки вне этой задачи — FOLLOW-UP.

### Конечный результат

Предпросмотр может получить по маркетплейсу рекламу по каждому листингу за период и сумму, не
привязанную к листингам, не зависеть от модуля рекламы напрямую.

### Чек-лист

- [x] Integration: документы и строки двух листингов + документ без строк → `byListing`, `total`,
      `unallocated`; чужая компания и другой маркетплейс не попадают; период по `report_date`.
- [x] `ModuleBoundaryRules` (PHPStan) зелёный: Marketplace не импортирует MarketplaceAds.
- [x] PHPStan / CS; `ARCHITECTURE.md` — порт и реализация.

## Stage Report — DONE

Порт, DTO, реализация в MarketplaceAds; integration-тест (по листингам 350 / 100, всего 490,
не привязано 40; февраль, WB и чужая компания не попадают). PHPStan с `ModuleBoundaryRules` — exit 0,
CS — 0. BLOCKER/IMPORTANT: 0. FOLLOW-UP: Ozon-строки статистики без листинга не сохраняются при
загрузке (`ProcessAdRawDocumentAction`).
