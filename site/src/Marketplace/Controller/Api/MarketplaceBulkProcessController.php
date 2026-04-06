<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\DispatchBulkProcessingAction;
use App\Marketplace\Application\DTO\BulkProcessMonthCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Запускает пакетную обработку всех RawDocument за указанный месяц.
 * Диспатчит асинхронные шаги sales/returns/costs для каждого документа.
 */
#[Route('/api/marketplace')]
#[IsGranted('ROLE_COMPANY_USER')]
final class MarketplaceBulkProcessController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DispatchBulkProcessingAction $action,
    ) {
    }

    #[Route('/bulk-process', name: 'marketplace_bulk_process', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $data = json_decode($request->getContent(), true) ?? [];

        $marketplaceRaw = $data['marketplace'] ?? null;
        $year           = isset($data['year']) ? (int) $data['year'] : null;
        $month          = isset($data['month']) ? (int) $data['month'] : null;

        $marketplace = $marketplaceRaw !== null ? MarketplaceType::tryFrom((string) $marketplaceRaw) : null;

        if ($marketplace === null) {
            return new JsonResponse(['error' => 'Invalid or missing marketplace. Allowed: wildberries, ozon, yandex_market, sber_megamarket'], 422);
        }

        if ($year === null || $year < 2020 || $year > 2030) {
            return new JsonResponse(['error' => 'Invalid or missing year. Allowed range: 2020–2030'], 422);
        }

        if ($month === null || $month < 1 || $month > 12) {
            return new JsonResponse(['error' => 'Invalid or missing month. Allowed range: 1–12'], 422);
        }

        $count = ($this->action)(new BulkProcessMonthCommand(
            companyId:   (string) $company->getId(),
            marketplace: $marketplace,
            year:        $year,
            month:       $month,
        ));

        return new JsonResponse(['dispatched' => $count]);
    }
}
