# RECON: Unit-extended отчёт — аудит для реализации экспорта в XLS

> Дата аудита: 2026-04-21. Только чтение, код не изменялся.

---

## 1. Версии и зависимости

**Файл**: `site/composer.json`

| Зависимость | Версия |
|---|---|
| `symfony/framework-bundle` | 7.3.* |
| PHP (требование Symfony 7.3) | ≥ 8.2 (явного `require.php` нет) |
| `openspout/openspout` | ^4.28.5 ✅ (используется для **чтения** XLSX) |
| `phpoffice/phpspreadsheet` | `*` ✅ (установлен) |
| `box/spout` | не найден |
| `symfony/stimulus-bundle` | ^2.32 |
| `symfony/ux-turbo` | ^2.32 |
| `pentatrion/vite-bundle` | ^8.2 (**Vite** — основной JS-бандлер) |
| `symfony/asset-mapper` | 7.3.* |
| Doctrine ORM | 3.6.1 |
| React | 18.3.1 (via package.json) |
| TypeScript | 5.9.3 |

- Webpack Encore — **не используется**
- `.php-version` и `Dockerfile` с явной версией PHP — **не найдены**
- Ext: `bcmath`, `ctype`, `iconv`, `sodium`

---

## 2. Контроллер отчёта

### 2.1 Index-контроллер (страница)

**Файл**: `site/src/MarketplaceAnalytics/Controller/UnitExtendedIndexController.php`

```php
<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class UnitExtendedIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route(
        '/marketplace-analytics/unit-extended',
        name: 'marketplace_analytics_unit_extended_index',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $this->activeCompanyService->getActiveCompany();

        $marketplaces = [
            ['value' => '', 'label' => 'Все'],
            ...array_map(
                static fn (MarketplaceType $t): array => [
                    'value' => $t->value,
                    'label' => $t->getDisplayName(),
                ],
                MarketplaceType::cases(),
            ),
        ];

        return $this->render('marketplace_analytics/unit_extended/index.html.twig', [
            'marketplaces' => $marketplaces,
        ]);
    }
}
```

- **Имя роута**: `marketplace_analytics_unit_extended_index`
- **Query-параметры**: не принимаются (страница только рендерит React)
- **Сервис данных**: нет — данные грузит API-контроллер

### 2.2 API-контроллер (данные для React)

**Файл**: `site/src/MarketplaceAnalytics/Controller/Api/UnitExtendedController.php`

```php
<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace-analytics/unit-extended',
    name: 'marketplace_analytics_api_unit_extended',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class UnitExtendedController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly UnitExtendedQuery $unitExtendedQuery,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $marketplace = $request->query->get('marketplace');
        if ($marketplace === null || $marketplace === '') {
            $marketplace = null;
        } else {
            $validValues = array_map(
                static fn (MarketplaceType $t): string => $t->value,
                MarketplaceType::cases(),
            );
            if (!in_array($marketplace, $validValues, true)) {
                return $this->json([
                    'error' => 'Invalid marketplace. Allowed: ' . implode(', ', $validValues),
                ], 422);
            }
        }

        $periodFrom = $request->query->get('periodFrom', '');
        $periodTo   = $request->query->get('periodTo', '');

        if ($periodFrom === '' || $periodTo === '') {
            return $this->json(['error' => 'periodFrom and periodTo are required'], 422);
        }

        if ($periodFrom > $periodTo) {
            return $this->json(['error' => 'periodFrom must be <= periodTo'], 422);
        }

        $result = $this->unitExtendedQuery->execute($companyId, $marketplace, $periodFrom, $periodTo);

        return $this->json($result);
    }
}
```

- **Имя роута**: `marketplace_analytics_api_unit_extended`
- **Query-параметры**: через `$request->query->get(...)` (нативный `Request`, без DTO и ParamConverter)
  - `marketplace` — опциональный, строка
  - `periodFrom` — обязательный, ISO-дата
  - `periodTo` — обязательный, ISO-дата
- **Сервис отчёта**: `App\MarketplaceAnalytics\Infrastructure\Query\UnitExtendedQuery`, метод `execute()`

---

## 3. Сервис отчёта

**Файл**: `site/src/MarketplaceAnalytics/Infrastructure/Query/UnitExtendedQuery.php`

**Объявление класса**: `final readonly class UnitExtendedQuery`

### Публичные методы

