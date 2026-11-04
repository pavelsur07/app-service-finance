<?php

declare(strict_types=1);

namespace App\Budget\Controller;

use App\Budget\Application\ChangeBudgetVersionStatusAction;
use App\Budget\Application\DTO\ChangeBudgetVersionStatusCommand;
use App\Budget\Enum\BudgetVersionStatus;
use App\Budget\Exception\BudgetApprovalForbiddenException;
use App\Budget\Exception\BudgetYearAlreadyApprovedException;
use App\Budget\Exception\InvalidBudgetVersionTransitionException;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Утвердить / вернуть в черновик / в архив / восстановить. Чужой вариант — 404 (BudgetExceptionListener).
 * POST /finance/budget/versions/{id}/status/{target}.
 */
#[Route('/finance/budget/versions/{id}/status/{target}', name: 'budget_version_status', requirements: ['id' => Requirement::UUID, 'target' => 'draft|approved|archived'], methods: ['POST'])]
#[IsGranted('ROLE_USER')]
#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final class ChangeBudgetVersionStatusController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly ChangeBudgetVersionStatusAction $changeStatus,
    ) {
    }

    public function __invoke(string $id, string $target, Request $request): RedirectResponse
    {
        $companyId = (string) $this->activeCompanyService->getActiveCompany()->getId();
        $back = $this->redirectToRoute('budget_version_show', ['id' => $id]);
        if (!$this->isCsrfTokenValid('budget_version_status'.$id, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Форма устарела — обновите страницу.');

            return $back;
        }
        $user = $this->getUser();
        \assert($user instanceof User);

        try {
            ($this->changeStatus)(new ChangeBudgetVersionStatusCommand($companyId, $id, BudgetVersionStatus::from($target), (string) $user->getId()));
            $this->addFlash('success', match (BudgetVersionStatus::from($target)) {
                BudgetVersionStatus::APPROVED => 'Вариант утверждён.',
                BudgetVersionStatus::DRAFT => 'Вариант снова черновик.',
                BudgetVersionStatus::ARCHIVED => 'Вариант в архиве.',
            });
        } catch (BudgetApprovalForbiddenException|BudgetYearAlreadyApprovedException|InvalidBudgetVersionTransitionException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $back;
    }
}
