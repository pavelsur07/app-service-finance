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
final class GetReconciliationController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly IngestionFacade $ingestionFacade,
        private readonly VerificationPeriodValidator $periodValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[OA\Get(summary: 'Ingestion reconciliation summary', tags: ['Ingestion verification'])]
    #[OA\Parameter(name: 'shop_ref', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'year', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 2020, maximum: 2100))]
    #[OA\Parameter(name: 'month', in: 'query', required: true, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))]
    #[OA\Response(
        response: 200,
        description: 'Reconciliation summary',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'summary',
                    properties: [
                        new OA\Property(property: 'period', type: 'string', example: '2026-05'),
                        new OA\Property(property: 'canon_total_minor', type: 'integer', example: 1234567800),
                        new OA\Property(property: 'ozon_control_total_minor', type: 'integer', nullable: true, example: 1234566800),
                        new OA\Property(property: 'currency', type: 'string', example: 'RUB'),
                        new OA\Property(property: 'canon_vs_ozon_delta_minor', type: 'integer', nullable: true, example: 1000),
                        new OA\Property(property: 'threshold_minor', type: 'integer', example: 100),
                        new OA\Property(property: 'recomputed_at', type: 'string', example: '2026-06-15T10:00:00Z'),
                    ],
                    type: 'object',
                ),
                new OA\Property(
                    property: 'by_type',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'type', type: 'string', example: 'sale'),
                            new OA\Property(property: 'type_label', type: 'string', example: 'Продажа'),
                            new OA\Property(property: 'canon_amount_minor', type: 'integer', example: 1500000000),
                            new OA\Property(property: 'tx_count', type: 'integer', example: 240),
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
                        new OA\Property(property: 'code', type: 'string', example: 'invalid_period'),
                        new OA\Property(property: 'message', type: 'string', example: 'Некорректный период'),
                    ],
                    type: 'object',
                ),
            ],
            type: 'object',
        ),
    )]
    #[Route('/api/ingestion/verification/reconciliation', name: 'api_ingestion_verification_reconciliation', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $shopRef = $this->nullableString($request->query->get('shop_ref'));
        $year = $request->query->getInt('year');
        $month = $request->query->getInt('month');
        $this->periodValidator->assertYearMonth($year, $month);

        $this->logRequest($companyId);
        $reconciliation = $this->ingestionFacade->getReconciliation($companyId, $shopRef, $year, $month);

        return $this->json([
            'summary' => $reconciliation['summary']->toArray(),
            'by_type' => array_map(static fn ($item): array => $item->toArray(), $reconciliation['byType']),
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
            'endpoint' => 'reconciliation',
            'companyId' => $companyId,
            'requestedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
