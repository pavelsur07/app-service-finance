<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\ListingTagFacade;
use App\MarketplaceAnalytics\Infrastructure\Query\WidgetSummaryQuery;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/api/marketplace-analytics/unit-extended/widgets',
    name: 'api_marketplace_analytics_widgets_summary',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class GetWidgetsSummaryController extends AbstractController
{
    private const MAX_TAGS = 100;

    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly WidgetSummaryQuery $widgetQuery,
        private readonly ListingTagFacade $listingTagFacade,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $marketplace = $request->query->get('marketplace');
        if (null === $marketplace || '' === $marketplace) {
            $marketplace = null;
        } else {
            $validValues = array_map(
                static fn (MarketplaceType $t): string => $t->value,
                MarketplaceType::cases(),
            );
            if (!in_array($marketplace, $validValues, true)) {
                return $this->json([
                    'error' => 'Invalid marketplace. Allowed: '.implode(', ', $validValues),
                ], 422);
            }
        }

        $periodFromStr = $request->query->get('periodFrom', '');
        $periodToStr = $request->query->get('periodTo', '');

        if ('' === $periodFromStr || '' === $periodToStr) {
            return $this->json(['error' => 'periodFrom and periodTo are required'], 422);
        }

        try {
            $periodFrom = new \DateTimeImmutable($periodFromStr);
            $periodTo = new \DateTimeImmutable($periodToStr);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date format. Expected Y-m-d'], 422);
        }

        if ($periodFrom > $periodTo) {
            return $this->json(['error' => 'periodFrom must be <= periodTo'], 422);
        }

        // Разбор тегов повторяет UnitExtendedController: обе ручки обслуживают
        // один фильтр на странице, и расхождение в валидации означало бы, что
        // таблица приняла набор тегов, который виджеты отвергли.
        $tagIds = $request->query->all('tags');
        foreach ($tagIds as $tagId) {
            if (!is_string($tagId) || !Uuid::isValid($tagId)) {
                return $this->json(['error' => 'tags must be a list of uuids'], 422);
            }
        }
        if (count($tagIds) > self::MAX_TAGS) {
            return $this->json(['error' => 'too many tags (max '.self::MAX_TAGS.')'], 422);
        }
        /** @var list<string> $tagIds */
        $tagIds = array_values(array_unique($tagIds));
        $tagsMatchAll = 'all' === $request->query->get('tagsMatch');

        // Теги резолвим один раз на оба периода: текущий и предыдущий обязаны
        // сравниваться по одному и тому же набору листингов, иначе дельта
        // «пред: N ₽» сравнивала бы разные корзины товаров.
        $listingIds = [] !== $tagIds
            ? $this->listingTagFacade->listingIdsByTags((string) $company->getId(), $tagIds, $tagsMatchAll)
            : null;

        $current = $this->widgetQuery->getSummary(
            $company->getId(),
            $marketplace,
            $periodFrom,
            $periodTo,
            $listingIds,
        );

        $days = $periodFrom->diff($periodTo)->days;
        $prevTo = $periodFrom->modify('-1 day');
        $prevFrom = $prevTo->modify("-{$days} days");

        $previous = $this->widgetQuery->getSummary(
            $company->getId(),
            $marketplace,
            $prevFrom,
            $prevTo,
            $listingIds,
        );

        return new JsonResponse([
            'current' => $current,
            'previous' => $previous,
            'period' => [
                'from' => $periodFrom->format('Y-m-d'),
                'to' => $periodTo->format('Y-m-d'),
                'previousFrom' => $prevFrom->format('Y-m-d'),
                'previousTo' => $prevTo->format('Y-m-d'),
            ],
        ]);
    }
}
