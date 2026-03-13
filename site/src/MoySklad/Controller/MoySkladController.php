<?php

declare(strict_types=1);

namespace App\MoySklad\Controller;

use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/moy-sklad')]
#[IsGranted('ROLE_USER')]
final class MoySkladController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route('', name: 'moysklad_index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        return $this->render('moy_sklad/index.html.twig', [
            'activeCompanyId' => (string) $company->getId(),
        ]);
    }
}
