<?php

declare(strict_types=1);

namespace App\Balance\Controller;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Security\BalanceAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class BalanceRebuildController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly BalanceLedgerService $ledger,
    ) {
    }

    #[Route('/balance/accounts/rebuild', name: 'balance_rebuild', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'manage');
        if (!$this->isCsrfTokenValid('balance_rebuild', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->ledger->rebuildCurrentStates($companyId, $actor, $request->request->getString('reason'));
        $this->addFlash('success', 'Остатки восстановлены из проведенных документов.');

        return $this->redirectToRoute('balance_accounts');
    }
}
