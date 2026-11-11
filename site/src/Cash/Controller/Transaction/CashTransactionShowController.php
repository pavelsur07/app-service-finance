<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashTransactionShowController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionRepository $txRepo,
        private readonly CashTransferRepository $transferRepository,
        private readonly \App\Cash\Http\CashTransactionPage $page,
    ) {
    }

    #[Route('/{id}', name: 'cash_transaction_show', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function __invoke(string $id): Response
    {
        $company = $this->companyService->getActiveCompany();
        $tx = $this->txRepo->findOneByIdAndCompanyId($id, (string) $company->getId()) ?? throw $this->createNotFoundException();
        $transfer = $this->transferRepository->findOneByTransactionAndCompanyId($tx, (string) $company->getId());

        return $this->render('transaction/show.html.twig', ['tx' => $tx, 'transfer' => $transfer, 'canCreatePnlDocument' => null === $transfer && $this->page->canCreatePnlDocument($tx), 'pnlDocuments' => $this->page->getDocumentsForTransaction($tx)]);
    }
}
