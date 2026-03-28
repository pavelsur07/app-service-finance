<?php

declare(strict_types=1);

namespace App\Loan\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class LoanScheduleTemplateController extends AbstractController
{
    #[Route('/loans/schedule/template', name: 'loan_schedule_template', methods: ['GET'])]
    public function __invoke(): StreamedResponse
    {
        $response = new StreamedResponse();
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="loan_schedule_template.csv"');

        $response->setCallback(function () {
            $handle = fopen('php://output', 'w');

            $rows = [
                ['date', 'total_payment', 'principal_part', 'interest_part', 'fee_part', 'is_paid'],
                ['2025-01-28', '50000.00', '35000.00', '15000.00', '0.00', '0'],
                ['2025-02-28', '50000.00', '36000.00', '14000.00', '0.00', '0'],
                ['2025-03-28', '50000.00', '37000.00', '13000.00', '0.00', '0'],
            ];

            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
            }

            fclose($handle);
        });

        return $response;
    }
}