```php
/**
 * @return array{items: list<array<string, mixed>>, totals: array<string, mixed>}
 */
public function execute(
    string $companyId,
    ?string $marketplace,
    string $periodFrom,
    string $periodTo,
    int $limit = 500,
): array
```

**Возвращает**: `array` с двумя ключами:
- `items` — массив строк отчёта (до `$limit = 500`)
- `totals` — агрегаты по всем строкам (без лимита)

**Источники данных** (Doctrine, несколько таблиц через Facade):
- `MarketplaceFacade::getSalesAggregatesByListing()` — данные продаж
- `MarketplaceFacade::getReturnAggregatesByListing()` — данные возвратов
- `MarketplaceFacade::getCostAggregatesByListing()` — затраты по маркетплейсу
- `MarketplaceFacade::getListingsMetaByIds()` — метаданные листингов (SKU, наименование)

**Итератор/пагинация**: отсутствуют. Данные собираются в памяти и обрезаются по `$limit`.

---

## 4. Структура данных отчёта

### Одна строка (`$result['items'][n]`)

```php
[
    'listingId'          => string,       // ID листинга на маркетплейсе
    'title'              => string,       // Наименование товара
    'sku'                => string,       // Артикул
    'marketplace'        => string,       // Код маркетплейса ('ozon', 'wb', ...)
    'revenue'            => float,        // Выручка
    'quantity'           => int,          // Количество продаж
    'returnsTotal'       => float,        // Сумма возвратов
    'costPriceTotal'     => float,        // Себестоимость общая
    'costPriceUnit'      => float,        // Себестоимость единицы
    'commission'         => float,        // Комиссия
    'logistics'          => float,        // Логистика
    'otherCosts'         => float,        // Прочие затраты
    'totalCosts'         => float,        // Итого затрат (commission + logistics + otherCosts)
    'profit'             => float,        // Прибыль
    'marginPercent'      => float|null,   // Маржа %, null если revenue=0
    'roiPercent'         => float|null,   // ROI %, null если costPriceTotal=0
    'otherCostsBreakdown' => [            // Детализация прочих затрат
        [
            'serviceGroup'  => string,
            'costsAmount'   => float,
            'stornoAmount'  => float,
            'netAmount'     => float,
            'categories'    => [
                [
                    'code'         => string,
                    'name'         => string,
                    'costsAmount'  => float,
                    'stornoAmount' => float,
                    'netAmount'    => float,
                ],
                // ...
            ],
        ],
        // ...
    ],
    'allCostsBreakdown' => [ /* та же структура, включает комиссию и логистику */ ],
]
```

### Строка итогов (`$result['totals']`)

```php
[
    'revenue'        => float,
    'quantity'       => int,
    'returnsTotal'   => float,
    'costPriceTotal' => float,
    'commission'     => float,
    'logistics'      => float,
    'otherCosts'     => float,
    'totalCosts'     => float,
    'profit'         => float,
    'marginPercent'  => float|null,
    'roiPercent'     => float|null,
]
```

### Колонки в UI (из `UnitExtendedTable.tsx`)

| # | Ключ | Заголовок |
|---|---|---|
| 1 | `sku` | SKU |
| 2 | `title` | Наименование |
| 3 | `revenue` | Выручка |
| 4 | `quantity` | Кол-во |
| 5 | `returnsTotal` | Возвраты |
| 6 | `costPriceTotal` | Себестоимость |
| 7 | `costPriceUnit` | Себест. ед. |
| 8 | `commission` | Комиссия |
| 9 | `logistics` | Логистика |
| 10 | `otherCosts` | Прочие затраты (с кнопкой раскрытия) |
| 11 | `totalCosts` | Итого затрат |
| 12 | `profit` | Прибыль |
| 13 | `marginPercent` | Маржа % |
| 14 | `roiPercent` | ROI % |
| 15 | `allCostsBreakdown` | Все затраты (с раскрытием) |

---

## 5. Twig-шаблон отчёта

**Путь**: `site/templates/marketplace_analytics/unit_extended/index.html.twig`

