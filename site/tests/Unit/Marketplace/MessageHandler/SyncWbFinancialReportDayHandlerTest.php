<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\MessageHandler;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncError;
use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\FinancialReportSyncMode;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Infrastructure\Api\Wildberries\WbFinanceSalesReportClient;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Message\SyncWbFinancialReportDayMessage;
use App\Marketplace\MessageHandler\SyncWbFinancialReportDayHandler;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncWbFinancialReportDayHandlerTest extends IntegrationTestCase
{
    public function testServiceWiring(): void
    {
        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);

        self::assertInstanceOf(SyncWbFinancialReportDayHandler::class, $handler);
    }

    public function testTemporaryApiExceptionMarksRetryableFailedAndStoresError(): void
    {
        $company = $this->createCompany(9101);
        $connection = $this->createWbSellerConnection($company, 9101);

        $this->swapWbClient([new MockResponse('{"error":"temporary"}', ['http_code' => 500])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);

        $this->expectException(RecoverableMessageHandlingException::class);
        try {
            $handler(new SyncWbFinancialReportDayMessage(
                companyId: $company->getId(),
                connectionId: $connection->getId(),
                businessDate: '2026-05-19',
                mode: FinancialReportSyncMode::MANUAL->value,
                forceRefresh: false,
            ));
        } finally {
            $status = $this->findStatus($connection->getId(), $company->getId(), '2026-05-19');
            self::assertNotNull($status);
            self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());

            $error = $this->findLastError($status->getId());
            self::assertNotNull($error);
            self::assertSame('App\Marketplace\Exception\MarketplaceTemporaryApiException', $error->getErrorClass());
        }
    }

    public function testConflictMarksConflictAndThrowsUnrecoverable(): void
    {
        $company = $this->createCompany(9102);
        $connection = $this->createWbSellerConnection($company, 9102);
        $businessDate = new \DateTimeImmutable('2026-05-19');

        $existingRaw = new MarketplaceRawDocument(
            'bbbbbbbb-bbbb-4bbb-8bbb-000000009102',
            $company,
            MarketplaceType::WILDBERRIES,
            'sales_report',
        );
        $existingRaw->setPeriodFrom($businessDate);
        $existingRaw->setPeriodTo($businessDate);
        $existingRaw->setApiEndpoint('wildberries::finance-sales-reports-detailed');
        $existingRaw->setRawData([['rrdId' => 1]]);
        $existingRaw->setRecordsCount(1);
        $this->setRawDocumentProcessingStatus($existingRaw, PipelineStatus::RUNNING);
        $this->em->persist($existingRaw);
        $this->em->flush();

        $this->swapWbClient([new MockResponse('[{"rrdId":2}]', ['http_code' => 200])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);

        $this->expectException(UnrecoverableMessageHandlingException::class);
        try {
            $handler(new SyncWbFinancialReportDayMessage(
                companyId: $company->getId(),
                connectionId: $connection->getId(),
                businessDate: '2026-05-19',
                mode: FinancialReportSyncMode::MANUAL->value,
                forceRefresh: true,
            ));
        } finally {
            $status = $this->findStatus($connection->getId(), $company->getId(), '2026-05-19');
            self::assertNotNull($status);
            self::assertSame(FinancialReportSyncStatus::CONFLICT, $status->getStatus());

            $error = $this->findLastError($status->getId());
            self::assertNotNull($error);
            self::assertSame('App\Marketplace\Exception\WbRawDocumentRefreshConflictException', $error->getErrorClass());
        }
    }


    public function testForceRefreshIsPropagatedToProcessDayReportMessage(): void
    {
        $company = $this->createCompany(9103);
        $connection = $this->createWbSellerConnection($company, 9103);

        $this->swapWbClient([new MockResponse('[{"rrdId":2}]', ['http_code' => 200])]);

        $spyBus = new class() implements MessageBusInterface {
            /** @var list<object> */
            public array $messages = [];
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->messages[] = $message;
                return new Envelope($message, $stamps);
            }
        };

        self::getContainer()->set(MessageBusInterface::class, $spyBus);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler(new SyncWbFinancialReportDayMessage(
            companyId: $company->getId(),
            connectionId: $connection->getId(),
            businessDate: '2026-05-19',
            mode: FinancialReportSyncMode::MANUAL->value,
            forceRefresh: true,
        ));

        $dispatched = array_values(array_filter(
            $spyBus->messages,
            static fn (object $m): bool => $m instanceof ProcessDayReportMessage,
        ));

        self::assertCount(1, $dispatched);
        self::assertTrue($dispatched[0]->forceRefresh);
    }

    private function createCompany(int $index): Company
    {
        $user = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($user)->build();
        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function createWbSellerConnection(Company $company, int $suffix): MarketplaceConnection
    {
        $connection = new MarketplaceConnection(
            sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $suffix),
            $company,
            MarketplaceType::WILDBERRIES,
            MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('wb-test-token')->setIsActive(true);

        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    /** @param list<MockResponse> $responses */
    private function swapWbClient(array $responses): void
    {
        $client = new WbFinanceSalesReportClient(
            new MockHttpClient($responses),
            new MockClock('2026-05-20 12:00:00 UTC'),
        );

        self::getContainer()->set(WbFinanceSalesReportClient::class, $client);
    }


    private function setRawDocumentProcessingStatus(MarketplaceRawDocument $document, PipelineStatus $status): void
    {
        $reflection = new \ReflectionProperty($document, 'processingStatus');
        $reflection->setAccessible(true);
        $reflection->setValue($document, $status);
    }

    private function findStatus(string $connectionId, string $companyId, string $date): ?MarketplaceFinancialReportSyncStatus
    {
        return $this->em->getRepository(MarketplaceFinancialReportSyncStatus::class)->findOneBy([
            'connectionId' => $connectionId,
            'companyId' => $companyId,
            'businessDate' => new \DateTimeImmutable($date),
            'reportType' => 'sales_report',
        ]);
    }

    private function findLastError(string $statusId): ?MarketplaceFinancialReportSyncError
    {
        return $this->em->getRepository(MarketplaceFinancialReportSyncError::class)->findOneBy([
            'syncStatusId' => $statusId,
        ], ['createdAt' => 'DESC']);
    }
}
