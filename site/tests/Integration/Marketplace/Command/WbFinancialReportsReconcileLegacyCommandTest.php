<?php

declare(strict_types=1);

namespace App\Tests\Integration\Marketplace\Command;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceCost;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WbFinancialReportsReconcileLegacyCommandTest extends IntegrationTestCase
{
    public function testDryRunDoesNotPersistStatuses(): void
    {
        [$company, $connection] = $this->seedWbConnection();
        $this->seedRaw($company, '2026-01-02', PipelineStatus::COMPLETED, 3, 'legacy::wb-endpoint');

        $exit = $this->tester()->execute(['--from' => '2026-01-02', '--to' => '2026-01-02', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertSame(0, $this->countStatuses($company->getId(), $connection->getId()));
    }

    public function testCompletedRawCreatesSuccessWithRawDocumentAndEndpoint(): void
    {
        [$company, $connection] = $this->seedWbConnection();
        $raw = $this->seedRaw($company, '2026-01-03', PipelineStatus::COMPLETED, 2, 'legacy::old-api');

        $exit = $this->tester()->execute(['--from' => '2026-01-03', '--to' => '2026-01-03']);

        self::assertSame(Command::SUCCESS, $exit);
        $status = $this->findStatus($company->getId(), $connection->getId(), '2026-01-03');
        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $status);
        self::assertSame(FinancialReportSyncStatus::SUCCESS, $status->getStatus());
        self::assertSame($raw->getId(), $status->getRawDocumentId());
        self::assertSame('legacy::old-api', $status->getApiEndpoint());
    }

    public function testFailedRawCreatesFailedWithRawDocumentId(): void
    {
        [$company, $connection] = $this->seedWbConnection();
        $raw = $this->seedRaw($company, '2026-01-08', PipelineStatus::FAILED, 3, 'legacy::failed-api');

        $exit = $this->tester()->execute(['--from' => '2026-01-08', '--to' => '2026-01-08']);

        self::assertSame(Command::SUCCESS, $exit);
        $status = $this->findStatus($company->getId(), $connection->getId(), '2026-01-08');
        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $status);
        self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());
        self::assertSame($raw->getId(), $status->getRawDocumentId());
    }

    public function testPendingRawCreatesNonMissingStatus(): void
    {
        [$company, $connection] = $this->seedWbConnection();
        $this->seedRaw($company, '2026-01-04', PipelineStatus::PENDING, 1, 'legacy::old-api');

        $exit = $this->tester()->execute(['--from' => '2026-01-04', '--to' => '2026-01-04']);

        self::assertSame(Command::SUCCESS, $exit);
        $status = $this->findStatus($company->getId(), $connection->getId(), '2026-01-04');
        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $status);
        self::assertContains($status->getStatus(), [FinancialReportSyncStatus::PROCESSING, FinancialReportSyncStatus::CONFLICT]);
    }

    public function testGeneratedCostRowsWithoutRawCreateSuccessWithLegacyEndpoint(): void
    {
        [$company, $connection] = $this->seedWbConnection();
        $this->seedCost($company, new \DateTimeImmutable('2026-01-06 12:34:00'));

        $exit = $this->tester()->execute(['--from' => '2026-01-06', '--to' => '2026-01-06']);

        self::assertSame(Command::SUCCESS, $exit);
        $status = $this->findStatus($company->getId(), $connection->getId(), '2026-01-06');
        self::assertInstanceOf(MarketplaceFinancialReportSyncStatus::class, $status);
        self::assertSame(FinancialReportSyncStatus::SUCCESS, $status->getStatus());
        self::assertSame('legacy_generated_rows', $status->getApiEndpoint());
    }

    public function testNoRawNoGeneratedRowsCreatesNoStatus(): void
    {
        [$company, $connection] = $this->seedWbConnection();

        $exit = $this->tester()->execute(['--from' => '2026-01-07', '--to' => '2026-01-07']);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertNull($this->findStatus($company->getId(), $connection->getId(), '2026-01-07'));
    }

    public function testFromGreaterThanToReturnsFailure(): void
    {
        $exit = $this->tester()->execute(['--from' => '2026-01-10', '--to' => '2026-01-01']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testInvalidCompanyIdReturnsFailure(): void
    {
        $exit = $this->tester()->execute(['--company-id' => 'invalid-uuid']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testInvalidConnectionIdReturnsFailure(): void
    {
        $exit = $this->tester()->execute(['--connection-id' => 'invalid-uuid']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testNegativeLimitReturnsFailure(): void
    {
        $exit = $this->tester()->execute(['--limit' => '-1']);

        self::assertSame(Command::FAILURE, $exit);
    }

    public function testRepeatRunIsIdempotent(): void
    {
        [$company, $connection] = $this->seedWbConnection();
        $this->seedRaw($company, '2026-01-05', PipelineStatus::COMPLETED, 1, 'legacy::old-api');

        $this->tester()->execute(['--from' => '2026-01-05', '--to' => '2026-01-05']);
        $this->tester()->execute(['--from' => '2026-01-05', '--to' => '2026-01-05']);

        self::assertSame(1, $this->countStatuses($company->getId(), $connection->getId()));
    }

    private function seedWbConnection(): array
    {
        $owner = UserBuilder::aUser()->withEmail(sprintf('wb-owner-%s@example.test', Uuid::uuid7()->toString()))->build();
        $company = CompanyBuilder::aCompany()->withOwner($owner)->build();

        $connection = new MarketplaceConnection(Uuid::uuid7()->toString(), $company, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('api-key')->setIsActive(true);

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();

        return [$company, $connection];
    }

    private function seedCost(Company $company, \DateTimeImmutable $costDate): void
    {
        $cost = new MarketplaceCost(Uuid::uuid7()->toString(), $company, MarketplaceType::WILDBERRIES);
        $cost->setAmount('100.00');
        $cost->setCostDate($costDate);

        $this->em->persist($cost);
        $this->em->flush();
    }

    private function seedRaw(Company $company, string $day, PipelineStatus $status, int $records, string $apiEndpoint): MarketplaceRawDocument
    {
        $raw = new MarketplaceRawDocument(Uuid::uuid7()->toString(), $company, MarketplaceType::WILDBERRIES, 'sales_report');
        $date = new \DateTimeImmutable($day);
        $raw->setPeriodFrom($date)->setPeriodTo($date)->setApiEndpoint($apiEndpoint)->setRecordsCount($records)->setRawData([['k' => 'v']]);
        if ($status === PipelineStatus::COMPLETED) {
            $raw->markCompleted();
        } elseif ($status === PipelineStatus::FAILED) {
            $raw->markFailed(['sales']);
        } elseif ($status === PipelineStatus::RUNNING) {
            $raw->markRunning();
        } else {
            $raw->resetProcessingStatus();
        }

        $this->em->persist($raw);
        $this->em->flush();

        return $raw;
    }

    private function findStatus(string $companyId, string $connectionId, string $day): ?MarketplaceFinancialReportSyncStatus
    {
        return $this->em->getRepository(MarketplaceFinancialReportSyncStatus::class)->findOneBy([
            'companyId' => $companyId,
            'connectionId' => $connectionId,
            'businessDate' => new \DateTimeImmutable($day),
            'reportType' => 'sales_report',
        ]);
    }

    private function countStatuses(string $companyId, string $connectionId): int
    {
        return $this->em->getRepository(MarketplaceFinancialReportSyncStatus::class)->count([
            'companyId' => $companyId,
            'connectionId' => $connectionId,
            'reportType' => 'sales_report',
        ]);
    }

    private function tester(): CommandTester
    {
        $app = new Application(self::bootKernel());

        return new CommandTester($app->find('app:marketplace:wb-financial-reports:reconcile-legacy'));
    }
}