```twig
{% extends 'base.html.twig' %}

{% block title %}Unit — расширенный — Аналитика маркетплейсов{% endblock %}

{% block content %}
    {% include 'marketplace_analytics/_tabs.html.twig' with { activeTab: 'unit_extended' } %}

    <div class="page-body">
        <div class="container-xl">
            <div class="tab-content">
                <div class="tab-pane active show">
                    <div
                        id="react-unit-extended"
                        data-marketplaces="{{ marketplaces|json_encode|e('html_attr') }}"
                    ></div>
                </div>
            </div>
        </div>
    </div>
{% endblock %}

{% block javascripts %}
    {{ parent() }}
    {{ vite_entry_script_tags('unit_extended_page') }}
{% endblock %}
```

**Вывод**: Twig-шаблон минимальный — только mount-точка для React (`#react-unit-extended`). Всё UI включая фильтры — в React.

### Toolbar/фильтры (в React, файл `UnitExtendedWidget.tsx`)

Кнопка экспорта встаёт в `card-options` рядом со счётчиком товаров:

```tsx
<div className="card-header">
    <h3 className="card-title">Юнит-экономика по листингам</h3>
    <div className="card-options">
        {/* ← сюда кнопка экспорта */}
        <span className="text-muted">
            Товаров: {items.length.toLocaleString('ru-RU')}
        </span>
    </div>
</div>
```

### CSS-классы кнопок (Tabler/Bootstrap 5)

```
btn btn-primary          — основная кнопка
btn btn-sm btn-primary   — маленькая основная
btn btn-ghost-primary    — ghost-вариант
btn btn-ghost-secondary  — ghost вторичный
btn btn-sm               — маленькая
```

**CSS-фреймворк**: **Tabler** (на базе Bootstrap 5)
- `@tabler/core@1.2.0` (CDN)
- `@tabler/icons-webfont@2.39.0` (иконки `ti ti-*`)

---

## 6. Фронтенд-слой

### Stimulus-контроллеры

**Папка**: `site/assets/controllers/`

Контроллеры:
- `hello_controller.js` — демо-пример
- `csrf_protection_controller.js` — CSRF

**Пример стиля** (`hello_controller.js`):
```javascript
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.element.textContent = 'Hello Stimulus! Edit me in assets/controllers/hello_controller.js';
    }
}
```

### Сборка JS

**Инструмент**: Vite (`pentatrion/vite-bundle ^8.2`)

**Файл**: `site/vite.config.js`

```javascript
import { defineConfig } from "vite";
import symfonyPlugin from "vite-plugin-symfony";
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        react(),
        symfonyPlugin(),
    ],
    build: {
        outDir: "public/build",
        rollupOptions: {
            input: {
                app: "./assets/app.js",
                dashboard: "./assets/react/dashboard_started.tsx",
                marketplace_analytics_kpi: "./assets/react/marketplace_analytics_kpi.tsx",
                marketplace_analytics_page: "./assets/react/marketplace-analytics-page.tsx",
                reconciliation_page: "./assets/react/reconciliation-page.tsx",
                unit_extended_page: "./assets/react/unit-extended-page.tsx",
            },
        }
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        watch: { usePolling: true },
        hmr: { host: 'localhost' }
    }
});
```

**Entry point для unit-extended**: `unit_extended_page` → `assets/react/unit-extended-page.tsx`

**Стек фронтенда**:
- React 18.3.1 + TypeScript 5.9.3
- Vite 6.0 + `vite-plugin-symfony`
- Tabler Icons 3.37.1 (`ti ti-*`)
- Stimulus 2.32 (для progressive enhancement)

---

## 7. Авторизация и безопасность

### Защита роутов

Оба контроллера (`UnitExtendedIndexController` и `UnitExtendedController`) используют:
```php
#[IsGranted('ROLE_COMPANY_USER')]
```

### Иерархия ролей (`security.yaml`)

```
ROLE_COMPANY_USER ← ROLE_USER
ROLE_COMPANY_OWNER ← ROLE_COMPANY_USER
ROLE_SUPER_ADMIN ← ROLE_ADMIN
```

### Кастомный Voter

Кастомного Voter для `marketplace-analytics` — **не найдено**.

### RateLimiter

**Файл**: `site/config/packages/rate_limiter.yaml`

```yaml
framework:
    rate_limiter:
        reports_api:
            policy: fixed_window
            limit: 60
            interval: '1 minute'
        registration:
            policy: fixed_window
            limit: 5
            interval: '10 minutes'
```

- Лимитер `reports_api` существует, но **не применён** к unit-extended контроллерам (применяется только в `/api/public/reports/`)

---

## 8. Очереди и асинхронность

