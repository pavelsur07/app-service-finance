<?php

declare(strict_types=1);

namespace App\Ingestion\Controller\Api\Verification;

use App\Ingestion\Application\Service\VerificationPeriodValidator;
use App\Ingestion\Exception\InvalidPeriodException;
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
final class GetIssuesController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly IngestionFacade $ingestionFacade,
        private readonly VerificationPeriodValidator $periodValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[OA\Get(summary: 'Open ingestion normalization issues', tags: ['Ingestion verification'])]
    #[OA\Parameter(name: 'shop_ref', in: 'query', required: false, schema: new OA\Schema(type: 'string'))]
    #[OA\Parameter(name: 'year', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 2020, maximum: 2100))]
    #[OA\Parameter(name: 'month', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 12))]
    #[OA\Parameter(name: 'page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 1, minimum: 1))]
    #[OA\Parameter(name: 'limit', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 50, minimum: 1, maximum: 200))]
    #[OA\Response(
        response: 200,
        description: 'Open issues',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(
                    property: 'items',
                    type: 'array',
                    items: new OA\Items(
                        properties: [
                            new OA\Property(property: 'id', type: 'string', format: 'uuid'),
                            new OA\Property(property: 'kind', type: 'string', example: 'sum_mismatch'),
                            new OA\Property(property: 'human_description', type: 'string'),
                            new OA\Property(property: 'created_at', type: 'string', example: '2026-06-15T10:00:00Z'),
                        ],
                        type: 'object',
                    ),
                ),
                new OA\Property(
                    property: 'meta',
                    properties: [
                        new OA\Property(property: 'page', type: 'integer', example: 1),
                        new OA\Property(property: 'limit', type: 'integer', example: 50),
                        new OA\Property(property: 'total', type: 'integer', example: 0),
                        new OA\Property(property: 'total_pages', type: 'integer', example: 0),
                    ],
                    type: 'object',
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
    #[Route('/api/ingestion/verification/issues', name: 'api_ingestion_verification_issues', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $shopRef = $this->nullableString($request->query->get('shop_ref'));
        [$year, $month] = $this->optionalYearMonth($request);
        $page = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 50);

        $this->logRequest($companyId);
        $issues = $this->ingestionFacade->listIssues($companyId, $shopRef, $year, $month, $page, $limit);

        return $this->json([
            'items' => array_map(static fn ($item): array => $item->toArray(), $issues['items']),
            'meta' => $issues['meta']->toArray(),
        ]);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function optionalYearMonth(Request $request): array
    {
        $hasYear = $request->query->has('year');
        $hasMonth = $request->query->has('month');

        if (!$hasYear && !$hasMonth) {
            return [null, null];
        }

        if (!$hasYear || !$hasMonth) {
            throw new InvalidPeriodException();
        }

        $year = $request->query->getInt('year');
        $month = $request->query->getInt('month');
        $this->periodValidator->assertYearMonth($year, $month);

        return [$year, $month];
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
            'endpoint' => 'issues',
            'companyId' => $companyId,
            'requestedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
