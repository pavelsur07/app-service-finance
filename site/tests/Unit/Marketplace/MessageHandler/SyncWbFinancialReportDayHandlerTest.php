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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SyncWbFinancialReportDayHandlerTest extends IntegrationTestCase
{
    public function testServiceWiring(): void
    {
        self::assertInstanceOf(SyncWbFinancialReportDayHandler::class, self::getContainer()->get(SyncWbFinancialReportDayHandler::class));
    }

    public function testEmptyResponseMarksEmptyWithoutRawDocumentAndWithoutDispatch(): void
    {
        $company = $this->createCompany(9201);
        $connection = $this->createWbSellerConnection($company, 9201);
        $bus = $this->swapBusSpy();
        $this->swapWbClient([new MockResponse('', ['http_code' => 204])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler($this->message($company->getId(), $connection->getId(), false));

        $status = $this->findStatus($connection->getId(), $company->getId(), '2026-05-19');
        self::assertNotNull($status);
        self::assertSame(FinancialReportSyncStatus::EMPTY, $status->getStatus());
        self::assertSame(0, $this->countRawDocuments($company->getId(), '2026-05-19'));
        self::assertCount(0, $this->filterProcessMessages($bus->messages));
    }

    public function testRowsResponseCreatesRawDocumentMarksProcessingAndDispatches(): void
    {
        $company = $this->createCompany(9202);
        $connection = $this->createWbSellerConnection($company, 9202);
        $bus = $this->swapBusSpy();
        $this->swapWbClient([new MockResponse('[{"rrdId":11},{"rrdId":12}]', ['http_code' => 200])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler($this->message($company->getId(), $connection->getId(), false));

        $status = $this->findStatus($connection->getId(), $company->getId(), '2026-05-19');
        self::assertNotNull($status);
        self::assertSame(FinancialReportSyncStatus::PROCESSING, $status->getStatus());

        $raw = $this->findRawDocument($company->getId(), '2026-05-19');
        self::assertNotNull($raw);
        self::assertSame(2, $raw->getRecordsCount());

        $messages = $this->filterProcessMessages($bus->messages);
        self::assertCount(1, $messages);
        self::assertSame($raw->getId(), $messages[0]->rawDocumentId);
    }

    public function testTemporaryApiExceptionMarksRetryableFailedAndStoresError(): void
    {
        $company = $this->createCompany(9203);
        $connection = $this->createWbSellerConnection($company, 9203);
        $this->swapWbClient([new MockResponse('{"error":"temporary"}', ['http_code' => 500])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $this->expectException(RecoverableMessageHandlingException::class);

        try {
            $handler($this->message($company->getId(), $connection->getId(), false));
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
        $company = $this->createCompany(9204);
        $connection = $this->createWbSellerConnection($company, 9204);
        $businessDate = new \DateTimeImmutable('2026-05-19');

        $existingRaw = $this->createRaw($company, $businessDate, [['rrdId' => 1]], 1, PipelineStatus::RUNNING);
        $this->swapWbClient([new MockResponse('[{"rrdId":2}]', ['http_code' => 200])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            $handler($this->message($company->getId(), $connection->getId(), true));
        } finally {
            $status = $this->findStatus($connection->getId(), $company->getId(), '2026-05-19');
            self::assertNotNull($status);
            self::assertSame(FinancialReportSyncStatus::CONFLICT, $status->getStatus());

            $error = $this->findLastError($status->getId());
            self::assertNotNull($error);
            self::assertSame('App\Marketplace\Exception\WbRawDocumentRefreshConflictException', $error->getErrorClass());
            self::assertSame($existingRaw->getId(), $this->findRawDocument($company->getId(), '2026-05-19')?->getId());
        }
    }

    public function testForceRefreshIsPropagatedToProcessDayReportMessage(): void
    {
        $company = $this->createCompany(9205);
        $connection = $this->createWbSellerConnection($company, 9205);
        $bus = $this->swapBusSpy();
        $this->swapWbClient([new MockResponse('[{"rrdId":2}]', ['http_code' => 200])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler($this->message($company->getId(), $connection->getId(), true));

        $messages = $this->filterProcessMessages($bus->messages);
        self::assertCount(1, $messages);
        self::assertTrue($messages[0]->forceRefresh);
    }

    public function testExistingCompletedRawWithForceFalseUsesCanonicalRawDocument(): void
    {
        $company = $this->createCompany(9206);
        $connection = $this->createWbSellerConnection($company, 9206);
        $existing = $this->createRaw($company, new \DateTimeImmutable('2026-05-19'), [['rrdId' => 100]], 1, PipelineStatus::COMPLETED);
        $bus = $this->swapBusSpy();
        $this->swapWbClient([new MockResponse('[{"rrdId":200}]', ['http_code' => 200])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler($this->message($company->getId(), $connection->getId(), false));

        $raw = $this->findRawDocument($company->getId(), '2026-05-19');
        self::assertNotNull($raw);
        self::assertSame($existing->getId(), $raw->getId());
        self::assertSame([['rrdId' => 200]], $raw->getRawData());
        self::assertCount(1, $this->filterProcessMessages($bus->messages));
    }

    public function testExistingCompletedRawWithForceTrueRefreshesRawData(): void
    {
        $company = $this->createCompany(9207);
        $connection = $this->createWbSellerConnection($company, 9207);
        $existing = $this->createRaw($company, new \DateTimeImmutable('2026-05-19'), [['rrdId' => 300]], 1, PipelineStatus::COMPLETED);
        $this->swapWbClient([new MockResponse('[{"rrdId":301},{"rrdId":302}]', ['http_code' => 200])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler($this->message($company->getId(), $connection->getId(), true));

        $raw = $this->findRawDocument($company->getId(), '2026-05-19');
        self::assertNotNull($raw);
        self::assertSame($existing->getId(), $raw->getId());
        self::assertSame([['rrdId' => 301], ['rrdId' => 302]], $raw->getRawData());
        self::assertSame(2, $raw->getRecordsCount());
    }

    public function testRateLimitMarksFailedAndThrowsRecoverable(): void
    {
        $company = $this->createCompany(9208);
        $connection = $this->createWbSellerConnection($company, 9208);
        $this->swapWbClient([new MockResponse('{"error":"too many"}', ['http_code' => 429, 'response_headers' => ['x-ratelimit-retry: 120']])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $this->expectException(RecoverableMessageHandlingException::class);

        try {
            $handler($this->message($company->getId(), $connection->getId(), false));
        } finally {
            $status = $this->findStatus($connection->getId(), $company->getId(), '2026-05-19');
            self::assertNotNull($status);
            self::assertSame(FinancialReportSyncStatus::FAILED, $status->getStatus());
            self::assertNotNull($status->getNextRetryAt());

            $error = $this->findLastError($status->getId());
            self::assertNotNull($error);
            self::assertSame('App\Marketplace\Exception\MarketplaceRateLimitException', $error->getErrorClass());
        }
    }

    public function testAuthExceptionMarksAuthFailedAndStoresError(): void
    {
        $company = $this->createCompany(9209);
        $connection = $this->createWbSellerConnection($company, 9209);
        $this->swapWbClient([new MockResponse('{"error":"auth"}', ['http_code' => 401])]);

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $this->expectException(UnrecoverableMessageHandlingException::class);

        try {
            $handler($this->message($company->getId(), $connection->getId(), false));
        } finally {
            $status = $this->findStatus($connection->getId(), $company->getId(), '2026-05-19');
            self::assertNotNull($status);
            self::assertSame(FinancialReportSyncStatus::AUTH_FAILED, $status->getStatus());
            $error = $this->findLastError($status->getId());
            self::assertNotNull($error);
            self::assertSame('App\Marketplace\Exception\MarketplaceAuthException', $error->getErrorClass());
        }
    }

    public function testInvalidBusinessDateThrowsAndSkipsStatusCreation(): void
    {
        $company = $this->createCompany(9210);
        $connection = $this->createWbSellerConnection($company, 9210);
        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $this->expectException(\DomainException::class);

        try {
            $handler(new SyncWbFinancialReportDayMessage($company->getId(), $connection->getId(), '2026-02-31', FinancialReportSyncMode::MANUAL->value, false));
        } finally {
            self::assertNull($this->findStatus($connection->getId(), $company->getId(), '2026-02-28'));
        }
    }

    public function testInactiveConnectionSkips(): void
    {
        $company = $this->createCompany(9211);
        $connection = $this->createWbSellerConnection($company, 9211);
        $connection->setIsActive(false);
        $this->em->flush();

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler($this->message($company->getId(), $connection->getId(), false));
        self::assertNull($this->findStatus($connection->getId(), $company->getId(), '2026-05-19'));
    }

    public function testWrongMarketplaceOrConnectionTypeSkips(): void
    {
        $company = $this->createCompany(9212);

        $ozon = new MarketplaceConnection('aaaaaaaa-aaaa-4aaa-8aaa-000000009212', $company, MarketplaceType::OZON, MarketplaceConnectionType::SELLER);
        $ozon->setApiKey('oz')->setIsActive(true);
        $this->em->persist($ozon);

        $wbPerformance = new MarketplaceConnection('aaaaaaaa-aaaa-4aaa-8aaa-000000009213', $company, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::PERFORMANCE);
        $wbPerformance->setApiKey('wb')->setIsActive(true);
        $this->em->persist($wbPerformance);
        $this->em->flush();

        $handler = self::getContainer()->get(SyncWbFinancialReportDayHandler::class);
        $handler($this->message($company->getId(), $ozon->getId(), false));
        $handler($this->message($company->getId(), $wbPerformance->getId(), false));

        self::assertNull($this->findStatus($ozon->getId(), $company->getId(), '2026-05-19'));
        self::assertNull($this->findStatus($wbPerformance->getId(), $company->getId(), '2026-05-19'));
    }

    private function message(string $companyId, string $connectionId, bool $forceRefresh): SyncWbFinancialReportDayMessage
    {
        return new SyncWbFinancialReportDayMessage($companyId, $connectionId, '2026-05-19', FinancialReportSyncMode::MANUAL->value, $forceRefresh);
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
        $connection = new MarketplaceConnection(sprintf('aaaaaaaa-aaaa-4aaa-8aaa-%012d', $suffix), $company, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::SELLER);
        $connection->setApiKey('wb-test-token')->setIsActive(true);
        $this->em->persist($connection);
        $this->em->flush();

        return $connection;
    }

    private function createRaw(Company $company, \DateTimeImmutable $date, array $rows, int $count, PipelineStatus $status): MarketplaceRawDocument
    {
        $raw = new MarketplaceRawDocument(sprintf('bbbbbbbb-bbbb-4bbb-8bbb-%012d', random_int(1, 999999)), $company, MarketplaceType::WILDBERRIES, 'sales_report');
        $raw->setPeriodFrom($date);
        $raw->setPeriodTo($date);
        $raw->setApiEndpoint('wildberries::finance-sales-reports-detailed');
        $raw->setRawData($rows);
        $raw->setRecordsCount($count);
        $this->setRawDocumentProcessingStatus($raw, $status);
        $this->em->persist($raw);
        $this->em->flush();

        return $raw;
    }

    /** @param list<MockResponse> $responses */
    private function swapWbClient(array $responses): void
    {
        self::getContainer()->set(WbFinanceSalesReportClient::class, new WbFinanceSalesReportClient(new MockHttpClient($responses), new MockClock('2026-05-20 12:00:00 UTC')));
    }

    private function swapBusSpy(): object
    {
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

        return $spyBus;
    }

    /** @param list<object> $messages */
    private function filterProcessMessages(array $messages): array
    {
        return array_values(array_filter($messages, static fn (object $m): bool => $m instanceof ProcessDayReportMessage));
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

    private function findRawDocument(string $companyId, string $date): ?MarketplaceRawDocument
    {
        return $this->em->getRepository(MarketplaceRawDocument::class)->findOneBy([
            'company' => $companyId,
            'marketplace' => MarketplaceType::WILDBERRIES,
            'documentType' => 'sales_report',
            'periodFrom' => new \DateTimeImmutable($date),
            'periodTo' => new \DateTimeImmutable($date),
        ]);
    }

    private function countRawDocuments(string $companyId, string $date): int
    {
        return $this->em->getRepository(MarketplaceRawDocument::class)->count([
            'company' => $companyId,
            'marketplace' => MarketplaceType::WILDBERRIES,
            'documentType' => 'sales_report',
            'periodFrom' => new \DateTimeImmutable($date),
            'periodTo' => new \DateTimeImmutable($date),
        ]);
    }
}
