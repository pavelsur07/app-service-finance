<?php

declare(strict_types=1);

namespace App\Cash\Controller\Import;

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
        private readonly \App\Cash\Http\CashListPagination $pagination,
    ) {
    }

    #[Route('', name: 'cash_file_import_profile_index', methods: ['GET'])]
    public function __invoke(\Symfony\Component\HttpFoundation\Request $request): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $pager = ($this->pagination)($request, new \Pagerfanta\Doctrine\ORM\QueryAdapter($this->repository->listQuery((string) $company->getId())));
        $profiles = $pager->getCurrentPageResults();

        return $this->render('cash/file_import_profiles/index.html.twig', ['pager' => $pager, 'profiles' => $profiles]);
    }
}
