<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/bank1c')]
final class Bank1CImportUploadController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
    ) {
    }

    #[Route('', name: 'cash_bank1c_import_upload', methods: ['GET'])]
    public function __invoke(): Response
    {
        // Сохраняем прежнюю проверку активной компании после удаления селектора счёта.
        $this->activeCompanyService->getActiveCompany();

        return $this->render('cash/bank1c_import_upload.html.twig');
    }
}
