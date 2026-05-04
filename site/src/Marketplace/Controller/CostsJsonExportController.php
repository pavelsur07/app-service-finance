<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceCostRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
final class CostsJsonExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly MarketplaceCostRepository $costRepository,
    ) {
    }

    #[Route('/costs/export.json', name: 'marketplace_costs_export_json', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $query = $request->query->all();

        $categoryId = $this->stringOrNull($query['category'] ?? null);
        $mapped = $this->resolveMapped($query['mapped'] ?? null);
        $now = new \DateTimeImmutable();

        $defaultDateFrom = $now->modify('first day of this month')->setTime(0, 0);
        $defaultDateTo = $now->modify('last day of this month')->setTime(23, 59, 59);

        $marketplace = $this->resolveMarketplace($query['marketplace'] ?? null);
        $dateFrom = $this->resolveDate($query['date_from'] ?? null, $defaultDateFrom);
        $dateTo = $this->resolveDate($query['date_to'] ?? null, $defaultDateTo)->setTime(23, 59, 59);

        $rows = $this->costRepository->findExportRowsByCompanyAndFilters(
            $company,
            $marketplace,
            $dateFrom,
            $dateTo,
            $categoryId,
            $mapped,
        );

        $payload = [
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'filters' => [
                'category' => $categoryId,
                'mapped' => $mapped,
                'marketplace' => $marketplace->value,
                'date_from' => $dateFrom->format('Y-m-d'),
                'date_to' => $dateTo->format('Y-m-d'),
            ],
            'count' => \count($rows),
            'costs' => $rows,
        ];

        $response = new JsonResponse($payload);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set(
            'Content-Disposition',
            \sprintf('attachment; filename="marketplace-costs-%s_%s.json"', $dateFrom->format('Y-m-d'), $dateTo->format('Y-m-d')),
        );

        return $response;
    }

    private function stringOrNull(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return $raw;
    }

    private function resolveMapped(mixed $raw): string
    {
        $value = $this->stringOrNull($raw);

        if ($value === 'all' || $value === 'linked' || $value === 'general') {
            return $value;
        }

        return 'all';
    }

    private function resolveMarketplace(mixed $raw): MarketplaceType
    {
        $value = $this->stringOrNull($raw);
        if ($value === null) {
            return MarketplaceType::OZON;
        }

        return MarketplaceType::tryFrom($value) ?? MarketplaceType::OZON;
    }

    private function resolveDate(mixed $raw, \DateTimeImmutable $default): \DateTimeImmutable
    {
        $value = $this->stringOrNull($raw);
        if ($value === null) {
            return $default;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return $default;
        }

        return $date;
    }
}
