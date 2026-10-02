<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Ozon\Application;

use App\Marketplace\Application\Command\ProcessMarketplaceRawDocumentCommand;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Enum\MarketplaceRawFormat;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\OzonReconciliationCheck;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Ozon\Application\Action\RunOzonReconciliationAction;
use App\Marketplace\Ozon\Application\Service\OzonAccrualServiceCategoryResolver;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonLedgerTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRawAccrualTotalsQuery;
use App\Marketplace\Ozon\Infrastructure\Query\Reconciliation\OzonRealizationTotalsQuery;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Страховка от расхождения двух разборов: сверка пересчитывает сырьё своим SQL, а учёт пишут настоящие
 * процессоры продаж, возвратов и затрат. Если они разойдутся в форме разбора, здесь появится «расхождение»
 * на заведомо корректных данных — иначе ночной гейт был бы вечно красным или, наоборот, слепым.
 */
final class ReconciliationAgainstRealProcessorsTest extends IntegrationTestCase
{
    private const DAY = '2026-09-10';

    public function testReconciliationMatchesWhatTheRealProcessorsWrote(): void
    {
        [$companyId, $docId] = $this->seedDocument($this->payload());

        $run = $this->processAndReconcile($companyId, $docId);

        $lines = self::getContainer()->get(OzonReconciliationLineRepository::class)->findByRun($companyId, $run->getId());
        $ledgerLines = array_values(array_filter($lines, static fn ($l): bool => OzonReconciliationCheck::RAW_VS_LEDGER === $l->getCheck()));
        $codes = array_map(static fn ($l): string => $l->getCategoryCode(), $ledgerLines);

        // Данные реально разобраны: продажа, возврат и все виды затрат, включая обе стороны компенсации и неизвестную услугу.
        foreach (['ozon_sale_commission', 'ozon_compensation', 'ozon_decompensation', 'ozon_unknown_777'] as $expected) {
            self::assertContains($expected, $codes, $expected);
        }
        self::assertGreaterThan(5, count($ledgerLines));

        foreach ($ledgerLines as $line) {
            self::assertSame(
                OzonReconciliationStatus::MATCHED,
                $line->getStatus(),
                sprintf('%s/%s raw=%s ledger=%s', $line->getBlock()->value, $line->getCategoryCode(), (string) $line->getSourceMinor(), (string) $line->getTargetMinor()),
            );
        }
        self::assertSame(0, $run->getMismatchCount());
    }

    public function testSeveralAccrualsOfOnePostingOnOneDayAreAllKeptAndReprocessingIsIdempotent(): void
    {
        $payload = $this->payload();
        $sale = static fn (int $accrualId, string $amount): array => [
            'accrual_id' => $accrualId, 'date' => self::DAY, 'unit_number' => '51100626-0301-2', 'accrued_category' => 'POSTING',
            'posting' => ['products' => [[
                'sku' => '308866704', 'delivery' => null,
                'commission' => ['seller_price' => ['amount' => $amount], 'sale_price' => ['amount' => '1161.9'], 'sale_amount' => ['amount' => $amount], 'commission' => ['amount' => '0']],
            ]]],
        ];
        $return = static fn (int $accrualId, string $amount): array => [
            'accrual_id' => $accrualId, 'date' => self::DAY, 'unit_number' => '0156228731-0949-3', 'accrued_category' => 'POSTING',
            'posting' => ['products' => [[
                'sku' => '308866704', 'delivery' => null,
                'commission' => ['seller_price' => ['amount' => '-'.$amount], 'sale_price' => ['amount' => '-500'], 'sale_amount' => ['amount' => '-'.$amount], 'commission' => ['amount' => '0']],
            ]]],
        ];
        // Продажа: две одинаковые единицы; возврат: два частичных возврата с РАЗНЫМИ суммами (случаи с прода, сентябрь 2026).
        array_push($payload['accruals'], $sale(64793331570, '3800'), $sale(64795093458, '3800'), $return(70000000001, '16346'), $return(70000000002, '9242'));
        [$companyId, $docId] = $this->seedDocument($payload);

        $run = $this->processAndReconcile($companyId, $docId);

        self::assertSame(0, $run->getMismatchCount());
        self::assertSame(1 + 2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :c', ['c' => $companyId]));
        self::assertSame('7600.00', $this->connection->fetchOne("SELECT SUM(total_revenue)::numeric(12,2)::text FROM marketplace_sales WHERE company_id = :c AND external_order_id LIKE 'ozon-accrual-51100626-0301-2-product-0%'", ['c' => $companyId]));
        self::assertSame('25588.00', $this->connection->fetchOne("SELECT SUM(refund_amount)::numeric(12,2)::text FROM marketplace_returns WHERE company_id = :c AND external_return_id LIKE 'ozon-accrual-0156228731-0949-3-return-product-0%'", ['c' => $companyId]));

        // Повторная обработка дня заменяет строки документа, а не наращивает их.
        $rowsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :c', ['c' => $companyId]) + (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE company_id = :c', ['c' => $companyId]);
        $rerun = $this->processAndReconcile($companyId, $docId);
        $rowsAfter = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_sales WHERE company_id = :c', ['c' => $companyId]) + (int) $this->connection->fetchOne('SELECT COUNT(*) FROM marketplace_returns WHERE company_id = :c', ['c' => $companyId]);

        self::assertSame($rowsBefore, $rowsAfter);
        self::assertSame(0, $rerun->getMismatchCount());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{0: string, 1: string}
     */
    private function seedDocument(array $payload): array
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $doc = MarketplaceRawDocumentBuilder::aDocument()->forCompany($company)->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('accrual_by_day')->withProcessingStatus('completed')
            ->withPeriod(new \DateTimeImmutable(self::DAY), new \DateTimeImmutable(self::DAY))->build();
        $doc->setApiEndpoint(MarketplaceRawFormat::OZON_ACCRUAL_BY_DAY->value);
        $doc->setRawData($payload);
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($doc);
        $this->em->flush();

        return [(string) $company->getId(), (string) $doc->getId()];
    }

