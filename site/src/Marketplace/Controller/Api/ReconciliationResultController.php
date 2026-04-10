<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Repository\ReconciliationSessionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Получение результата конкретной сессии сверки.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationResultController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ReconciliationSessionRepository $sessionRepository,
    ) {
    }

    #[Route('/api/marketplace/reconciliation/{id}', name: 'api_marketplace_reconciliation_result', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $session = $this->sessionRepository->findByIdAndCompany($id, $companyId);

        if ($session === null) {
            throw $this->createNotFoundException();
        }

        return $this->json([
            'id'               => $session->getId(),
            'marketplace'      => $session->getMarketplace(),
            'periodFrom'       => $session->getPeriodFrom()->format('Y-m-d'),
            'periodTo'         => $session->getPeriodTo()->format('Y-m-d'),
            'originalFilename' => $session->getOriginalFilename(),
            'status'           => $session->getStatus()->value,
            'result'           => $session->getDecodedResult(),
            'createdAt'        => $session->getCreatedAt()->format('c'),
        ]);
    }
}
