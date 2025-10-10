<?php
declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Report\PlReportCalculator;
use App\Service\ActiveCompanyService; // используем существующий сервис
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class PlReportPreviewController extends AbstractController
{
    #[Route('/finance/report/preview', name: 'finance_report_preview', methods: ['GET'])]
    public function preview(
        Request $request,
        ActiveCompanyService $activeCompany,
        PlReportCalculator $calc
    ): Response {
        $company = $activeCompany->getActiveCompany();
        $periodParam = $request->query->get('period') ?? (new \DateTimeImmutable('first day of this month'))->format('Y-m-01');
        $period = new \DateTimeImmutable($periodParam);

        $result = $calc->calculate($company, $period);

        return $this->render('finance/report/preview.html.twig', [
            'company' => $company,
            'period'  => $period,
            'rows'    => $result->rows,
            'warnings'=> $result->warnings,
        ]);
    }
}
