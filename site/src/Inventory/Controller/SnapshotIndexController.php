<?php

declare(strict_types=1);

namespace App\Inventory\Controller;

use App\Inventory\Infrastructure\Query\InventorySnapshotSessionListQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class SnapshotIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly InventorySnapshotSessionListQuery $sessionListQuery,
    ) {
    }

    #[Route('/inventory/snapshots', name: 'inventory_snapshots_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $page = max(1, $request->query->getInt('page', 1));
        $pager = $this->sessionListQuery->getPage($companyId, $page, InventorySnapshotSessionListQuery::PER_PAGE);

        return $this->render('inventory/snapshots/index.html.twig', [
            'pager' => $pager,
        ]);
    }
}
