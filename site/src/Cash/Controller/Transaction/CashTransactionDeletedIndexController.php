<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Shared\Service\CompanyContextService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashTransactionDeletedIndexController extends AbstractController
{
    public function __construct(
        private readonly CompanyContextService $companyContextService,
        private readonly CashTransactionRepository $txRepo,
        private readonly \App\Cash\Http\CashTransactionPage $page,
        private readonly CashTransferRepository $transferRepository,
    ) {
    }

    #[Route('/deleted', name: 'cash_transaction_deleted_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $companyId = $this->companyContextService->getCompanyId();
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $pager = $this->txRepo->paginateDeletedByCompany($companyId, $page, $perPage);
        /** @var list<CashTransaction> $transactions */
        $transactions = array_values(iterator_to_array($pager->getCurrentPageResults()));
        $this->txRepo->warmSplits($transactions);
        $transferByTransactionId = $this->page->mapTransfersToLegIds($this->transferRepository->findByTransactionIdsAndCompanyId(array_map(static fn (CashTransaction $transaction): string => (string) $transaction->getId(), $transactions), $companyId));

        return $this->render('cash/transaction/deleted_index.html.twig', ['pager' => $pager, 'transactions' => $transactions, 'transferByTransactionId' => $transferByTransactionId]);
    }
}
