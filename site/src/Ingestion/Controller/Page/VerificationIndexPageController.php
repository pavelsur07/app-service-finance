<?php

declare(strict_types=1);

namespace App\Ingestion\Controller\Page;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_COMPANY_USER')]
final class VerificationIndexPageController extends AbstractController
{
    #[Route('/ingestion/verification', name: 'ingestion_verification_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->redirectToRoute('ingestion_verification_coverage');
    }
}
