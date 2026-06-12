<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Controller;

use App\Marketplace\Application\ReprocessMarketplacePeriodAction;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Application\SyncConnectionAction;
use App\Marketplace\Controller\MarketplaceController;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\OzonRealizationStatusQuery;
use App\Marketplace\Infrastructure\Query\RawDocumentsListQuery;
use App\Marketplace\Infrastructure\Query\WbFinanceSyncStatusListQuery;
use App\Marketplace\Message\TriggerInitialSyncMessage;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Company\Repository\ProjectDirectionRepository;
use App\Shared\Service\ActiveCompanyService;
use App\Tests\Builders\Company\CompanyBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarketplaceControllerCreateConnectionTest extends TestCase
{
    public function testCreateWbConnectionDoesNotDispatchLegacyInitialTrigger(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $createdConnection = null;

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->expects(self::once())
            ->method('findByMarketplace')
            ->with($company, MarketplaceType::WILDBERRIES, MarketplaceConnectionType::SELLER)
            ->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (MarketplaceConnection $connection) use (&$createdConnection): bool {
                $createdConnection = $connection;

                return MarketplaceType::WILDBERRIES === $connection->getMarketplace();
            }));
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $startFrom = new \DateTimeImmutable('2026-01-01 00:00:00');
        $startDateResolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $startDateResolver->method('resolve')->willReturn($startFrom);

        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::once())
            ->method('planInitial')
            ->willReturn(1);

        $response = $this->controller($companyService, $connectionRepository, $em, $messageBus, $startDateResolver, $planner)
            ->createConnection($this->request(MarketplaceType::WILDBERRIES));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertInstanceOf(MarketplaceConnection::class, $createdConnection);
    }

    public function testCreateWbConnectionPlansInitialSyncThroughStatusBasedPlanner(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $createdConnection = null;

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('findByMarketplace')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (MarketplaceConnection $connection) use (&$createdConnection): bool {
                $createdConnection = $connection;

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::never())->method('dispatch');

        $startFrom = new \DateTimeImmutable('2026-02-01 00:00:00');
        $startDateResolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $startDateResolver->expects(self::once())
            ->method('resolve')
            ->with($company, self::callback(static function (MarketplaceConnection $connection) use (&$createdConnection): bool {
                $createdConnection = $connection;

                return true;
            }))
            ->willReturn($startFrom);

        $plannedConnectionId = null;
        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::once())
            ->method('planInitial')
            ->willReturnCallback(static function (?string $companyId, ?string $connectionId, ?\DateTimeImmutable $plannerStartFrom) use ($company, $startFrom, &$plannedConnectionId): int {
                self::assertSame((string) $company->getId(), $companyId);
                self::assertNotNull($connectionId);
                self::assertSame($startFrom, $plannerStartFrom);
                $plannedConnectionId = $connectionId;

                return 1;
            });

        $this->controller($companyService, $connectionRepository, $em, $messageBus, $startDateResolver, $planner)
            ->createConnection($this->request(MarketplaceType::WILDBERRIES));

        self::assertInstanceOf(MarketplaceConnection::class, $createdConnection);
        self::assertSame($createdConnection->getId(), $plannedConnectionId);
    }

    public function testCreateNonWbConnectionStillDispatchesLegacyInitialTrigger(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $createdConnection = null;

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('findByMarketplace')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')
            ->with(self::callback(static function (MarketplaceConnection $connection) use (&$createdConnection): bool {
                $createdConnection = $connection;

                return true;
            }));
        $em->expects(self::once())->method('flush');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $message) use ($company, &$createdConnection): bool {
                self::assertInstanceOf(TriggerInitialSyncMessage::class, $message);
                self::assertSame((string) $company->getId(), $message->companyId);
                self::assertSame($createdConnection->getId(), $message->connectionId);
                self::assertSame(MarketplaceType::OZON->value, $message->marketplace);

                return true;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $startDateResolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $startDateResolver->expects(self::never())->method('resolve');

        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::never())->method('planInitial');

        $this->controller($companyService, $connectionRepository, $em, $messageBus, $startDateResolver, $planner)
            ->createConnection($this->request(MarketplaceType::OZON, 'client-id'));
    }

    private function request(MarketplaceType $marketplace, ?string $clientId = null): Request
    {
        return new Request(request: [
            'marketplace' => $marketplace->value,
            'api_key' => 'api-key',
            'client_id' => $clientId,
        ]);
    }

    private function controller(
        ActiveCompanyService $companyService,
        MarketplaceConnectionRepository $connectionRepository,
        EntityManagerInterface $em,
        MessageBusInterface $messageBus,
        WbInitialSyncStartDateResolver $startDateResolver,
        WbFinancialReportSyncPlannerInterface $planner,
    ): MarketplaceController {
        return new class(
            $companyService,
            $connectionRepository,
            self::uninitialized(MarketplaceRawDocumentRepository::class),
            self::uninitialized(MarketplaceAdapterRegistry::class),
            self::uninitialized(OzonRealizationStatusQuery::class),
            self::uninitialized(RawDocumentsListQuery::class),
            self::uninitialized(ProjectDirectionRepository::class),
            $em,
            $messageBus,
            self::uninitialized(ReprocessMarketplacePeriodAction::class),
            self::uninitialized(SyncConnectionAction::class),
            $startDateResolver,
            $planner,
            self::uninitialized(WbFinanceSyncStatusListQuery::class),
        ) extends MarketplaceController {
            protected function addFlash(string $type, mixed $message): void
            {
            }

            protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                return new RedirectResponse('/'.$route, $status);
            }
        };
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return T
     */
    private static function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
