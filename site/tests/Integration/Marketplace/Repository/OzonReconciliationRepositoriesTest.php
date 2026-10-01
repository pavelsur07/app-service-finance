<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Repository;

use App\Marketplace\Enum\OzonReconciliationBlock;
use App\Marketplace\Enum\OzonReconciliationStatus;
use App\Marketplace\Repository\OzonReconciliationLineRepository;
use App\Marketplace\Repository\OzonReconciliationRunRepository;
use App\Tests\Builders\Marketplace\OzonReconciliationLineBuilder;
use App\Tests\Builders\Marketplace\OzonReconciliationRunBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class OzonReconciliationRepositoriesTest extends IntegrationTestCase
{
    private const COMPANY_A = '11111111-1111-1111-1111-111111111111';
    private const COMPANY_B = '22222222-2222-2222-2222-222222222222';

    private OzonReconciliationRunRepository $runs;
    private OzonReconciliationLineRepository $lines;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runs = self::getContainer()->get(OzonReconciliationRunRepository::class);
        $this->lines = self::getContainer()->get(OzonReconciliationLineRepository::class);
    }

    public function testRunIsFoundOnlyByItsOwnCompany(): void
    {
        $run = OzonReconciliationRunBuilder::aRun()->withCompanyId(self::COMPANY_A)->forMonth(2026, 6)->build();
        $this->runs->save($run);
        $this->em->flush();
        $this->em->clear();

        $from = new \DateTimeImmutable('2026-06-01');
        $to = new \DateTimeImmutable('2026-06-30');

        self::assertNotNull($this->runs->findByPeriod(self::COMPANY_A, $from, $to));
        self::assertNull($this->runs->findByPeriod(self::COMPANY_B, $from, $to));
        self::assertNotNull($this->runs->findByIdForCompany(self::COMPANY_A, $run->getId()));
        self::assertNull($this->runs->findByIdForCompany(self::COMPANY_B, $run->getId()));
        self::assertSame([], $this->runs->findRecentByCompany(self::COMPANY_B));
    }

    public function testRecentRunsAreNewestFirstAndLimited(): void
    {
        foreach ([1 => 4, 2 => 6, 3 => 5] as $index => $month) {
            $this->runs->save(OzonReconciliationRunBuilder::aRun()->withIndex($index)->withCompanyId(self::COMPANY_A)->forMonth(2026, $month)->build());
        }
        $this->em->flush();
        $this->em->clear();

        $recent = $this->runs->findRecentByCompany(self::COMPANY_A, 2);

        self::assertCount(2, $recent);
        self::assertSame('2026-06-01', $recent[0]->getPeriodFrom()->format('Y-m-d'));
        self::assertSame('2026-05-01', $recent[1]->getPeriodFrom()->format('Y-m-d'));
    }

    public function testLinesAreScopedAndDeletedByCompanyAndRun(): void
    {
        $runA = OzonReconciliationRunBuilder::aRun()->withIndex(1)->withCompanyId(self::COMPANY_A)->build();
        $runB = OzonReconciliationRunBuilder::aRun()->withIndex(2)->withCompanyId(self::COMPANY_B)->build();
        $this->runs->save($runA);
        $this->runs->save($runB);

        $lineA = OzonReconciliationLineBuilder::aLine()->withIndex(1)->withCompanyId(self::COMPANY_A)->withRunId($runA->getId())
            ->forBlock(OzonReconciliationBlock::LOGISTICS, 'ozon_logistic_direct')
            ->withAmounts(100000, 99500, OzonReconciliationStatus::MISMATCH)->build();
        $lineB = OzonReconciliationLineBuilder::aLine()->withIndex(2)->withCompanyId(self::COMPANY_B)->withRunId($runB->getId())->build();
        $this->lines->save($lineA);
        $this->lines->save($lineB);
        $this->em->flush();
        $this->em->clear();

        $found = $this->lines->findByRun(self::COMPANY_A, $runA->getId());
        self::assertCount(1, $found);
        self::assertSame(-500, $found[0]->getDeltaMinor());
        self::assertSame(OzonReconciliationStatus::MISMATCH, $found[0]->getStatus());
        self::assertSame([], $this->lines->findByRun(self::COMPANY_B, $runA->getId()));

        // Чужая компания не может снести строки чужого снимка.
        self::assertSame(0, $this->lines->deleteByRun(self::COMPANY_B, $runA->getId()));
        self::assertCount(1, $this->lines->findByRun(self::COMPANY_A, $runA->getId()));

        self::assertSame(1, $this->lines->deleteByRun(self::COMPANY_A, $runA->getId()));
        self::assertSame([], $this->lines->findByRun(self::COMPANY_A, $runA->getId()));
        self::assertCount(1, $this->lines->findByRun(self::COMPANY_B, $runB->getId()));
    }

    public function testDeltaIsNullWhenOneSideHasNoData(): void
    {
        $line = OzonReconciliationLineBuilder::aLine()
            ->withAmounts(null, 5000, OzonReconciliationStatus::NO_DATA)->build();

        self::assertNull($line->getDeltaMinor());
    }
}
