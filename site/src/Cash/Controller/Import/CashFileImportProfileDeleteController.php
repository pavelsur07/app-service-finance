<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

use App\Cash\Application\Import\File\DeleteCashFileImportProfileAction;
use App\Cash\Repository\Import\CashFileImportProfileRepository;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/cash/import/file/profiles')]
final class CashFileImportProfileDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CashFileImportProfileRepository $repository,
        private readonly DeleteCashFileImportProfileAction $deleteProfile,
    ) {
    }

    #[Route('/{id}/delete', name: 'cash_file_import_profile_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(ModuleAccess::FINANCE_WRITE)]
    public function __invoke(Request $request, string $id): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $profile = $this->repository->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (null === $profile) {
            throw $this->createNotFoundException();
        }
        if ($this->isCsrfTokenValid('delete'.$profile->getId(), (string) $request->request->get('_token'))) {
            ($this->deleteProfile)($profile);
        }

        return $this->redirectToRoute('cash_file_import_profile_index');
    }
}
