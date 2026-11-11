<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Enum\Transaction\CashTransactionAutoRuleApplyMode;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashAutoRuleEnqueueController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly AuditContextProvider $auditContextProvider,
        private readonly \App\Cash\Application\EnqueueCashAutoRulesAction $enqueueRules,
    ) {
    }

    #[Route('/auto-rule-apply', name: 'cash_transaction_auto_rule_apply_enqueue', methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request): RedirectResponse
    {
        $company = $this->companyService->getActiveCompany();
        if (!$this->isCsrfTokenValid('cash_transaction_auto_rule_apply_enqueue', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
        }
        $mode = CashTransactionAutoRuleApplyMode::tryFrom((string) $request->request->get('mode', CashTransactionAutoRuleApplyMode::SAFE->value));
        if (null === $mode) {
            $this->addFlash('danger', 'Выберите режим применения автоправил.');

            return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
        }
        $initiatedByUserId = $this->auditContextProvider->getActorUserId();
        if ($mode->replacesAutoAssigned() && ('1' !== $request->request->get('confirm_replace') || null === $initiatedByUserId)) {
            $this->addFlash('danger', 'Подтвердите небезопасную замену значений, назначенных автоправилами.');

            return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
        }
        $period = ($this->enqueueRules)((string) $company->getId(), $mode, $initiatedByUserId);
        $from = $period['from'];
        $today = $period['to'];
        $this->addFlash('success', sprintf('Автоправила поставлены в очередь в режиме «%s» за период %s — %s.', $mode->replacesAutoAssigned() ? 'Небезопасный' : 'Безопасный', $from->format('d.m.Y'), $today->format('d.m.Y')));

        return $this->redirectToRoute('cash_transaction_auto_rule_apply_page');
    }
}
