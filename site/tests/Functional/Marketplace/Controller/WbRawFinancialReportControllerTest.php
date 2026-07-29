<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Marketplace\Entity\Inventory\MarketplaceInventoryCostPrice;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

final class WbRawFinancialReportControllerTest extends WebTestCaseBase
{
    public function testRendersLoadedRawTotalsForActiveCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(501);
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [[
            'reportId' => 9001,
            'rrdId' => 1001,
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'quantity' => 1,
            'retailPriceWithDisc' => '2099',
            'retailAmount' => '1584',
            'forPay' => '1308.04',
            'acquiringFee' => '77.30',
        ]]);
        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3', 'Контроль финансового отчёта WB');
        self::assertStringContainsString('1 308,04 RUB', $crawler->filter('body')->text());
        self::assertStringContainsString('9001', $crawler->filter('body')->text());
        self::assertStringContainsString('Транзакции системы не используются', $crawler->filter('body')->text());
        self::assertSelectorTextContains('#wb-product-costs', 'Не сопоставлен');
        self::assertSelectorTextContains('#wb-product-costs', 'Нет цены');
        self::assertSelectorTextContains('#wb-product-costs', 'Нет полной себестоимости');
    }

    public function testRendersDeductionBreakdownFromRawReasons(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(506);
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [
            [
                'reportId' => 9101,
                'rrdId' => 1101,
                'sellerOperName' => 'Удержание',
                'deduction' => '-7.50',
                'bonusTypeName' => 'Списание за отзыв',
            ],
            [
                'reportId' => 9102,
                'rrdId' => 1102,
                'sellerOperName' => 'Удержание',
                'deduction' => '2.50',
                'bonus_type_name' => 'Списание за отзыв',
            ],
            [
                'reportId' => 9102,
                'rrdId' => 1103,
                'sellerOperName' => 'Удержание',
                'deduction' => '-3',
            ],
        ]);
        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Расшифровка удержаний и выплат');
        self::assertSelectorTextContains('body', 'Расходы WB (нетто)');
        self::assertSelectorTextContains('body', 'колонка «Возврат / сторно» показывает выплаты WB');
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Списание за отзыв');
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Основание операции WB');
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Без расшифровки WB');
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Удержано');
        self::assertSelectorTextContains('#wb-deduction-breakdown', 'Выплачено WB');
        self::assertStringContainsString(
            '2,50 RUB 7,50 RUB 5,00 RUB',
            $crawler->filter('#wb-deduction-breakdown tbody tr')->first()->text(null, true),
        );
        self::assertStringContainsString(
            'Итого 2,50 RUB 10,50 RUB 8,00 RUB',
            $crawler->filter('#wb-deduction-breakdown tfoot')->text(null, true),
        );
        self::assertSelectorCount(2, '#wb-deduction-breakdown tbody tr');
    }

    public function testDoesNotExposeRawRowsOfAnotherCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user] = $this->seedCompany(502);
        [, $otherCompany] = $this->seedCompany(503);
        $this->seedLoadedDay($otherCompany, new \DateTimeImmutable('2026-07-01'), [[
            'reportId' => 999999,
            'rrdId' => 999999,
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Чужая операция',
            'quantity' => 1,
            'retailPriceWithDisc' => '999999.99',
            'retailAmount' => '999999.99',
            'forPay' => '999999.99',
            'acquiringFee' => '0',
            'deduction' => '-42',
            'bonusTypeName' => 'Чужое удержание',
        ]]);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Чужая операция', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('Чужое удержание', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('999999', (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('.alert-warning', 'нет строк по выбранным фильтрам');
        self::assertSelectorTextContains('#wb-product-costs', 'нет товарных продаж или возвратов');
    }

    public function testRejectsInvalidOrOversizedPeriod(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user] = $this->seedCompany(504);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-01-01&date_to=2026-07-01',
        );

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Период отчёта не может превышать 93 дня');
    }

    public function testCsvUsesSameRawTotalsAndEscapesExternalSpreadsheetFormula(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(505);
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [
            [
                'reportId' => '=HYPERLINK("https://report.example.test")',
                'rrdId' => 2001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => '=HYPERLINK("https://example.test")',
                'quantity' => 1,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '90',
                'forPay' => '75',
                'acquiringFee' => '2',
            ],
            [
                'reportId' => 9002,
                'rrdId' => 2002,
                'sellerOperName' => 'Удержание',
                'deduction' => '-5',
                'bonusTypeName' => '=1+1',
            ],
        ]);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report/csv?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment;', (string) $client->getResponse()->headers->get('content-disposition'));
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('"Расчётное перечисление";80.00', $content);
        self::assertStringContainsString('"Расходы WB (нетто)";20.00', $content);
        self::assertStringContainsString('"Основание операции WB"', $content);
        self::assertStringContainsString(';Удержано;"Выплачено WB";"Влияние на перечисление"', $content);
        self::assertStringContainsString("'=1+1;2026-07-01;2026-07-01;1;1;0.00;5.00;5.00", $content);
        self::assertStringContainsString('Итого;;;;;0.00;5.00;5.00', $content);
        self::assertStringContainsString('\'=HYPERLINK', $content);
        self::assertStringContainsString('\'=HYPERLINK(""https://report.example.test"")', $content);
    }

    public function testRendersSkuCostsBetweenDeductionsAndReportSummaryAndExportsCsv(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(507);
        $this->seedListingWithCost(
            $company,
            507,
            '555',
            'M',
            '+SKU-555',
            '=Опасное имя',
            '460000000555',
            '100.00',
        );
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [
            [
                'reportId' => 9201,
                'rrdId' => 3001,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Продажа',
                'nmId' => 555,
                'techSize' => 'M',
                'sku' => '460000000555',
                'saleDt' => '2026-07-01',
                'quantity' => 2,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '180',
                'forPay' => '150',
                'acquiringFee' => '4',
            ],
            [
                'reportId' => 9201,
                'rrdId' => 3002,
                'docTypeName' => 'Возврат',
                'sellerOperName' => 'Возврат',
                'nmId' => 555,
                'techSize' => 'M',
                'sku' => '460000000555',
                'orderDt' => '2026-07-01',
                'quantity' => 1,
                'retailPriceWithDisc' => '100',
                'retailAmount' => '90',
                'forPay' => '75',
                'acquiringFee' => '2',
            ],
            [
                'reportId' => 9201,
                'rrdId' => 3003,
                'sellerOperName' => 'Удержание',
                'deduction' => '2.50',
                'bonusTypeName' => 'Тестовое удержание',
            ],
        ]);
        $client->loginUser($user);

        $crawler = $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $deductionsPosition = strpos($html, 'id="wb-deduction-breakdown"');
        $productsPosition = strpos($html, 'id="wb-product-costs"');
        $reportsPosition = strpos($html, 'Сводка по reportId');
        self::assertIsInt($deductionsPosition);
        self::assertIsInt($productsPosition);
        self::assertIsInt($reportsPosition);
        self::assertLessThan($productsPosition, $deductionsPosition);
        self::assertLessThan($reportsPosition, $productsPosition);

        self::assertSelectorTextContains('#wb-product-costs', 'Товары и себестоимость');
        self::assertSelectorTextContains('#wb-product-costs', 'Это не чистая прибыль');
        self::assertSelectorTextContains('#wb-product-costs', 'Сверка со статьями: 0,00 RUB');
        self::assertSelectorCount(1, '#wb-product-costs tbody tr[data-sku-nm-id="555"]');
        $productRow = $crawler->filter('#wb-product-costs tbody tr[data-sku-nm-id="555"]')->text(null, true);
        self::assertStringContainsString('=Опасное имя', $productRow);
        self::assertStringContainsString('+SKU-555', $productRow);
        self::assertStringContainsString('Продано: 2', $productRow);
        self::assertStringContainsString('Возвращено: 1', $productRow);
        self::assertStringContainsString('Нетто: 1', $productRow);
        self::assertStringContainsString('200,00 RUB', $productRow);
        self::assertStringContainsString('100,00 RUB', $productRow);
        self::assertStringContainsString('25,00 RUB', $productRow);
        self::assertStringContainsString('Рентабельность к продажам без СПП', $productRow);
        self::assertStringContainsString('-25,0%', $productRow);
        self::assertStringContainsString('50,0%', $productRow);
        self::assertSelectorTextContains(
            '#wb-product-costs tbody tr[data-sku-nm-id="555"] .text-danger',
            '25,00 RUB',
        );

        $client->request(
            'GET',
            '/marketplace/wb-finance-report/csv?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        $csv = (string) $client->getResponse()->getContent();
        $deductionsCsvPosition = strpos($csv, '"Основание операции WB"');
        $productsCsvPosition = strpos($csv, '"Товары и себестоимость"');
        $reportsCsvPosition = strpos($csv, 'reportId;"Дата с";"Дата по"');
        self::assertIsInt($deductionsCsvPosition);
        self::assertIsInt($productsCsvPosition);
        self::assertIsInt($reportsCsvPosition);
        self::assertLessThan($productsCsvPosition, $deductionsCsvPosition);
        self::assertLessThan($reportsCsvPosition, $productsCsvPosition);
        self::assertStringContainsString('Наименование;"Артикул продавца";nmId;Размер;Barcode', $csv);
        self::assertStringContainsString('"Результат до общих расходов WB";-25.00', $csv);
        self::assertStringContainsString("'=Опасное имя", $csv);
        self::assertStringContainsString("'+SKU-555", $csv);
        $productCsvLine = null;
        foreach (preg_split('/\R/u', $csv) ?: [] as $line) {
            if (str_contains($line, "'=Опасное имя")) {
                $productCsvLine = $line;
                break;
            }
        }
        self::assertNotNull($productCsvLine);
        self::assertSame([
            "'=Опасное имя",
            "'+SKU-555",
            '555',
            'M',
            '460000000555',
            'Сопоставлен',
            '2',
            '1',
            '1',
            '200.00',
            '100.00',
            '100.00',
            '180.00',
            '90.00',
            '90.00',
            '150.00',
            '75.00',
            '75.00',
            '200.00',
            '100.00',
            '100.00',
            '3',
            '0',
            '0',
            '100.0',
            'Полное',
            '-25.00',
            '-25.0',
            '50.0',
        ], str_getcsv($productCsvLine, ';', '"', ''));
    }

    public function testRendersFallbackPartialConflictAndUnallocatedWarnings(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$user, $company] = $this->seedCompany(508);
        $this->seedListingWithCost(
            $company,
            508,
            '601',
            'M',
            'FALLBACK',
            'Fallback',
            '460000000601',
            '50.00',
            '2026-07-02',
        );
        $partialListing = $this->seedListingWithCost(
            $company,
            509,
            '602',
            'M',
            'PARTIAL',
            'Partial',
            '460000000602',
            '0.00',
            '2026-06-01',
        );
        $this->em()->persist(new MarketplaceInventoryCostPrice(
            Uuid::uuid7()->toString(),
            (string) $company->getId(),
            $partialListing,
            new \DateTimeImmutable('2026-07-01'),
            '20.00',
        ));
        $this->seedListingWithCost(
            $company,
            510,
            '603',
            'M',
            'CONFLICT-NM',
            'Conflict nmId',
            '460000000603',
            '10.00',
        );
        $this->seedListingWithCost(
            $company,
            511,
            '604',
            'M',
            'CONFLICT-BARCODE',
            'Conflict barcode',
            '460000000604',
            '10.00',
        );
        $this->em()->flush();
        $this->seedLoadedDay($company, new \DateTimeImmutable('2026-07-01'), [
            $this->productSaleRow(4001, 9301, '601', '460000000601', '2026-07-01'),
            $this->productSaleRow(4002, 9301, '602', '460000000602', '2026-06-30'),
            $this->productSaleRow(4003, 9301, '602', '460000000602', '2026-07-01'),
            $this->productSaleRow(4004, 9301, '603', '460000000604', '2026-07-01'),
            [
                'reportId' => 9302,
                'rrdId' => 4005,
                'docTypeName' => 'Продажа',
                'sellerOperName' => 'Коррекция продаж',
                'quantity' => 0,
                'retailPriceWithDisc' => '0',
                'retailAmount' => '0',
                'forPay' => '5',
                'acquiringFee' => '0',
            ],
        ]);
        $client->loginUser($user);

        $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#wb-product-costs .alert-info', '1 шт.');
        self::assertSelectorTextContains('#wb-product-costs .alert-secondary', '5,00 RUB');
        self::assertSelectorTextContains(
            '#wb-product-costs tbody tr[data-sku-nm-id="602"]',
            'Частичное',
        );
        self::assertSelectorTextContains(
            '#wb-product-costs tbody tr[data-sku-nm-id="603"]',
            'Конфликт идентификаторов',
        );
        self::assertSelectorTextContains('#wb-product-costs .alert-warning', 'Конфликтов идентификаторов: 1');

        $client->request(
            'GET',
            '/marketplace/wb-finance-report?date_from=2026-07-01&date_to=2026-07-01&report_id=9302',
        );

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#wb-product-costs', 'нет товарных продаж или возвратов');
        self::assertSelectorTextContains('#wb-product-costs .alert-secondary', '5,00 RUB');
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedCompany(int $index): array
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()
            ->withIndex($index)
            ->withOwner($user)
            ->build();

        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$user, $company];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function seedLoadedDay(Company $company, \DateTimeImmutable $day, array $rows): void
    {
        $rawDocumentId = Uuid::uuid7()->toString();
        $rawDocument = MarketplaceRawDocumentBuilder::aDocument()
            ->withId($rawDocumentId)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->build()
            ->setRawData($rows)
            ->setRecordsCount(count($rows));

        $status = new MarketplaceFinancialReportSyncStatus(
            Uuid::uuid7()->toString(),
            (string) $company->getId(),
            Uuid::uuid7()->toString(),
            MarketplaceType::WILDBERRIES,
            'sales_report',
            'wildberries::sales_report',
            $day,
        );
        $status->markRawLoaded($rawDocumentId, count($rows), hash('sha256', serialize($rows)));
        $status->markSuccess();

        $this->em()->persist($rawDocument);
        $this->em()->persist($status);
        $this->em()->flush();
    }

    private function seedListingWithCost(
        Company $company,
        int $index,
        string $nmId,
        string $size,
        string $supplierSku,
        string $name,
        string $barcode,
        string $costAmount,
        string $effectiveFrom = '2026-01-01',
    ): MarketplaceListing {
        $listing = MarketplaceListingBuilder::aListing()
            ->withIndex($index)
            ->forCompany($company)
            ->withMarketplaceSku($nmId)
            ->build()
            ->setSize($size)
            ->setSupplierSku($supplierSku)
            ->setName($name);
        $this->em()->persist($listing);
        $this->em()->persist(new MarketplaceListingBarcode(
            Uuid::uuid7()->toString(),
            $listing,
            (string) $company->getId(),
            MarketplaceType::WILDBERRIES->value,
            $barcode,
        ));
        $this->em()->persist(new MarketplaceInventoryCostPrice(
            Uuid::uuid7()->toString(),
            (string) $company->getId(),
            $listing,
            new \DateTimeImmutable($effectiveFrom),
            $costAmount,
        ));
        $this->em()->flush();

        return $listing;
    }

    /**
     * @return array<string, mixed>
     */
    private function productSaleRow(
        int $rrdId,
        int $reportId,
        string $nmId,
        string $barcode,
        string $saleDate,
    ): array {
        return [
            'reportId' => $reportId,
            'rrdId' => $rrdId,
            'docTypeName' => 'Продажа',
            'sellerOperName' => 'Продажа',
            'nmId' => $nmId,
            'techSize' => 'M',
            'sku' => $barcode,
            'saleDt' => $saleDate,
            'quantity' => 1,
            'retailPriceWithDisc' => '100',
            'retailAmount' => '90',
            'forPay' => '70',
            'acquiringFee' => '2',
        ];
    }
}
