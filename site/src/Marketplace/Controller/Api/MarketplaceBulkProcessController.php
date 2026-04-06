<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\DispatchBulkProcessingAction;
use App\Marketplace\Application\DTO\BulkProcessMonthCommand;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/bulk-process', name: 'marketplace_bulk_process', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('bulk_process', $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['error' => 'Invalid CSRF token'], 403);
        }

        $company = $this->activeCompanyService->getActiveCompany();

        $data = json_decode($request->getContent(), true) ?? [];

        $marketplaceRaw = $data['marketplace'] ?? null;
        $yearRaw        = $data['year'] ?? null;
        $monthRaw       = $data['month'] ?? null;

        $marketplace = is_string($marketplaceRaw) ? MarketplaceType::tryFrom($marketplaceRaw) : null;

        if ($marketplace === null) {
            $allowed = implode(', ', array_map(static fn(MarketplaceType $m) => $m->value, MarketplaceType::cases()));

            return new JsonResponse(['error' => "Invalid or missing marketplace. Allowed: $allowed"], 422);
        }

        $year = is_numeric($yearRaw) ? (int) $yearRaw : null;

        if ($year === null || $year < 2000 || $year > 2100) {
            return new JsonResponse(['error' => 'Invalid or missing year. Allowed range: 2000–2100'], 422);
        }

        $month = is_numeric($monthRaw) ? (int) $monthRaw : null;

        if ($month === null || $month < 1 || $month > 12) {
            return new JsonResponse(['error' => 'Invalid or missing month. Allowed range: 1–12'], 422);
        }

        try {
            $count = ($this->action)(new BulkProcessMonthCommand(
                companyId:   (string) $company->getId(),
                marketplace: $marketplace,
                year:        $year,
                month:       $month,
            ));
        } catch (\Throwable $e) {
            $this->logger->error('Bulk processing dispatch failed', [
                'company_id'  => (string) $company->getId(),
                'marketplace' => $marketplace->value,
                'year'        => $year,
                'month'       => $month,
                'error'       => $e->getMessage(),
            ]);

            return new JsonResponse(['error' => 'Failed to dispatch bulk processing'], 500);
        }

        return new JsonResponse(['dispatched' => $count]);
    }
}
