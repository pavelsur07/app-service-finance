<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transfer;

use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transfers')]
final class CashTransferDeletedIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransferRepository $repository,
    ) {
    }

    #[Route('/deleted', name: 'cash_transfer_deleted_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $pager = $this->repository->paginateDeletedByCompanyId((string) $company->getId(), max(1, $request->query->getInt('page', 1)), 50);

        return $this->render('cash/transfer/deleted_index.html.twig', ['transfers' => iterator_to_array($pager->getCurrentPageResults()), 'pager' => $pager]);
    }
}