**Файл**: `site/config/packages/messenger.yaml`

```yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                retry_strategy:
                    max_retries: 3
                    multiplier: 2
            failed: 'doctrine://default?queue_name=failed'

        default_bus: messenger.bus.default

        buses:
            messenger.bus.default:
                middleware:
                    - doctrine_ping_connection
                    - doctrine_close_connection

        routing:
            'App\Message\ApplyAutoRulesForTransaction': async
            'App\Marketplace\Message\SyncWbReportMessage': async
            'App\Marketplace\Message\SyncOzonReportMessage': async
            'App\Marketplace\Message\InitialSyncMessage': async
            'App\Marketplace\Message\TriggerInitialSyncMessage': async
            'App\Marketplace\Message\SyncOzonRealizationMessage': async
            'App\Marketplace\Message\ImportInventoryCostPriceMessage': async
            'App\Marketplace\Message\CloseMonthStageMessage': async
            'App\Marketplace\Message\ProcessDayReportMessage': async
            'App\Marketplace\Message\ProcessRawDocumentStepMessage': async
            'App\Marketplace\Message\ReprocessCostsMessage': async
            'App\Marketplace\Application\Command\FetchMarketplaceDataCommand': async
            'App\Catalog\Message\ImportProductsMessage': async
            App\Message\SendRegistrationEmailMessage: async
            App\Cash\Message\Import\BankImportMessage: async
            App\Cash\Message\Import\CashFileImportMessage: async
            Symfony\Component\Mailer\Messenger\SendEmailMessage: async
            Symfony\Component\Notifier\Message\ChatMessage: async
            Symfony\Component\Notifier\Message\SmsMessage: async
            App\MarketplaceAnalytics\Message\RecalcSnapshotsMessage: async
            App\MarketplaceAds\Message\ProcessAdRawDocumentMessage: async
            App\MarketplaceAds\Message\FetchOzonAdStatisticsMessage: async
            App\MarketplaceAds\Message\LoadOzonAdStatisticsRangeMessage: async
```

**Транспорты**:
- `async` — Redis (через `MESSENGER_TRANSPORT_DSN`), 3 повтора с множителем 2
- `failed` — Doctrine, очередь `failed`

**Паттерн «генерация файла в фоне → ссылка»**: в проекте **не реализован**. Нет готового шаблона для этого — только синхронные скачивания.

---

## 9. Существующие экспорты

### 9.1 BinaryFileResponse (файл с диска)

**Файл**: `site/src/MarketplaceAnalytics/Controller/Api/DebugDownloadRawDocumentController.php`

```php
<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/api/marketplace-analytics/debug/raw-document/{id}/download',
    name: 'api_marketplace_analytics_debug_raw_document_download',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugDownloadRawDocumentController extends AbstractController
{
    private const EXTENSION_MAP = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-excel'       => 'xls',
        'text/csv'                       => 'csv',
        'application/csv'                => 'csv',
        'application/zip'                => 'zip',
        'application/octet-stream'       => 'bin',
    ];

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceRawDocumentRepository $rawDocumentRepository,
        private readonly StorageService $storageService,
    ) {
    }

    public function __invoke(string $id): Response
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $document = $this->rawDocumentRepository->find($id);

        if (null === $document || (string) $document->getCompany()->getId() !== $companyId) {
            throw $this->createNotFoundException('Raw-документ не найден.');
        }

        $rawData    = $document->getRawData();
        $periodFrom = $document->getPeriodFrom()->format('Y-m');

        if (isset($rawData['file_path'])) {
            return $this->serveFromDisk($rawData, $periodFrom);
        }

        if ((!empty($rawData['_binary']) || !empty($rawData['_text'])) && isset($rawData['content_base64'])) {
            return $this->serveFromBase64($rawData, $periodFrom);
        }

        throw $this->createNotFoundException('Документ не содержит данных для скачивания.');
    }

    private function serveFromDisk(array $rawData, string $periodFrom): Response
    {
        $absolutePath = $this->storageService->getAbsolutePath($rawData['file_path']);

        if (!file_exists($absolutePath)) {
            throw $this->createNotFoundException('Файл не найден на диске.');
        }

        $contentType = $rawData['content_type'] ?? 'application/octet-stream';
        $extension   = self::EXTENSION_MAP[$contentType] ?? pathinfo($absolutePath, PATHINFO_EXTENSION) ?: 'bin';
        $filename    = sprintf('mutual_settlement_%s.%s', $periodFrom, $extension);

        $response = new BinaryFileResponse($absolutePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
        $response->headers->set('Content-Type', $contentType);

        return $response;
    }

    private function serveFromBase64(array $rawData, string $periodFrom): Response
    {
        $contentType = $rawData['content_type'] ?? 'application/octet-stream';
        $content     = base64_decode($rawData['content_base64'], true);

        if (false === $content) {
            throw new \RuntimeException('Не удалось декодировать base64.');
        }

        $extension = self::EXTENSION_MAP[$contentType] ?? 'bin';
        $filename  = sprintf('mutual_settlement_%s.%s', $periodFrom, $extension);

        return new Response($content, 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length'      => (string) strlen($content),
        ]);
    }
}
```

