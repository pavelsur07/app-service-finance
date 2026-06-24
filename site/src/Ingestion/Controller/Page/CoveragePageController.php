<?php

declare(strict_types=1);

namespace App\Ingestion\Controller\Page;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class CoveragePageController extends AbstractController
{
    #[Route('/ingestion/coverage', name: 'ingestion_coverage', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('ingestion/verification/coverage.html.twig');
    }

    #[Route('/ingestion/verification/coverage', name: 'ingestion_verification_coverage', methods: ['GET'])]
    public function legacyVerificationCoverage(): Response
    {
        return $this->redirectToRoute('ingestion_coverage');
    }
}
