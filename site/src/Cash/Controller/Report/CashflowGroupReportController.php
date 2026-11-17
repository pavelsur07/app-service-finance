<?php

declare(strict_types=1);

namespace App\Cash\Controller\Report;

use App\Cash\Application\Report\CashflowGroupReportRequest;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** Консолидированный отчёт ДДС группы активной компании. Только чтение. */
#[IsGranted('ROLE_USER')]
final class CashflowGroupReportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowGroupReportRequest $report,
    ) {
    }

    #[Route('/finance/reports/cashflow/group', name: 'report_cashflow_group', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->render('cash/report/cashflow_group.html.twig', [
            'report' => ($this->report)($request, $company, $user),
            'companyName' => $company->getName(),
        ]);
    }
}
