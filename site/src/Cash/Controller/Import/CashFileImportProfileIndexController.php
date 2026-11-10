<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Entity\Import\CashFileImportProfile;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file/profiles')]
final class CashFileImportProfileIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CashFileImportProfileRepository $repository,
    ) {
    }

    #[Route('', name: 'cash_file_import_profile_index', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $profiles = $this->repository->findByCompanyAndType($company, CashFileImportProfile::TYPE_CASH_TRANSACTION);

        return $this->render('cash/file_import_profiles/index.html.twig', ['profiles' => $profiles]);
    }
}
