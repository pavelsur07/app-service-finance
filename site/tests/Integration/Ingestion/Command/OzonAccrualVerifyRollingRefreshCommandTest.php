<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Command;

use App\Company\Entity\Company;
use App\Ingestion\Application\Source\Ozon\OzonResourceType;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Message\NormalizeRawRecordMessage;
use App\Ingestion\MessageHandler\NormalizeRawRecordHandler;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OzonAccrualVerifyRollingRefreshCommandTest extends IntegrationTestCase
{
    public function testPassesWhenLatestRawMatchesCanonicalTransactions(): void
    {
        $company = $this->seedCompany(2201);
        $connection = $this->seedConnection($company, '77777777-7777-7777-7777-000000002201');
        $date = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        $rawRecord = $this->storeAccrualRaw($company->getId(), $connection->getId(), $date, 220100);

        /** @var NormalizeRawRecordHandler $handler */
        $handler = self::getContainer()->get(NormalizeRawRecordHandler::class);
        $handler(new NormalizeRawRecordMessage($rawRecord->getId(), $company->getId()));
        $this->em->clear();

        /** @var TestHandler $logHandler */
        $logHandler = self::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $tester = $this->tester('app:ingestion:ozon-accrual:verify-rolling-refresh');
        $exit = $tester->execute([
            '--company-id' => $company->getId(),
            '--days-back' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('ok', $tester->getDisplay());
        self::assertStringContainsString('amountMismatches', $tester->getDisplay());
        self::assertFalse($logHandler->hasErrorRecords());
    }

    public function testFailsWhenDoneRawHasNoCanonicalTransactions(): void
    {
        $company = $this->seedCompany(2202);
        $connection = $this->seedConnection($company, '77777777-7777-7777-7777-000000002202');
        $date = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        $rawRecord = $this->storeAccrualRaw($company->getId(), $connection->getId(), $date, 220200);
        $rawRecord->markNormalizationDone();
        $this->em->flush();

        /** @var TestHandler $logHandler */
        $logHandler = self::getContainer()->get(TestHandler::class);
        $logHandler->clear();

        $tester = $this->tester('app:ingestion:ozon-accrual:verify-rolling-refresh');
        $exit = $tester->execute([
            '--company-id' => $company->getId(),
            '--days-back' => '2',
        ]);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('fail', $tester->getDisplay());
        self::assertStringContainsString($company->getId(), $tester->getDisplay());
        self::assertStringContainsString($connection->getId(), $tester->getDisplay());
        self::assertStringContainsString('amountMismatches', $tester->getDisplay());
        self::assertStringContainsString('countMismatches', $tester->getDisplay());
        self::assertTrue($logHandler->hasErrorThatPasses(
            static function (LogRecord $record) use ($company, $connection): bool {
                $details = $record->context['failedTargetDetails'][0] ?? null;

                return 'Ozon accrual rolling refresh verification found mismatches.' === $record->message
                    && 1 === ($record->context['failedTargets'] ?? null)
                    && is_array($details)
                    && $company->getId() === ($details['companyId'] ?? null)
                    && $connection->getId() === ($details['shopRef'] ?? null)
                    && !array_key_exists('ok', $details)
                    && ($details['amountMismatches'] ?? 0) > 0
                    && ($details['countMismatches'] ?? 0) > 0;
            },
        ));
    }

    private function seedCompany(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function seedConnection(Company $company, string $id): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            id: $id,
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function storeAccrualRaw(string $companyId, string $connectionRef, string $date, int $accrualId): IngestRawRecord
    {
        /** @var RawStorageFacade $rawStorageFacade */
        $rawStorageFacade = self::getContainer()->get(RawStorageFacade::class);

        $records = $rawStorageFacade->store(new RawBatch(
            companyId: $companyId,
            connectionRef: $connectionRef,
            shopRef: $connectionRef,
            source: IngestSource::OZON,
            resourceType: OzonResourceType::ACCRUAL_BY_DAY,
            externalId: sprintf('accrual-by-day:%s:%s', $date, $date),
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: new \DateTimeImmutable(sprintf('%s 10:00:00+00:00', $date)),
            rows: [[
                'accrual_id' => $accrualId,
                'date' => $date,
                'accrued_category' => 'POSTING',
                'posting' => [
                    'products' => [[
                        'commission' => [
                            'sale_amount' => ['amount' => '100.00', 'currency' => 'RUB'],
                            'commission' => ['amount' => '-10.00', 'currency' => 'RUB'],
                        ],
                    ]],
                ],
            ]],
        ));
        $this->em->flush();

        return $records[0];
    }

    private function tester(string $commandName): CommandTester
    {
        $app = new Application(self::$kernel);

        return new CommandTester($app->find($commandName));
    }
}
