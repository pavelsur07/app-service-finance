<?php

declare(strict_types=1);

namespace App\Tests\Unit\MarketplaceAds\Application\Service;

use App\MarketplaceAds\Application\Service\AdBatchPlanner;
use App\MarketplaceAds\Entity\AdScheduledBatch;
use App\MarketplaceAds\Enum\AdScheduledBatchState;
use App\MarketplaceAds\Infrastructure\Api\Ozon\OzonAdClient;
use App\MarketplaceAds\Repository\AdScheduledBatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit-тесты {@see AdBatchPlanner}:
 *  - 260 кампаний → 26 батчей (chunk по 10);
 *  - scheduled_at у N-го батча = now() + N * 120s;
 *  - все батчи стартуют в PLANNED, retry_count = 0;
 *  - пустой список кампаний → RuntimeException;
 *  - повторный вызов (findByJobId возвращает не пустой список) → идемпотентен.
 */
final class AdBatchPlannerTest extends TestCase
{
    private const COMPANY_ID = '11111111-1111-1111-1111-111111111111';
    private const JOB_ID = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';

    /** @var OzonAdClient&MockObject */
    private OzonAdClient $ozonClient;
    /** @var AdScheduledBatchRepository&MockObject */
    private AdScheduledBatchRepository $batchRepo;
    /** @var EntityManagerInterface&MockObject */
    private EntityManagerInterface $em;
    private AdBatchPlanner $planner;

    protected function setUp(): void
    {
        $this->ozonClient = $this->createMock(OzonAdClient::class);
        $this->batchRepo = $this->createMock(AdScheduledBatchRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);

        $this->planner = new AdBatchPlanner(
            $this->ozonClient,
            $this->batchRepo,
            $this->em,
            new NullLogger(),
        );
    }

    public function testPlansCorrectNumberOfBatchesFor260Campaigns(): void
    {
        $this->batchRepo->method('findByJobId')->willReturn([]);
        $this->ozonClient->method('listAllSkuCampaigns')->willReturn(
            $this->buildCampaigns(260),
        );

        $saved = [];
        $this->batchRepo->expects(self::exactly(26))
            ->method('save')
            ->willReturnCallback(static function (AdScheduledBatch $batch) use (&$saved): void {
                $saved[] = $batch;
            });
        $this->em->expects(self::once())->method('flush');

        $count = $this->planner->planBatchesForJob(
            self::JOB_ID,
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-30'),
        );

        self::assertSame(26, $count);
        self::assertCount(26, $saved);
    }

    public function testScheduledAtIsSpacedBy120Seconds(): void
    {
        $this->batchRepo->method('findByJobId')->willReturn([]);
        $this->ozonClient->method('listAllSkuCampaigns')->willReturn(
            $this->buildCampaigns(30), // 30 → ровно 3 батча, легко проверить 3 timestamp'а
        );

        $saved = [];
        $this->batchRepo->method('save')->willReturnCallback(
            static function (AdScheduledBatch $batch) use (&$saved): void {
                $saved[] = $batch;
            },
        );

        $this->planner->planBatchesForJob(
            self::JOB_ID,
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );

        self::assertCount(3, $saved);

        $base = $saved[0]->getScheduledAt();
        self::assertSame(120, $saved[1]->getScheduledAt()->getTimestamp() - $base->getTimestamp());
        self::assertSame(240, $saved[2]->getScheduledAt()->getTimestamp() - $base->getTimestamp());
    }

    public function testAllBatchesStartInPlannedStateWithZeroRetry(): void
    {
        $this->batchRepo->method('findByJobId')->willReturn([]);
        $this->ozonClient->method('listAllSkuCampaigns')->willReturn(
            $this->buildCampaigns(25),
        );

        $saved = [];
        $this->batchRepo->method('save')->willReturnCallback(
            static function (AdScheduledBatch $batch) use (&$saved): void {
                $saved[] = $batch;
            },
        );

        $this->planner->planBatchesForJob(
            self::JOB_ID,
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );

        self::assertCount(3, $saved); // 25 → 10+10+5
        foreach ($saved as $batch) {
            self::assertSame(AdScheduledBatchState::PLANNED, $batch->getState());
            self::assertSame(0, $batch->getRetryCount());
            self::assertSame(self::JOB_ID, $batch->getJobId());
            self::assertSame(self::COMPANY_ID, $batch->getCompanyId());
        }

        self::assertCount(10, $saved[0]->getCampaignIds());
        self::assertCount(10, $saved[1]->getCampaignIds());
        self::assertCount(5, $saved[2]->getCampaignIds(), 'Хвост последнего чанка — 5 кампаний');

        self::assertSame(0, $saved[0]->getBatchIndex());
        self::assertSame(1, $saved[1]->getBatchIndex());
        self::assertSame(2, $saved[2]->getBatchIndex());
    }

    public function testEmptyCampaignsThrowsRuntimeException(): void
    {
        $this->batchRepo->method('findByJobId')->willReturn([]);
        $this->ozonClient->method('listAllSkuCampaigns')->willReturn([]);

        $this->batchRepo->expects(self::never())->method('save');
        $this->em->expects(self::never())->method('flush');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No SKU campaigns found/');

        $this->planner->planBatchesForJob(
            self::JOB_ID,
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );
    }

    public function testAlreadyPlannedJobIsIdempotentAndReturnsExistingCount(): void
    {
        // Первый вызов findByJobId возвращает 3 существующих батча — planner должен
        // выйти рано, не вызывая Ozon и не создавая новых.
        $existing = [
            $this->buildExistingBatch(0),
            $this->buildExistingBatch(1),
            $this->buildExistingBatch(2),
        ];

        $this->batchRepo->expects(self::once())
            ->method('findByJobId')
            ->with(self::JOB_ID, self::COMPANY_ID)
            ->willReturn($existing);

        $this->ozonClient->expects(self::never())->method('listAllSkuCampaigns');
        $this->batchRepo->expects(self::never())->method('save');
        $this->em->expects(self::never())->method('flush');

        $count = $this->planner->planBatchesForJob(
            self::JOB_ID,
            self::COMPANY_ID,
            new \DateTimeImmutable('2026-04-01'),
            new \DateTimeImmutable('2026-04-10'),
        );

        self::assertSame(3, $count);
    }

    /**
     * @return list<array{id: string, title: string, state: string}>
     */
    private function buildCampaigns(int $count): array
    {
        /** @var list<array{id: string, title: string, state: string}> $campaigns */
        $campaigns = [];
        for ($i = 0; $i < $count; ++$i) {
            $campaigns[] = [
                'id' => sprintf('camp-%06d', $i),
                'title' => sprintf('Campaign %d', $i),
                'state' => 'CAMPAIGN_STATE_RUNNING',
            ];
        }

        return $campaigns;
    }

    private function buildExistingBatch(int $index): AdScheduledBatch
    {
        return new AdScheduledBatch(
            id: sprintf('bbbbbbbb-bbbb-bbbb-bbbb-%012d', $index),
            jobId: self::JOB_ID,
            companyId: self::COMPANY_ID,
            campaignIds: ['c-1'],
            dateFrom: new \DateTimeImmutable('2026-04-01'),
            dateTo: new \DateTimeImmutable('2026-04-10'),
            batchIndex: $index,
            scheduledAt: new \DateTimeImmutable(),
        );
    }
}
