<?php

declare(strict_types=1);

namespace App\Cash\Controller\Report;

use App\Cash\Application\Report\IntragroupDecisionAction;
use App\Cash\Http\CashflowGroupFormRequest;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final readonly class RemoveIntragroupDecisionController
{
    public function __construct(private IntragroupDecisionAction $action, private CashflowGroupFormRequest $form)
    {
    }

    #[Route('/finance/reports/cashflow/group/reconciliation/remove', name: 'report_cashflow_group_remove', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->form->handle($request, $user, 'Решение удалено.', function (string $activeId, string $userId, string $head) use ($request): void {
            $this->action->remove($activeId, $userId, $request->request->getString('id'), $head);
        });
    }
}
