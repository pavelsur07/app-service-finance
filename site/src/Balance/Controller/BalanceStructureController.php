<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceStructureController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly LedgerQuery $query,
    ) {
    }

    #[Route('/balance/structure/', name: 'balance_structure_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $this->access->actor($companyId);

        return $this->render('balance_structure/index.html.twig', ['items' => $this->query->categories($companyId), 'permissions' => $this->access->permissions($companyId), 'book' => $this->query->book($companyId)]);
    }
}
