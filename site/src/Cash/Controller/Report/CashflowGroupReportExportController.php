<?php

declare(strict_types=1);

namespace App\Cash\Controller\Report;

use App\Cash\Application\Report\CashflowGroupReportRequest;
use App\Cash\Infrastructure\Export\CashflowGroupReportXlsxWriter;
use App\Company\Entity\User;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CashflowGroupReportExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashflowGroupReportRequest $report,
        private readonly CashflowGroupReportXlsxWriter $writer,
    ) {
    }

    #[Route('/finance/reports/cashflow/group/export.xlsx', name: 'report_cashflow_group_export', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $report = ($this->report)($request, $this->companyService->getActiveCompany(), $user);
        if (null === $report) {
            return $this->redirectToRoute('report_cashflow_group');
        }

        $file = tempnam(sys_get_temp_dir(), 'cashflow_group_');
        if (false === $file) {
            throw new \RuntimeException('Unable to create temporary file for export');
        }
        try {
            $this->writer->write($report, $file);
        } catch (\Throwable $e) {
            unlink($file);

            throw $e;
        }

        $response = new BinaryFileResponse($file);
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, sprintf('cashflow-group-%s_%s.xlsx', $report->from->format('Y-m-d'), $report->to->format('Y-m-d')));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }
}