### 9.2 StreamedResponse (CSV)

**Файл**: `site/src/Controller/Api/PublicCashflowReportController.php` (фрагмент)

```php
$resp = new StreamedResponse(function () use ($periods, $categoryTotals, $openings, $closings) {
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Период', 'КатегорияID', 'Валюта', 'Сальдо нач.', 'Нетто', 'Сальдо кон.']);
    foreach ($periods as $i => $p) {
        fputcsv($out, [$label, $catId, $currency, $opening, $net, $closing]);
    }
    fclose($out);
});
$resp->headers->set('Content-Type', 'text/csv; charset=UTF-8');
$resp->headers->set('Cache-Control', 'max-age=60');

return $resp;
```

### 9.3 OpenSpout (чтение XLSX)

**Файл**: `site/src/Marketplace/Application/Reconciliation/XlsxReaderService.php`

```php
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Options;

$options = new Options();
$options->SHOULD_LOAD_EMPTY_ROWS = false;

$reader = new Reader($options);
$reader->open($absolutePath);

foreach ($reader->getSheetIterator() as $sheet) {
    foreach ($sheet->getRowIterator() as $row) {
        $cells  = $row->getCells();
        $values = array_map(static fn($cell) => $cell->getValue(), $cells);
        // обработка...
    }
}

$reader->close();
```

**Вывод**: OpenSpout используется только для **чтения**. Запись XLSX в проекте не реализована.
Для **записи** XLSX доступны: `openspout/openspout` (writer) и `phpoffice/phpspreadsheet`.

---

## 10. Конвенции проекта

### PSR-4 (из `composer.json`)

```json
"autoload": {
    "psr-4": {
        "App\\": "src/"
    }
}
```

### Структура модуля

```
src/MarketplaceAnalytics/
├── Controller/
│   ├── UnitExtendedIndexController.php
│   └── Api/
│       └── UnitExtendedController.php
├── Infrastructure/
│   └── Query/
│       └── UnitExtendedQuery.php
├── Repository/
├── Entity/
├── DTO/
├── Domain/
├── Facade/
├── Message/
│   └── RecalcSnapshotsMessage.php
└── MessageHandler/
```

### Расположение DTO

- `src/{Module}/DTO/` — основная папка
- Пример: `src/MarketplaceAnalytics/DTO/ListingUnitEconomics.php`
- Альтернатива: `src/{Module}/Application/DTO/`

### Именование роутов

**Паттерн**: `snake_case`, разделитель `_`
- `marketplace_analytics_unit_extended_index`
- `marketplace_analytics_api_unit_extended`
- `api_marketplace_analytics_debug_raw_document_download`
- Шаблон: `{module}_{submodule}_{action}` или `api_{module}_{action}`

### Стиль кода

```php
<?php

declare(strict_types=1);        // всегда

final class MyService           // final по умолчанию
final readonly class MyDto      // readonly для DTO и stateless-сервисов
class MyEntity                  // НЕ final (Doctrine proxy)
enum MyEnum                     // без final (PHP enum implicitly final)
```

- Constructor property promotion с `private readonly`
- Атрибуты для маршрутов и безопасности: `#[Route(...)]`, `#[IsGranted(...)]`
- PHPDoc только для `@return array{...}` типизации

### Пример атрибутов

```php
#[Route(
    '/api/marketplace-analytics/unit-extended',
    name: 'marketplace_analytics_api_unit_extended',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
```

---

## 11. Тесты

### PHPUnit

- **Версия**: 11.5.48 (из `composer.json` require-dev)

### Конфигурация

