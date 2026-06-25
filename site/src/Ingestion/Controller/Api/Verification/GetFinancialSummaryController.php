<?php

declare(strict_types=1);

namespace App\Ingestion\Controller\Api\Verification;

use App\Ingestion\Application\Service\VerificationPeriodValidator;
use App\Ingestion\Facade\IngestionFacade;
use App\Shared\Service\ActiveCompanyService;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Ingestion verification')]
final class GetFinancialSummaryController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly IngestionFacade $ingestionFacade,
        private readonly VerificationPeriodValidator $periodValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[OA\Get(summary: 'Ingestion financial summary', tags: ['Ingestion verification'])]
    #[OA\Parameter(name: 'shop_ref', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'year_from', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 2020, maximum: 2100))]
    #[OA\Parameter(name: 'month_from', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))]
    #[OA\Parameter(name: 'year_to', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 2020, maximum: 2100))]
    #[OA\Parameter(name: 'month_to', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))]
    #[OA\Response(
        response: 200,
        description: 'Financial summary',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'by_month',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'year', type: 'integer', example: 2026),
                            new OA\Property(property: 'month', type: 'integer', example: 5),
                            new OA\Property(property: 'income_minor', type: 'integer', example: 1500000000),
                            new OA\Property(property: 'expense_minor', type: 'integer', example: 800000000),
                            new OA\Property(property: 'net_minor', type: 'integer', example: 700000000),
                            new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
                        ],
                        type: 'object',
                    ),
                ),
                new OA\Property(
                    property: 'by_category',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'category_id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'category_name', type: 'string', example: 'Продажи'),
                            new OA\Property(property: 'flow', type: 'string', example: 'income'),
                            new OA\Property(property: 'amount_minor', type: 'integer', example: 1500000000),
                        ],
                        type: 'object',
                    ),
                ),
                new OA\Property(
                    property: 'marketplace_categories',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'source', type: 'string', example: 'ozon'),
                            new OA\Property(property: 'category_group', type: 'string', example: 'Услуги доставки'),
                            new OA\Property(property: 'category_name', type: 'string', example: 'Логистика'),
                            new OA\Property(property: 'type', type: 'string', example: 'fee'),
                            new OA\Property(property: 'direction', type: 'string', example: 'out'),
                            new OA\Property(property: 'amount_minor', type: 'integer', example: -5374795),
                            new OA\Property(property: 'tx_count', type: 'integer', example: 1418),
                            new OA\Property(property: 'sort_order', type: 'integer', example: 400),
                        ],
                        type: 'object',
                    ),
                ),
            ],
            type: 'object',
        ),
    )]
    #[OA\Response(
        response: 422,
        description: 'Invalid period range',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'error',
                    properties: [
                        new OA\Property(property: 'code', type: 'string', example: 'invalid_period_range'),
                        new OA\Property(property: 'message', type: 'string', example: 'Некорректный диапазон периода'),
                    ],
                    type: 'object',
                ),
            ],
            type: 'object',
        ),
    )]
    #[Route('/api/ingestion/verification/financial-summary', name: 'api_ingestion_verification_financial_summary', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $shopRef = $this->nullableString($request->query->get('shop_ref'));
        $yearFrom = $request->query->getInt('year_from');
        $monthFrom = $request->query->getInt('month_from');
        $yearTo = $request->query->getInt('year_to');
        $monthTo = $request->query->getInt('month_to');

        $this->periodValidator->assertMonthRange($yearFrom, $monthFrom, $yearTo, $monthTo);

        $this->logRequest($companyId);
        $summary = $this->ingestionFacade->getFinancialSummary(
            $companyId,
            $shopRef,
            $yearFrom,
            $monthFrom,
            $yearTo,
            $monthTo,
        );

        return $this->json([
            'by_month' => array_map(static fn ($item): array => $item->toArray(), $summary['byMonth']),
            'by_category' => array_map(static fn ($item): array => $item->toArray(), $summary['byCategory']),
            'marketplace_categories' => array_map(static fn ($item): array => $item->toArray(), $summary['marketplaceCategories']),
        ]);
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        return trim($value);
    }

    private function logRequest(string $companyId): void
    {
        $this->logger->info('Ingestion verification endpoint requested', [
            'endpoint' => 'financial-summary',
            'companyId' => $companyId,
            'requestedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
