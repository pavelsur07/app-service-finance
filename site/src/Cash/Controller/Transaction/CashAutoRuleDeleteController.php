<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DeleteCashTransactionAutoRuleAction;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash-transaction-auto-rules')]
final class CashAutoRuleDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionAutoRuleRepository $repo,
        private readonly DeleteCashTransactionAutoRuleAction $delete,
        private readonly AuditContextProvider $auditContextProvider,
    ) {
    }

    #[Route('/{id}/delete', name: 'cash_transaction_auto_rule_delete', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(string $id, Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $rule = $this->repo->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$rule) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('delete'.$rule->getId(), (string) $request->request->get('_token'))) {
            ($this->delete)($rule, $this->auditContextProvider->getActorUserId());
        }

        return $this->redirectToRoute('cash_transaction_auto_rule_index');
    }
}
