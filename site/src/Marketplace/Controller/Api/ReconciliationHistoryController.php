<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Repository\ReconciliationSessionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Список сессий сверки с пагинацией.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationHistoryController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ReconciliationSessionRepository $sessionRepository,
    ) {
    }

    #[Route('/api/marketplace/reconciliation/history', name: 'api_marketplace_reconciliation_history', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $page  = max(1, $request->query->getInt('page', 1));
        $limit = max(1, min(100, $request->query->getInt('limit', 20)));
        $offset = ($page - 1) * $limit;

        $sessions = $this->sessionRepository->findByCompanyOrderedByDate($companyId, $limit, $offset);
        $total    = $this->sessionRepository->countByCompany($companyId);

        $items = array_map(static fn ($s) => [
            'id'               => $s->getId(),
            'marketplace'      => $s->getMarketplace(),
            'periodFrom'       => $s->getPeriodFrom()->format('Y-m-d'),
            'periodTo'         => $s->getPeriodTo()->format('Y-m-d'),
            'originalFilename' => $s->getOriginalFilename(),
            'status'           => $s->getStatus()->value,
            'createdAt'        => $s->getCreatedAt()->format('c'),
        ], $sessions);

        return $this->json([
            'items' => $items,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }
}
