<?php

declare(strict_types=1);

namespace App\Ingestion\Controller\Page;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class FinancialSummaryPageController extends AbstractController
{
    #[Route('/ingestion/verification/financial-summary', name: 'ingestion_verification_financial_summary', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('ingestion/verification/financial-summary.html.twig');
    }
}
