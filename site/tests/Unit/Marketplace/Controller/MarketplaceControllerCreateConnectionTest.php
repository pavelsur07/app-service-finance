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
use App\Marketplace\Infrastructure\Api\Ozon\OzonCredentialValidationResult;
use App\Marketplace\Infrastructure\Api\Ozon\OzonCredentialValidationStatus;
use App\Marketplace\Infrastructure\Api\Ozon\OzonSellerCredentialValidatorInterface;
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

    public function testCreateValidOzonConnectionStoresActiveConnectionAndDispatchesInitialTrigger(): void
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

        $validator = $this->createMock(OzonSellerCredentialValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('client-id', 'api-key')
            ->willReturn(OzonCredentialValidationResult::valid());

        $this->controller($companyService, $connectionRepository, $em, $messageBus, $startDateResolver, $planner, $validator)
            ->createConnection($this->request(MarketplaceType::OZON, 'client-id'));

        self::assertInstanceOf(MarketplaceConnection::class, $createdConnection);
        self::assertTrue($createdConnection->isActive());
        self::assertNull($createdConnection->getLastSyncError());
    }

    public function testCreateInvalidOzonConnectionStoresInactiveConnectionWithErrorAndDoesNotDispatchInitialTrigger(): void
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
        $messageBus->expects(self::never())->method('dispatch');

        $startDateResolver = $this->createMock(WbInitialSyncStartDateResolver::class);
        $startDateResolver->expects(self::never())->method('resolve');

        $planner = $this->createMock(WbFinancialReportSyncPlannerInterface::class);
        $planner->expects(self::never())->method('planInitial');

        $validator = $this->createMock(OzonSellerCredentialValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('client-id', 'api-key')
            ->willReturn(new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::INVALID_CREDENTIALS,
                'Ozon отклонил Client-Id или Api-Key. Проверьте ключ Seller API.',
                403,
            ));

        $this->controller($companyService, $connectionRepository, $em, $messageBus, $startDateResolver, $planner, $validator)
            ->createConnection($this->request(MarketplaceType::OZON, 'client-id'));

        self::assertInstanceOf(MarketplaceConnection::class, $createdConnection);
        self::assertFalse($createdConnection->isActive());
        self::assertSame('Ozon отклонил Client-Id или Api-Key. Проверьте ключ Seller API.', $createdConnection->getLastSyncError());
    }

    public function testManualSyncDoesNotStartForInactiveConnectionWithStoredError(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('11111111-1111-4111-8111-111111111111', $company, MarketplaceType::OZON);
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');
        $connection->setIsActive(false);
        $connection->setLastSyncError('Ozon отклонил ключ.');

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('find')->with($connection->getId())->willReturn($connection);

        $em = $this->createMock(EntityManagerInterface::class);
        $messageBus = $this->createMock(MessageBusInterface::class);

        $response = $this->controller(
            $companyService,
            $connectionRepository,
            $em,
            $messageBus,
            $this->createMock(WbInitialSyncStartDateResolver::class),
            $this->createMock(WbFinancialReportSyncPlannerInterface::class),
        )->syncConnection($connection->getId());

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testTestConnectionActivatesValidOzonConnectionAndClearsError(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('22222222-2222-4222-8222-222222222222', $company, MarketplaceType::OZON);
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');
        $connection->setIsActive(false);
        $connection->setLastSyncError('old error');

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('find')->with($connection->getId())->willReturn($connection);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $validator = $this->createMock(OzonSellerCredentialValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('client-id', 'api-key')
            ->willReturn(OzonCredentialValidationResult::valid());

        $response = $this->controller(
            $companyService,
            $connectionRepository,
            $em,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(WbInitialSyncStartDateResolver::class),
            $this->createMock(WbFinancialReportSyncPlannerInterface::class),
            $validator,
        )->testConnection($connection->getId());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertTrue($connection->isActive());
        self::assertNull($connection->getLastSyncError());
    }

    public function testTestConnectionDeactivatesInvalidOzonConnectionAndStoresError(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('33333333-3333-4333-8333-333333333333', $company, MarketplaceType::OZON);
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('find')->with($connection->getId())->willReturn($connection);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $validator = $this->createMock(OzonSellerCredentialValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('client-id', 'api-key')
            ->willReturn(new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::INVALID_CREDENTIALS,
                'Ozon отклонил Client-Id или Api-Key. Проверьте ключ Seller API.',
                403,
            ));

        $response = $this->controller(
            $companyService,
            $connectionRepository,
            $em,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(WbInitialSyncStartDateResolver::class),
            $this->createMock(WbFinancialReportSyncPlannerInterface::class),
            $validator,
        )->testConnection($connection->getId());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertFalse($connection->isActive());
        self::assertSame('Ozon отклонил Client-Id или Api-Key. Проверьте ключ Seller API.', $connection->getLastSyncError());
    }

    public function testTestConnectionPreservesActiveOzonConnectionOnTemporaryValidationError(): void
    {
        $company = CompanyBuilder::aCompany()->build();
        $connection = new MarketplaceConnection('44444444-4444-4444-8444-444444444444', $company, MarketplaceType::OZON);
        $connection->setApiKey('api-key');
        $connection->setClientId('client-id');
        $connection->setIsActive(true);

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $connectionRepository = $this->createMock(MarketplaceConnectionRepository::class);
        $connectionRepository->method('find')->with($connection->getId())->willReturn($connection);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $validator = $this->createMock(OzonSellerCredentialValidatorInterface::class);
        $validator->expects(self::once())
            ->method('validate')
            ->with('client-id', 'api-key')
            ->willReturn(new OzonCredentialValidationResult(
                OzonCredentialValidationStatus::TEMPORARY_ERROR,
                'Ozon Seller API временно недоступен. Повторите проверку позже.',
                500,
            ));

        $response = $this->controller(
            $companyService,
            $connectionRepository,
            $em,
            $this->createMock(MessageBusInterface::class),
            $this->createMock(WbInitialSyncStartDateResolver::class),
            $this->createMock(WbFinancialReportSyncPlannerInterface::class),
            $validator,
        )->testConnection($connection->getId());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertTrue($connection->isActive());
        self::assertSame('Ozon Seller API временно недоступен. Повторите проверку позже.', $connection->getLastSyncError());
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
        ?OzonSellerCredentialValidatorInterface $ozonCredentialValidator = null,
    ): MarketplaceController {
        $ozonCredentialValidator ??= $this->createMock(OzonSellerCredentialValidatorInterface::class);

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
            $ozonCredentialValidator,
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
