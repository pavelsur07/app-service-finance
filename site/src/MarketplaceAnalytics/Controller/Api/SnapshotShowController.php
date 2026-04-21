<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Api\Response\SnapshotResponse;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use App\Shared\Service\ActiveCompanyService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Marketplace Analytics')]
final class SnapshotShowController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ListingDailySnapshotRepositoryInterface $snapshotRepository,
        private readonly MarketplaceFacade $marketplaceFacade,
    ) {}

    #[OA\Get(
        summary: 'Снэпшот по ID',
        description: 'Возвращает конкретный снэпшот аналитики. Проверяет принадлежность активной компании (multi-tenancy).',
        tags: ['Marketplace Analytics']
    )]
    #[OA\Parameter(
        name: 'id',
        in: 'path',
        required: true,
        description: 'UUID снэпшота',
        schema: new OA\Schema(type: 'string', format: 'uuid')
    )]
    #[OA\Response(
        response: 200,
        description: 'Снэпшот найден',
        content: new Model(type: SnapshotResponse::class)
    )]
    #[OA\Response(response: 401, description: 'Не авторизован')]
    #[OA\Response(
        response: 404,
        description: 'Снэпшот не найден или не принадлежит активной компании (legacy-формат ошибки, не RFC 7807). Также возможен 404 без legacy-тела, если у пользователя нет активной компании (NotFoundHttpException из ActiveCompanyService).',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'type', type: 'string', example: 'NOT_FOUND'),
                new OA\Property(property: 'message', type: 'string', example: 'Снимок не найден'),
            ]
        )
    )]
    #[Route(
        '/api/marketplace-analytics/snapshots/{id}',
        name: 'marketplace_analytics_api_snapshot_show',
        methods: ['GET'],
        requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
    )]
    public function __invoke(string $id): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $snapshot = $this->snapshotRepository->findById($id, $company->getId());

        if ($snapshot === null) {
            return $this->json(
                ['type' => 'NOT_FOUND', 'message' => 'Снимок не найден'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $listing = $this->marketplaceFacade->findListingById(
            $company->getId(),
            $snapshot->getListingId(),
        );

        return $this->json(
            SnapshotResponse::fromEntity(
                $snapshot,
                $listing?->name ?? '',
                $listing?->marketplaceSku ?? '',
            )->toArray(),
        );
    }
}
