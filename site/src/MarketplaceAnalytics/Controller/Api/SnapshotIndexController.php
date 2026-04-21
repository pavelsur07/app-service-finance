<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAnalytics\Api\Request\ListSnapshotsRequest;
use App\MarketplaceAnalytics\Api\Response\SnapshotListResponse;
use App\MarketplaceAnalytics\Api\Response\SnapshotResponse;
use App\MarketplaceAnalytics\Repository\ListingDailySnapshotRepositoryInterface;
use App\Shared\Service\ActiveCompanyService;
use Nelmio\ApiDocBundle\Attribute\Model;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[OA\Tag(name: 'Marketplace Analytics')]
final class SnapshotIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ListingDailySnapshotRepositoryInterface $snapshotRepository,
        private readonly MarketplaceFacade $marketplaceFacade,
    ) {}

    #[OA\Get(
        summary: 'Список снэпшотов аналитики',
        description: 'Возвращает постраничный список снэпшотов маркетплейс-аналитики для активной компании с фильтрами по маркетплейсу, листингу и диапазону дат.',
        tags: ['Marketplace Analytics']
    )]
    #[OA\Parameter(
        name: 'marketplace',
        in: 'query',
        required: false,
        description: 'Код маркетплейса (например, OZON, WB). Пустая строка трактуется как отсутствие фильтра.',
        schema: new OA\Schema(type: 'string', nullable: true)
    )]
    #[OA\Parameter(
        name: 'page',
        in: 'query',
        required: false,
        description: 'Номер страницы, начиная с 1.',
        schema: new OA\Schema(type: 'integer', default: 1, minimum: 1)
    )]
    #[OA\Parameter(
        name: 'perPage',
        in: 'query',
        required: false,
        description: 'Количество элементов на страницу.',
        schema: new OA\Schema(type: 'integer', default: 20, minimum: 1)
    )]
    #[OA\Parameter(
        name: 'dateFrom',
        in: 'query',
        required: false,
        description: 'Начало периода в формате YYYY-MM-DD (включительно).',
        schema: new OA\Schema(type: 'string', format: 'date')
    )]
    #[OA\Parameter(
        name: 'dateTo',
        in: 'query',
        required: false,
        description: 'Конец периода в формате YYYY-MM-DD (включительно).',
        schema: new OA\Schema(type: 'string', format: 'date')
    )]
    #[OA\Parameter(
        name: 'listingId',
        in: 'query',
        required: false,
        description: 'UUID листинга для фильтрации.',
        schema: new OA\Schema(type: 'string', format: 'uuid', nullable: true)
    )]
    #[OA\Response(
        response: 200,
        description: 'Постраничный список снэпшотов',
        content: new Model(type: SnapshotListResponse::class)
    )]
    #[OA\Response(
        response: 400,
        description: 'Неверный формат даты в dateFrom/dateTo (legacy-формат ошибки, не RFC 7807)',
        content: new OA\JsonContent(
            type: 'object',
            properties: [
                new OA\Property(property: 'type', type: 'string', example: 'BAD_REQUEST'),
                new OA\Property(property: 'message', type: 'string', example: 'Неверный формат даты. Используйте YYYY-MM-DD.'),
            ]
        )
    )]
    #[OA\Response(response: 401, description: 'Не авторизован')]
    #[OA\Response(
        response: 404,
        description: 'У пользователя нет активной компании (ActiveCompanyService::getActiveCompany() бросает NotFoundHttpException)'
    )]
    #[Route(
        '/api/marketplace-analytics/snapshots',
        name: 'marketplace_analytics_api_snapshot_index',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $req = ListSnapshotsRequest::fromRequest($request);

        $marketplace = ($req->marketplace !== null && $req->marketplace !== '')
            ? $req->marketplace
            : null;

        try {
            $dateFrom = $req->dateFrom !== null ? new \DateTimeImmutable($req->dateFrom) : null;
            $dateTo = $req->dateTo !== null ? new \DateTimeImmutable($req->dateTo) : null;
        } catch (\Exception) {
            return $this->json(
                ['type' => 'BAD_REQUEST', 'message' => 'Неверный формат даты. Используйте YYYY-MM-DD.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $result = $this->snapshotRepository->findPaginated(
            $company->getId(),
            $marketplace,
            $dateFrom,
            $dateTo,
            $req->listingId,
            $req->page,
            $req->perPage,
        );

        $listingMap = [];
        if ($result['items'] !== []) {
            $listings = $this->marketplaceFacade->getActiveListings($company->getId(), $marketplace);
            foreach ($listings as $listing) {
                $listingMap[$listing->id] = $listing;
            }
        }

        $data = array_map(
            static fn($snapshot) => SnapshotResponse::fromEntity(
                $snapshot,
                $listingMap[$snapshot->getListingId()]->name ?? '',
                $listingMap[$snapshot->getListingId()]->marketplaceSku ?? '',
            )->toArray(),
            $result['items'],
        );

        $total = $result['total'];
        $pages = $req->perPage > 0 ? (int) ceil($total / $req->perPage) : 1;

        return $this->json([
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $req->page,
                'per_page' => $req->perPage,
                'pages' => $pages,
            ],
        ]);
    }
}
