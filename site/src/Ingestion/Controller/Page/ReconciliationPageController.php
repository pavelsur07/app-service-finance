<?php

declare(strict_types=1);

namespace App\Ingestion\Controller\Page;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class ReconciliationPageController extends AbstractController
{
    #[Route('/ingestion/verification/reconciliation', name: 'ingestion_verification_reconciliation', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('ingestion/verification/reconciliation.html.twig');
    }
}
