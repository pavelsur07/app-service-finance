<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdEfficiencyItemDTO;
use App\MarketplaceAds\Infrastructure\Query\AdEfficiencyQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-ads/efficiency', name: 'marketplace_ads_api_efficiency', methods: ['GET'])]
#[IsGranted('ROLE_COMPANY_USER')]
final class AdEfficiencyController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AdEfficiencyQuery $adEfficiencyQuery,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $periodFromStr = (string) $request->query->get('periodFrom', '');
        $periodToStr = (string) $request->query->get('periodTo', '');

        $from = $this->parseDate($periodFromStr);
        $to = $this->parseDate($periodToStr);

        if (null === $from || null === $to) {
            return $this->json(['error' => 'periodFrom and periodTo must be in Y-m-d format'], 400);
        }

        if ($from > $to) {
            return $this->json(['error' => 'periodFrom must be <= periodTo'], 400);
        }

        $marketplaceRaw = $request->query->get('marketplace');
        $marketplaceValue = null;
        if (null !== $marketplaceRaw && '' !== $marketplaceRaw) {
            $marketplace = MarketplaceType::tryFrom((string) $marketplaceRaw);
            if (null === $marketplace) {
                return $this->json(['error' => 'invalid marketplace'], 400);
            }
            $marketplaceValue = $marketplace->value;
        }

        $page = $request->query->getInt('page', 1);
        $pageSize = $request->query->getInt('pageSize', 25);
        $sortBy = (string) $request->query->get('sortBy', 'revenue');
        $sortDir = (string) $request->query->get('sortDir', 'desc');

        $company = $this->activeCompanyService->getActiveCompany();

        $pageDto = $this->adEfficiencyQuery->getPage(
            (string) $company->getId(),
            $from,
            $to,
            $marketplaceValue,
            $page,
            $pageSize,
            $sortBy,
            $sortDir,
        );

        return $this->json([
            'items' => array_map(
                static fn (AdEfficiencyItemDTO $item): array => [
                    'listingId' => $item->listingId,
                    'sku' => $item->sku,
                    'title' => $item->title,
                    'marketplace' => $item->marketplace,
                    'revenue' => $item->revenue,
                    'adSpend' => $item->adSpend,
                    'drrPercent' => $item->drrPercent,
                ],
                $pageDto->items,
            ),
            'total' => $pageDto->total,
            'page' => $pageDto->page,
            'pageSize' => $pageDto->pageSize,
            'totals' => [
                'revenue' => $pageDto->totalRevenue,
                'adSpend' => $pageDto->totalAdSpend,
                'drrPercent' => $pageDto->totalDrrPercent,
            ],
        ]);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }
}
