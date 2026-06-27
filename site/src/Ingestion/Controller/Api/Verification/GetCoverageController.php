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
final class GetCoverageController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly IngestionFacade $ingestionFacade,
        private readonly VerificationPeriodValidator $periodValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[OA\Get(summary: 'Ingestion coverage heatmap', tags: ['Ingestion verification'])]
    #[OA\Parameter(name: 'shop_ref', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date'))]
    #[OA\Response(
        response: 200,
        description: 'Coverage heatmap and shop options',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'cells',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'date', type: 'string', format: 'date', example: '2026-06-01'),
                            new OA\Property(property: 'shop_ref', type: 'string', example: 'ozon-shop-123'),
                            new OA\Property(property: 'resource_type', type: 'string', example: 'ozon_finance_accrual_by_day'),
                            new OA\Property(property: 'resource_label', type: 'string', example: 'Accrual by day'),
                            new OA\Property(property: 'resource_group', type: 'string', example: 'Ozon Finance'),
                            new OA\Property(property: 'raw_count', type: 'integer', example: 1),
                            new OA\Property(property: 'tx_count', type: 'integer', example: 287),
                            new OA\Property(property: 'issue_count', type: 'integer', example: 0),
                            new OA\Property(property: 'last_fetched_at', type: 'string', nullable: true, example: '2026-06-02T03:14:00Z'),
                        ],
                        type: 'object',
                    ),
                ),
                new OA\Property(
                    property: 'shops',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'shop_ref', type: 'string', example: 'ozon-shop-123'),
                            new OA\Property(property: 'label', type: 'string', example: 'ozon-shop-123'),
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
        description: 'Invalid period',
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
    #[Route('/api/ingestion/verification/coverage', name: 'api_ingestion_verification_coverage', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        [$from, $to] = $this->periodValidator->parseDateRange(
            (string) $request->query->get('from', ''),
            (string) $request->query->get('to', ''),
        );
        $shopRef = $this->nullableString($request->query->get('shop_ref'));

        $this->logRequest($companyId);
        $coverage = $this->ingestionFacade->getCoverage($companyId, $shopRef, $from, $to);

        return $this->json([
            'cells' => array_map(static fn ($cell): array => $cell->toArray(), $coverage['cells']),
            'shops' => array_map(static fn ($shop): array => $shop->toArray(), $coverage['shops']),
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
            'endpoint' => 'coverage',
            'companyId' => $companyId,
            'requestedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
