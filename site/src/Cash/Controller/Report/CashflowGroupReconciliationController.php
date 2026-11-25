<?php

declare(strict_types=1);

namespace App\Cash\Controller\Report;

use App\Cash\Http\CashflowReconciliationPage;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CashflowGroupReconciliationController extends AbstractController
{
    #[Route('/finance/reports/cashflow/group/reconciliation', name: 'report_cashflow_group_reconciliation', methods: ['GET'])]
    public function __invoke(Request $request, ActiveCompanyService $companies, CashflowReconciliationPage $page): Response
    {
        $company = $companies->getActiveCompany();
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('cash/report/cashflow_group_reconciliation.html.twig', $page->context($request, $company, $user));
    }
}
