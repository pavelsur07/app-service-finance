<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Infrastructure\Performance\PerformanceOutcome;
use App\Shared\Infrastructure\Performance\PerformanceRecorder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceListingBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

/**
 * Acceptance M1 №1/№9: включённая диагностика не меняет результат денежного конвейера.
 * Один и тот же raw WB обрабатывается для двух компаний — с выключенным и с включённым
 * регистратором; строки продаж, возвратов и затрат совпадают до копейки, а события
 * этапов при этом пишутся и не несут сумм.
 */
final class PerformanceDiagnosticsPipelineTest extends IntegrationTestCase
{
    public function testEnabledDiagnosticsProducesIdenticalAccountingRowsAndStageEvents(): void
    {
        $handler = new TestHandler();
        $recorder = new PerformanceRecorder(new Logger('performance', [$handler]), true);
        // До первого flush и до создания сервисов конвейера: их зависимости получат этот экземпляр.
        self::getContainer()->set(PerformanceRecorder::class, $recorder);

        $plain = $this->seed(420, '99999999-aaaa-4aaa-8aaa-420000000001');
        $measured = $this->seed(421, '99999999-aaaa-4aaa-8aaa-421000000001');
        $action = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);

        foreach (['sales', 'returns', 'costs'] as $kind) {
            $action(new ProcessMarketplaceRawDocumentCommand((string) $plain->getId(), '99999999-aaaa-4aaa-8aaa-420000000001', $kind));
        }
        self::assertSame([], $handler->getRecords(), 'Вне области замера ничего не пишется');

        foreach (['sales', 'returns', 'costs'] as $kind) {
            $recorder->beginScope('ProcessRawDocumentStepMessage', 'wb', (string) $measured->getId());
            $action(new ProcessMarketplaceRawDocumentCommand((string) $measured->getId(), '99999999-aaaa-4aaa-8aaa-421000000001', $kind));
            $recorder->endScope(PerformanceOutcome::Ok);
        }

        $expected = $this->accountingSnapshot((string) $plain->getId(), 'c420-');
        self::assertNotEmpty($expected['sales']);
        self::assertNotEmpty($expected['returns']);
        self::assertNotEmpty($expected['costs']);
        self::assertSame($expected, $this->accountingSnapshot((string) $measured->getId(), 'c421-'));

        $stages = array_unique(array_map(static fn ($r): string => $r->context['stage'], $handler->getRecords()));
        foreach (['source_normalize', 'processor_total', 'financial_mapping', 'financial_posting', 'handler'] as $stage) {
            self::assertContains($stage, $stages);
        }

        $dump = (string) json_encode(array_map(static fn ($r): array => $r->context, $handler->getRecords()));
        foreach (['1584', '713.66', '2099', 'test-sale-srid', 'ART-001'] as $sensitive) {
            self::assertStringNotContainsString($sensitive, $dump);
        }
    }

    /**
     * @return array{sales: list<array<string, mixed>>, returns: list<array<string, mixed>>, costs: list<array<string, mixed>>}
     */
    private function accountingSnapshot(string $companyId, string $sridPrefix): array
    {
        // srid уникален в маркетплейсе глобально, поэтому у компаний разные префиксы — снимаются для сравнения.
        $snapshot = [
            'sales' => $this->connection->fetchAllAssociative('SELECT external_order_id, quantity, price_per_unit, total_revenue, cost_price, sale_date FROM marketplace_sales WHERE company_id = :c ORDER BY external_order_id', ['c' => $companyId]),
            'returns' => $this->connection->fetchAllAssociative('SELECT external_return_id, quantity, refund_amount, return_date FROM marketplace_returns WHERE company_id = :c ORDER BY external_return_id', ['c' => $companyId]),
            'costs' => $this->connection->fetchAllAssociative(
                'SELECT c.external_id, c.amount, c.operation_type, cat.code
                   FROM marketplace_costs c LEFT JOIN marketplace_cost_categories cat ON cat.id = c.category_id
                  WHERE c.company_id = :c ORDER BY c.external_id, c.operation_type, c.amount',
                ['c' => $companyId],
            ),
        ];
        array_walk_recursive($snapshot, static function (mixed &$value) use ($sridPrefix): void {
            if (\is_string($value)) {
                $value = str_replace($sridPrefix, '', $value);
            }
        });

        return $snapshot;
    }

    private function seed(int $index, string $rawDocId): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist(MarketplaceListingBuilder::aListing()
            ->withIndex($index)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withMarketplaceSku('200000000001')
            ->build());

        $day = new \DateTimeImmutable('2026-05-21');
        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->withId($rawDocId)
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::WILDBERRIES)
            ->withPeriod($day, $day)
            ->build();
        $common = ['reportId' => 1, 'nmId' => 123456, 'techSize' => 'M', 'sku' => '200000000001', 'vendorCode' => 'ART-001', 'brandName' => 'TestBrand', 'subjectName' => 'Одежда'];
        $rawDoc->setRawData([
            $common + ['rrdId' => 1001, 'docTypeName' => 'Продажа', 'sellerOperName' => 'Продажа', 'quantity' => 1, 'retailPriceWithDisc' => '2099', 'retailAmount' => '1584', 'commissionPercent' => 34, 'forPay' => '1308.04', 'acquiringFee' => '77.30', 'ppvzVw' => '600.00', 'ppvzVwNds' => '113.66', 'saleDt' => '2026-05-21T10:00:00Z', 'rrDate' => '2026-05-21', 'srid' => "c{$index}-test-sale-srid-1"],
            $common + ['rrdId' => 1002, 'docTypeName' => 'Возврат', 'sellerOperName' => 'Возврат', 'quantity' => 1, 'retailPriceWithDisc' => '2099', 'retailAmount' => '1584', 'commissionPercent' => 34, 'forPay' => '1308.04', 'acquiringFee' => '77.30', 'ppvzVw' => '600.00', 'ppvzVwNds' => '113.66', 'saleDt' => '2026-05-22T10:00:00Z', 'rrDate' => '2026-05-22', 'srid' => "c{$index}-test-return-srid-1"],
            $common + ['rrdId' => 1003, 'docTypeName' => '', 'sellerOperName' => 'Логистика', 'quantity' => 0, 'retailPriceWithDisc' => '0', 'deliveryAmount' => 1, 'returnAmount' => 0, 'deliveryService' => '80', 'saleDt' => '2026-05-21T10:00:00Z', 'rrDate' => '2026-05-21', 'srid' => "c{$index}-test-logistics-srid-1"],
        ]);
        $this->em->persist($rawDoc);
        $this->em->flush();

        return $company;
    }
}
