<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceStructureService;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceCategoryMoveController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly BalanceStructureService $structure,
    ) {
    }

    #[Route('/balance/structure/move', name: 'balance_structure_move', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        $id = $request->request->getString('category_id');
        if (!$this->isCsrfTokenValid('category_move_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->structure->moveCategory($companyId, $actor, $id, $request->request->getString('direction'));

        return $this->redirectToRoute('balance_structure_index');
    }
}
