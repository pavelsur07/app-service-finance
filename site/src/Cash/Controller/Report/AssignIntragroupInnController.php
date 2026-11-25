<?php

declare(strict_types=1);

namespace App\Cash\Controller\Report;

use App\Cash\Application\Report\AssignIntragroupTaxIdAction;
use App\Cash\Http\CashflowGroupFormRequest;
use App\Company\Entity\User;
use App\Company\Security\ModuleAccess;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(ModuleAccess::FINANCE_WRITE)]
final readonly class AssignIntragroupInnController
{
    public function __construct(private AssignIntragroupTaxIdAction $action, private CashflowGroupFormRequest $form)
    {
    }

    #[Route('/finance/reports/cashflow/group/reconciliation/inn', name: 'report_cashflow_group_assign_inn', methods: ['POST'])]
    public function __invoke(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->form->handle($request, $user, 'ИНН контрагента сохранён.', function (string $activeId, string $userId, string $head) use ($request): void {
            $fields = $request->request;
            ($this->action)($activeId, $userId, $fields->getString('companyId'), $fields->getString('counterpartyId'), $fields->getString('peerCompanyId'), $head);
        });
    }
}
