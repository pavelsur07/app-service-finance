<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Ingestion\Application\Source\Wildberries\WbResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Facade\RawStorageFacade;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WbFinancePreviewNormalizationCommandTest extends IntegrationTestCase
{
    public function testWindowlessRecordsAreSelectedByReportDateFromExternalId(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $connectionRef = Uuid::uuid7()->toString();
        $job = new SyncJob(
            companyId: $companyId,
            connectionRef: $connectionRef,
            source: IngestSource::WILDBERRIES,
            resourceType: WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            kind: SyncJobKind::INCREMENTAL,
            shopRef: $connectionRef,
        );

        $this->em->persist($job);
        $this->em->flush();

        /** @var RawStorageFacade $rawStorage */
        $rawStorage = self::getContainer()->get(RawStorageFacade::class);
        $rawStorage->store($this->batch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            syncJobId: $job->getId(),
            externalId: 'wb-sales-report-detailed:2026-06-20:rrd-0',
            fetchedAt: new \DateTimeImmutable('2026-06-21 10:00:00+00:00'),
        ));
        $rawStorage->store($this->batch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            syncJobId: $job->getId(),
            externalId: 'wb-sales-report-detailed:2026-06-21:rrd-0',
            fetchedAt: new \DateTimeImmutable('2026-06-22 09:17:43+00:00'),
        ));

        $tester = $this->tester();
        $exit = $tester->execute([
            '--company-id' => $companyId,
            '--from' => '2026-06-21',
            '--to' => '2026-06-21',
            '--shop-ref' => $connectionRef,
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        $display = $tester->getDisplay();
        self::assertStringContainsString('wb-sales-report-detailed:2026-06-21:rrd-0', $display);
        self::assertStringNotContainsString('wb-sales-report-detailed:2026-06-20:rrd-0', $display);
        self::assertStringContainsString('No unknown operation rows.', $display);
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find('app:ingestion:wb-finance:preview-normalization'));
    }

    private function batch(
        string $companyId,
        string $connectionRef,
        string $syncJobId,
        string $externalId,
        \DateTimeImmutable $fetchedAt,
    ): RawBatch {
        return new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::WILDBERRIES,
            resourceType: WbResourceType::FINANCE_SALES_REPORT_DETAILED,
            externalId: $externalId,
            syncJobId: $syncJobId,
            fetchedAt: $fetchedAt,
            rows: [[
                'rrdId' => 1,
                'rrDate' => '2026-06-21',
                'currency' => 'RUB',
                'sellerOperName' => '',
                'docTypeName' => '',
            ]],
        );
    }
}
