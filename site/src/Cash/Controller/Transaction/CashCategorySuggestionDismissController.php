<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DismissCashCategorySuggestionAction;
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
    '/finance/cash-transactions/ai-suggestions/{id}/dismiss',
    name: 'cash_category_suggestion_dismiss',
    requirements: ['id' => Requirement::UUID],
    methods: ['POST'],
)]
final class CashCategorySuggestionDismissController extends AbstractController
{
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(
        string $id,
        Request $request,
        ActiveCompanyService $companyService,
        AuditContextProvider $auditContextProvider,
        DismissCashCategorySuggestionAction $action,
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
            $action((string) $companyService->getActiveCompany()->getId(), $id, $userId);
            $this->addFlash('success', 'Подсказка ИИ отклонена.');
        } catch (CashCategorySuggestionNotFoundException|CashCategorySuggestionStateException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $back;
    }
}