    private function processAndReconcile(string $companyId, string $docId): \App\Marketplace\Entity\OzonReconciliationRun
    {
        $process = self::getContainer()->get(ProcessMarketplaceRawDocumentAction::class);
        foreach (['sales', 'returns', 'costs'] as $kind) {
            $process(new ProcessMarketplaceRawDocumentCommand($companyId, $docId, $kind));
        }
        $this->em->clear();

        return (new RunOzonReconciliationAction(
            new OzonRealizationTotalsQuery($this->connection),
            new OzonRawAccrualTotalsQuery($this->connection, new OzonAccrualServiceCategoryResolver()),
            new OzonLedgerTotalsQuery($this->connection),
            self::getContainer()->get(OzonReconciliationRunRepository::class),
            self::getContainer()->get(OzonReconciliationLineRepository::class),
            $this->em,
            new MockClock('2026-09-12 09:00:00'),
            new NullLogger(),
        ))($companyId, new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-30'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'accruals' => [
                // Продажа: комиссия и две услуги доставки.
                [
                    'accrual_id' => 1001, 'date' => self::DAY, 'unit_number' => '80000001-1001-1', 'accrued_category' => 'POSTING',
                    'posting' => ['products' => [[
                        'sku' => '700000000',
                        'delivery' => ['services' => [
                            ['type_id' => 32, 'accrued' => ['amount' => '-118']],
                            ['type_id' => 29, 'accrued' => ['amount' => '-5.35']],
                        ]],
                        'commission' => [
                            'seller_price' => ['amount' => '2999'], 'sale_price' => ['amount' => '1168.57'],
                            'sale_amount' => ['amount' => '2999'], 'commission' => ['amount' => '-1379.54'],
                        ],
                    ]]],
                ],
                // Возврат той же позиции: комиссия возвращается (сторно).
                [
                    'accrual_id' => 1002, 'date' => self::DAY, 'unit_number' => '80000001-1001-1', 'accrued_category' => 'POSTING',
                    'posting' => ['products' => [[
                        'sku' => '700000000', 'delivery' => null,
                        'commission' => [
                            'seller_price' => ['amount' => '-2999'], 'sale_price' => ['amount' => '-1168.57'],
                            'sale_amount' => ['amount' => '-2999'], 'commission' => ['amount' => '1379.54'],
                        ],
                    ]]],
                ],
                // ITEM: размещение, компенсация и декомпенсация (знак выбирает категорию), неизвестная услуга.
                [
                    'accrual_id' => 1003, 'date' => self::DAY, 'unit_number' => '90000003', 'accrued_category' => 'ITEM',
                    'item_fees' => ['fees' => [['sku' => '700000000', 'fees' => [
                        ['type_id' => 78, 'accrued' => ['amount' => '-100']],
                        ['type_id' => 90, 'accrued' => ['amount' => '250.50']],
                        ['type_id' => 777, 'accrued' => ['amount' => '-7']],
                    ]]]],
                ],
                [
                    'accrual_id' => 1004, 'date' => self::DAY, 'unit_number' => '90000004', 'accrued_category' => 'ITEM',
                    'item_fees' => ['fees' => [['sku' => '700000000', 'fees' => [
                        ['type_id' => 90, 'accrued' => ['amount' => '-40.25']],
                    ]]]],
                ],
                // NON_ITEM: подписка.
                ['accrual_id' => 1005, 'date' => self::DAY, 'accrued_category' => 'NON_ITEM', 'non_item_fee' => ['type_id' => 52, 'accrued' => ['amount' => '-24990']]],
            ],
            'service_types' => ['32' => 'Logistic', '29' => 'LastMileCourier', '78' => 'TemporaryPlacement', '90' => 'Compensation', '52' => 'PremiumSubscription'],
        ];
    }
}
