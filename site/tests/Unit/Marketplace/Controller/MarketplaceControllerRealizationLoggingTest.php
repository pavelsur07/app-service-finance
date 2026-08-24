<?php

declare(strict_types=1);

namespace App\Tests\Unit\Marketplace\Controller;

use App\Company\Repository\ProjectDirectionRepository;
use App\Marketplace\Application\ProcessMarketplaceRawDocumentAction;
use App\Marketplace\Application\ProcessOzonRealizationAction;
use App\Marketplace\Application\ReprocessMarketplacePeriodAction;
use App\Marketplace\Application\Service\WbFinancialReportSyncPlannerInterface;
use App\Marketplace\Application\Service\WbInitialSyncStartDateResolver;
use App\Marketplace\Application\SyncConnectionAction;
use App\Marketplace\Controller\MarketplaceController;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Ozon\OzonSellerCredentialValidatorInterface;
use App\Marketplace\Infrastructure\Query\OzonRealizationStatusQuery;
use App\Marketplace\Infrastructure\Query\RawDocumentsListQuery;
use App\Marketplace\Infrastructure\Query\WbFinanceSyncStatusListQuery;
use App\Marketplace\Infrastructure\Security\ConnectionApiKeyCodec;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Marketplace\Repository\MarketplaceOzonRealizationRepository;
use App\Marketplace\Repository\MarketplaceRawDocumentRepository;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\AppLogger;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Marketplace\MarketplaceRawDocumentBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Регрессия наблюдаемости: сбой обработки реализации обязан уйти в лог уровня ERROR.
 *
 * sentry.yaml отключает register_error_listener/register_error_handler, поэтому
 * в GlitchTip попадают ТОЛЬКО ERROR-записи Monolog. Контроллер ловит исключение
 * и показывает флеш — без явного лога инцидент остаётся полностью невидимым.
 */
final class MarketplaceControllerRealizationLoggingTest extends TestCase
{
    public function testFailedRealizationProcessingIsLoggedAsErrorWithException(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $rawDoc = MarketplaceRawDocumentBuilder::aDocument()
            ->forCompany($company)
            ->withMarketplace(MarketplaceType::OZON)
            ->withDocumentType('realization')
            ->build();

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $rawDocumentRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocumentRepository->method('find')->willReturn($rawDoc);

        $appLogger = $this->createMock(AppLogger::class);
        $appLogger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Ошибка обработки реализации'),
                self::isInstanceOf(\RuntimeException::class),
                self::callback(static function (array $context) use ($company, $rawDoc): bool {
                    self::assertSame((string) $company->getId(), $context['companyId']);
                    self::assertSame((string) $rawDoc->getId(), $context['rawDocumentId']);

                    return true;
                }),
            );

        $controller = $this->controller($companyService, $rawDocumentRepository, $appLogger);

        $response = $controller->processRealization(
            (string) $rawDoc->getId(),
            new Request(request: ['_token' => 'valid']),
            $this->failingAction(),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testFailedPeriodReprocessIsLoggedAsErrorWithException(): void
    {
        $company = CompanyBuilder::aCompany()->build();

        $companyService = $this->createMock(ActiveCompanyService::class);
        $companyService->method('getActiveCompany')->willReturn($company);

        $appLogger = $this->createMock(AppLogger::class);
        $appLogger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Ошибка переобработки'),
                self::isInstanceOf(\RuntimeException::class),
                self::callback(static function (array $context) use ($company): bool {
                    self::assertSame((string) $company->getId(), $context['companyId']);
                    self::assertSame('ozon', $context['marketplace']);
                    self::assertSame('2026-01-01', $context['periodFrom']);
                    self::assertSame('2026-01-31', $context['periodTo']);
                    self::assertSame('realization', $context['type']);

                    return true;
                }),
            );

        $controller = $this->controller(
            $companyService,
            $this->createMock(MarketplaceRawDocumentRepository::class),
            $appLogger,
            $this->failingReprocessAction(),
        );

        $response = $controller->reprocess(new Request(request: [
            '_token' => 'valid',
            'marketplace' => 'ozon',
            'period_from' => '2026-01-01',
            'period_to' => '2026-01-31',
            'type' => 'realization',
        ]));

        self::assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * Action, который гарантированно падает: его репозиторий не находит документ.
     * Класс final, поэтому мокаем не сам Action, а его зависимость.
     */
    private function failingAction(): ProcessOzonRealizationAction
    {
        $rawDocumentRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocumentRepository->method('find')->willReturn(null);

        return new ProcessOzonRealizationAction(
            $rawDocumentRepository,
            self::uninitialized(MarketplaceOzonRealizationRepository::class),
            self::uninitialized(MarketplaceListingRepository::class),
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
        );
    }

    /**
     * Action переобработки падает на первом же обращении к репозиторию.
     * Класс final, поэтому мокаем не сам Action, а его зависимость.
     */
    private function failingReprocessAction(): ReprocessMarketplacePeriodAction
    {
        $rawDocumentRepository = $this->createMock(MarketplaceRawDocumentRepository::class);
        $rawDocumentRepository->method('findByCompanyAndPeriod')
            ->willThrowException(new \RuntimeException('reprocess exploded'));

        return new ReprocessMarketplacePeriodAction(
            $rawDocumentRepository,
            self::uninitialized(ProcessMarketplaceRawDocumentAction::class),
            self::uninitialized(ProcessOzonRealizationAction::class),
            new NullLogger(),
        );
    }

    private function controller(
        ActiveCompanyService $companyService,
        MarketplaceRawDocumentRepository $rawDocumentRepository,
        AppLogger $appLogger,
        ?ReprocessMarketplacePeriodAction $reprocessAction = null,
    ): MarketplaceController {
        return new class($companyService, self::uninitialized(MarketplaceConnectionRepository::class), $rawDocumentRepository, self::uninitialized(MarketplaceAdapterRegistry::class), self::uninitialized(OzonRealizationStatusQuery::class), self::uninitialized(RawDocumentsListQuery::class), self::uninitialized(ProjectDirectionRepository::class), $this->createMock(EntityManagerInterface::class), $this->createMock(MessageBusInterface::class), $reprocessAction ?? self::uninitialized(ReprocessMarketplacePeriodAction::class), self::uninitialized(SyncConnectionAction::class), self::uninitialized(WbInitialSyncStartDateResolver::class), $this->createMock(WbFinancialReportSyncPlannerInterface::class), self::uninitialized(WbFinanceSyncStatusListQuery::class), $this->createMock(OzonSellerCredentialValidatorInterface::class), self::uninitialized(ConnectionApiKeyCodec::class), $appLogger) extends MarketplaceController {
            protected function addFlash(string $type, mixed $message): void
            {
            }

            protected function isCsrfTokenValid(string $id, ?string $token): bool
            {
                return true;
            }

            protected function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
            {
                return new RedirectResponse('/'.$route, $status);
            }
        };
    }

    private static function uninitialized(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }
}
