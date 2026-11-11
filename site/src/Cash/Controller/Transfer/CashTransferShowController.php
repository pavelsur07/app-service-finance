<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transfer;

use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transfers')]
final class CashTransferShowController extends AbstractController
{
    public function __construct(
        private readonly CashTransferRepository $repository,
        private readonly ActiveCompanyService $companyService,
    ) {
    }

    #[Route('/{id}', name: 'cash_transfer_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $transfer = $this->repository->findOneDetailedByIdAndCompanyId($id, (string) $this->companyService->getActiveCompany()->getId()) ?? throw $this->createNotFoundException();

        return $this->render('cash/transfer/show.html.twig', ['transfer' => $transfer]);
    }
}