**Файл**: `site/phpunit.xml`

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php"
         cacheDirectory="var/test/.phpunit.cache"
         colors="true"
         executionOrder="depends,defects">
    <php>
        <env name="APP_ENV" value="test"/>
        <env name="DATABASE_URL" value="postgresql://app:secret@site-postgres:5432/app_test?serverVersion=15&amp;charset=utf8"/>
    </php>
    <testsuites>
        <testsuite name="unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="functional">
            <directory>tests/Functional</directory>
        </testsuite>
        <testsuite name="marketplace-analytics">
            <directory>tests/MarketplaceAnalytics</directory>
        </testsuite>
        <testsuite name="cash">
            <directory>tests/Cash</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

### Структура `tests/`

```
tests/
├── Unit/
├── Integration/
├── Functional/
│   └── Analytics/
│       └── DashboardSnapshotControllerTest.php
├── MarketplaceAnalytics/
├── Marketplace/
├── Cash/
└── Support/
    ├── Kernel/
    │   └── WebTestCaseBase.php
    └── Db/
        └── DbReset.php
```

### Пример функционального теста (WebTestCase)

**Файл**: `site/tests/Functional/Analytics/DashboardSnapshotControllerTest.php`

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Analytics;

use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class DashboardSnapshotControllerTest extends WebTestCaseBase
{
    public function testSnapshotContainsAllWidgetKeys(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $em   = $this->em();
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()
            ->withOwner($user)
            ->build();

        $em->persist($user);
        $em->persist($company);
        $em->flush();

        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();

        $client->request('GET', '/api/dashboard/v1/snapshot');

        self::assertResponseStatusCodeSame(200);

        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertArrayHasKey('widgets', $payload);
    }
}
```

### Базовый класс

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Tests\Support\Db\DbReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class WebTestCaseBase extends WebTestCase
{
    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function resetDb(): void
    {
        (new DbReset())->reset($this->em());
    }
}
```

**Builder-паттерн**: `App\Tests\Builders\{Module}\{Entity}Builder` с fluent-интерфейсом.

---

## 12. Локализация

### Конфигурация

**Файл**: `site/config/packages/translation.yaml`

```yaml
framework:
    default_locale: en
    translator:
        default_path: '%kernel.project_dir%/translations'
```

- **Default locale**: `en`
- **Папка переводов**: `site/translations/` — **пуста**

### Фактическое использование

- `{{ 'key'|trans }}` в шаблонах marketplace-analytics — **не применяется**
- Весь UI-текст **захардкожен на русском** в React-компонентах
- Примеры: `"Unit — расширенный"`, `"Юнит-экономика по листингам"`, `"Товаров"`

### Итог

| Слой | Язык |
|---|---|
| Backend (PHP-конфиг) | `en` (locale) |
| Frontend (React/Twig) | Русский (hardcoded) |
| Переводы i18n | Не реализованы |

---

## Итоговые выводы для реализации экспорта

1. **Библиотека для XLSX**: доступны `openspout/openspout` (writer, рекомендован — уже в зависимостях) и `phpoffice/phpspreadsheet`. OpenSpout уже используется в проекте (для чтения).

2. **Паттерн HTTP-ответа**: использовать `StreamedResponse` (аналог CSV-экспорта) либо `Response` с `Content-Disposition: attachment`. `BinaryFileResponse` только для файлов с диска.

3. **Архитектура**: новый контроллер `UnitExtendedExportController` в `src/MarketplaceAnalytics/Controller/Api/`, сервис-экспортёр в `src/MarketplaceAnalytics/Infrastructure/Export/` или `Application/`. Данные берутся из `UnitExtendedQuery::execute()`.

4. **Фронтенд**: кнопка в `card-options` внутри `UnitExtendedWidget.tsx`, классы `btn btn-sm btn-ghost-secondary`, иконка `ti ti-download`. Кнопка открывает URL `/api/marketplace-analytics/unit-extended/export?...` с теми же параметрами.

5. **Авторизация**: `#[IsGranted('ROLE_COMPANY_USER')]` — такой же как у существующего API.

6. **Нет итератора**: `UnitExtendedQuery` собирает всё в память. Лимит 500. Для XLS это приемлемо.

7. **Тесты**: хэппи-пас тест в `tests/MarketplaceAnalytics/` через `WebTestCaseBase`, проверить код ответа 200 и `Content-Disposition`.
