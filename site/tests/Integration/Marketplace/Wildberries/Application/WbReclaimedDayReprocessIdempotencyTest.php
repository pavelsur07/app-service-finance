<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Wildberries\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Stage 1.1 / R-01: восстановление зависшего WB-дня заново ставит три шага pipeline для уже
 * загруженного raw-документа (без forceRefresh). Предыдущая попытка могла успеть выполнить
 * часть шагов (процесс убит), поэтому повтор обязан быть идемпотентным: ни дублей, ни потерь.
 */
final class WbReclaimedDayReprocessIdempotencyTest extends IntegrationTestCase
{
    private const RAW_DOC_ID = '99999999-aaaa-4aaa-8aaa-111111111501';

    public function testRerunAfterPartialPipelineCompletesWithoutDuplicatesAndFurtherRerunsChangeNothing(): void
    {
        $company = CompanyBuilder::aCompany()->withIndex(501)->build();
        $user = $company->getUser();
        self::assertNotNull($user);
        $this->em->persist($user);
        $this->em->persist($company);

        $day = new \DateTimeImmutable('2026-04-20');
        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->withId(self::RAW_DOC_ID)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->build();
        $rawDoc->setRawData([
            $this->operationRow(1001, 'Продажа', 'SRID-RECLAIM-SALE-1', '2099', '1584'),
            $this->operationRow(1002, 'Продажа', 'SRID-RECLAIM-SALE-2', '1500', '1200'),
            $this->operationRow(1003, 'Возврат', 'SRID-RECLAIM-RETURN-1', '900', '700'),
            [
                'reportId' => 1,
                'rrdId' => 1004,
                'docTypeName' => '',
                'sellerOperName' => 'Логистика',
                'quantity' => 0,
                'retailPriceWithDisc' => '0',
                'deliveryAmount' => 1,
                'returnAmount' => 0,
                'deliveryService' => '80',
                'nmId' => 123456,
                'techSize' => 'M',
                'sku' => '200000000001',
                'vendorCode' => 'ART-001',
                'brandName' => 'TestBrand',
                'subjectName' => 'Одежда',
                'saleDt' => '2026-04-20T10:00:00Z',
                'rrDate' => '2026-04-20',
                'srid' => 'SRID-RECLAIM-LOGISTICS-1',
            ],
        ]);
        $this->em->persist($rawDoc);
        $this->em->flush();

        $companyId = (string) $company->getId();
        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);

        // Первая попытка: процесс убит после sales и returns, шаг costs не выполнен.
        $action(new ProcessMarketplaceRawDocumentCommand($companyId, self::RAW_DOC_ID, 'sales'));
        $action(new ProcessMarketplaceRawDocumentCommand($companyId, self::RAW_DOC_ID, 'returns'));
        self::assertSame(2, $this->rowsCount('marketplace_sales', $companyId));
        self::assertSame(1, $this->rowsCount('marketplace_returns', $companyId));
        $revenueBefore = $this->rowsSum('marketplace_sales', 'total_revenue', $companyId);

        // Reclaim: ProcessDayReportHandler заново ставит все три шага, forceRefresh = false.
        $this->runAllSteps($action, $companyId);

        self::assertSame(2, $this->rowsCount('marketplace_sales', $companyId), 'Повтор не дублирует продажи.');
        self::assertSame(1, $this->rowsCount('marketplace_returns', $companyId), 'Повтор не дублирует возвраты.');
        self::assertSame($revenueBefore, $this->rowsSum('marketplace_sales', 'total_revenue', $companyId), 'Суммы не меняются.');
        $costsAfterCompletion = $this->rowsCount('marketplace_costs', $companyId);
        self::assertGreaterThan(0, $costsAfterCompletion, 'Шаг costs отработал и создал затраты (иначе проверка идемпотентности затрат пуста).');
        $costSumAfterCompletion = $this->rowsSum('marketplace_costs', 'amount', $companyId);

        // Ещё один повтор (например, второй reclaim) ничего не меняет.
        $this->runAllSteps($action, $companyId);

        self::assertSame(2, $this->rowsCount('marketplace_sales', $companyId));
        self::assertSame(1, $this->rowsCount('marketplace_returns', $companyId));
        self::assertSame($costsAfterCompletion, $this->rowsCount('marketplace_costs', $companyId), 'Затраты пересоздаются без накопления дублей.');
        self::assertSame($costSumAfterCompletion, $this->rowsSum('marketplace_costs', 'amount', $companyId), 'Сумма затрат не меняется.');
        self::assertSame($revenueBefore, $this->rowsSum('marketplace_sales', 'total_revenue', $companyId));
    }

    private function runAllSteps(ProcessMarketplaceRawDocumentAction $action, string $companyId): void
    {
        foreach (['sales', 'returns', 'costs'] as $kind) {
            $action(new ProcessMarketplaceRawDocumentCommand($companyId, self::RAW_DOC_ID, $kind));
        }
    }

    private function rowsCount(string $table, string $companyId): int
    {
        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM %s WHERE company_id = :companyId AND raw_document_id = :rawDocId', $table),
            ['companyId' => $companyId, 'rawDocId' => self::RAW_DOC_ID],
        );
    }

    private function rowsSum(string $table, string $column, string $companyId): string
    {
        return (string) $this->connection->fetchOne(
            sprintf('SELECT COALESCE(SUM(%s), 0) FROM %s WHERE company_id = :companyId AND raw_document_id = :rawDocId', $column, $table),
            ['companyId' => $companyId, 'rawDocId' => self::RAW_DOC_ID],
        );
    }

    /** @return array<string, mixed> */
    private function operationRow(int $rrdId, string $operation, string $srid, string $priceWithDisc, string $retailAmount): array
    {
        return [
            'reportId' => 1,
            'rrdId' => $rrdId,
            'docTypeName' => $operation,
            'sellerOperName' => $operation,
            'quantity' => 1,
            'retailPriceWithDisc' => $priceWithDisc,
            'retailAmount' => $retailAmount,
            'commissionPercent' => 34,
            'forPay' => '1308.04',
            'acquiringFee' => '77.30',
            'ppvzVw' => '600.00',
            'ppvzVwNds' => '113.66',
            'nmId' => 123456,
            'techSize' => 'M',
            'sku' => '200000000001',
            'vendorCode' => 'ART-001',
            'brandName' => 'TestBrand',
            'subjectName' => 'Одежда',
            'saleDt' => '2026-04-20T10:00:00Z',
            'rrDate' => '2026-04-20',
            'srid' => $srid,
        ];
    }
}
