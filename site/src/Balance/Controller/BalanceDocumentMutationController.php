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
final class BalanceDocumentMutationController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly BalanceAccess $access,
        private readonly BalanceLedgerService $ledger,
    ) {
    }

    #[Route('/balance/documents/{id}/post', name: 'balance_document_post', defaults: ['action' => 'post'], methods: ['POST'])]
    #[Route('/balance/documents/{id}/delete', name: 'balance_document_delete', defaults: ['action' => 'delete'], methods: ['POST'])]
    public function __invoke(Request $request, string $id, string $action): Response
    {
        $companyId = $this->activeCompany->getActiveCompany()->getId();
        if (null === $companyId) {
            throw $this->createAccessDeniedException();
        }
        $actor = $this->access->actor($companyId, 'post' === $action ? 'post' : 'prepare');
        if (!$this->isCsrfTokenValid('document_'.$action.'_'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if ('post' === $action) {
            $this->ledger->post($companyId, $actor, $id, $request->request->getInt('version'), $request->request->getBoolean('confirm_zero'));

            return $this->redirectToRoute('balance_document', ['id' => $id]);
        }
        $this->ledger->deleteDraft($companyId, $actor, $id, $request->request->getInt('version'));

        return $this->redirectToRoute('balance_journal');
    }
}
