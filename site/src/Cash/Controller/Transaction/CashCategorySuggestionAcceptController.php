<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\AcceptCashCategorySuggestionAction;
use App\Cash\Enum\Transaction\CashCategorySuggestionDecisionOutcome;
use App\Cash\Exception\CashCategorySuggestionNotFoundException;
use App\Cash\Exception\CashCategorySuggestionStateException;
use App\Company\Security\ModuleAccess;
use App\Shared\Audit\AuditContextProvider;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route(
    '/finance/cash-transactions/ai-suggestions/{id}/accept',
    name: 'cash_category_suggestion_accept',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
final class CashCategorySuggestionAcceptController extends AbstractController
{
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(
        string $id,
        Request $request,
        ActiveCompanyService $companyService,
        AuditContextProvider $auditContextProvider,
        AcceptCashCategorySuggestionAction $action,
    ): RedirectResponse {
        $back = $this->redirectToRoute('cash_transaction_index', $request->query->all());
        if (!$this->isCsrfTokenValid('cash_category_suggestion_'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Неверный CSRF токен.');

            return $back;
        }
        $userId = $auditContextProvider->getActorUserId();
        if (null === $userId) {
            throw $this->createAccessDeniedException();
        }

        try {
            $outcome = $action((string) $companyService->getActiveCompany()->getId(), $id, $userId);
            if (CashCategorySuggestionDecisionOutcome::ACCEPTED === $outcome) {
                $this->addFlash('success', 'Статья из подсказки ИИ проставлена.');
            } else {
                $this->addFlash('warning', 'Подсказка устарела: у операции уже есть статья или статья недоступна.');
            }
        } catch (CashCategorySuggestionNotFoundException|CashCategorySuggestionStateException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (\DomainException $e) {
            // Закрытый период (FinancePeriodLockedException) и операция перевода
            // (CashTransactionService::assertStandaloneTransaction).
            $this->addFlash('danger', $e->getMessage());
        }

        return $back;
    }
}
