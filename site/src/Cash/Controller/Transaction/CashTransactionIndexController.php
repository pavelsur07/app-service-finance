<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionFilters;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\FiatCurrency;
use App\Cash\Infrastructure\Query\CashCategorizationQuery;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Repository\Transfer\CashTransferRepository;
use App\Company\Facade\CounterpartyFacade;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/finance/cash-transactions')]
final class CashTransactionIndexController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly CashTransactionRepository $txRepo,
        private readonly \App\Cash\Http\CashTransactionPage $page,
        private readonly CashTransferRepository $transferRepository,
        private readonly MoneyAccountRepository $accountRepo,
        private readonly CashflowCategoryRepository $categoryRepo,
        private readonly CounterpartyFacade $counterpartyFacade,
        private readonly CashCategorizationQuery $categorizationQuery,
    ) {
    }

    #[Route('/', name: 'cash_transaction_index', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        try {
            $filters = CashTransactionFilters::fromQuery($request->query->all());
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('cash_transaction_index');
        }
        $page = max(1, (int) $request->query->get('page', 1));
        $limit = 20;
        $pager = $this->txRepo->paginateByCompanyWithFilters($company, $filters, $page, $limit);
        /** @var list<CashTransaction> $transactions */
        $transactions = array_values(iterator_to_array($pager->getCurrentPageResults()));
        $this->txRepo->warmSplits($transactions);
        $transactionIds = array_map(static fn (CashTransaction $transaction): string => (string) $transaction->getId(), $transactions);
        $transferByTransactionId = $this->page->mapTransfersToLegIds($this->transferRepository->findByTransactionIdsAndCompanyId($transactionIds, (string) $company->getId()));
        $accounts = $this->accountRepo->findBy(['company' => $company]);
        $categories = $this->categoryRepo->findTreeByCompany($company);
        // Неархивные по показанному названию плюс выбранный в фильтре (даже архивный):
        // шаблону нужны только они — список и подпись выбранного.
        $counterparties = $this->counterpartyFacade->getSelectable((string) $company->getId(), $filters['counterpartyId'] ?? null);

        return $this->render('transaction/index.html.twig', [
            'transactions' => $transactions,
            'filters' => $filters,
            'accounts' => $accounts,
            'categories' => $categories,
            'counterparties' => $counterparties,
            'currencies' => FiatCurrency::cases(),
            'pager' => $pager,
            'transferByTransactionId' => $transferByTransactionId,
            // ИИ-подсказки статей (режим подсказок): только у операций без статьи.
            'aiSuggestionByTransactionId' => $this->categorizationQuery->shownSuggestions((string) $company->getId(), $transactionIds),
            // Статья, проставленная автоприменением и не изменённая: значок «ИИ» с уверенностью.
            'aiAutoAppliedByTransactionId' => $this->categorizationQuery->autoAppliedConfidence((string) $company->getId(), $transactionIds),
            'hasStandaloneTransactions' => (bool) array_diff($transactionIds, array_keys($transferByTransactionId)),
        ]);
    }
}
