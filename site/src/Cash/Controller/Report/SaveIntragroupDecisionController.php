<?php

declare(strict_types=1);

namespace App\Cash\Controller\Report;

use App\Cash\Application\Report\IntragroupDecisionAction;
use App\Cash\Enum\Transaction\IntragroupDecisionType;
use App\Cash\Exception\IntragroupDecisionException;
use App\Cash\Http\CashflowGroupFormRequest;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final readonly class SaveIntragroupDecisionController
{
    public function __construct(private IntragroupDecisionAction $action, private CashflowGroupFormRequest $form)
    {
    }

    #[Route('/finance/reports/cashflow/group/reconciliation/decision', name: 'report_cashflow_group_decide', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->form->handle($request, $user, 'Решение о сопоставлении сохранено.', function (string $activeId, string $userId, string $head) use ($request): void {
            $fields = $request->request;
            $type = IntragroupDecisionType::tryFrom($fields->getString('type')) ?? throw new IntragroupDecisionException('Выберите действие для пары.');
            $this->action->save($activeId, $userId, $fields->getString('id'), $type, $fields->getString('outflowId'), $fields->getString('inflowId'), $head);
        });
    }
}
