<?php

declare(strict_types=1);

namespace App\Cash\Controller\Transaction;

use App\Cash\Application\DTO\CashTransactionSplitInput;
use App\Cash\Application\DTO\CashTransactionSplitsInput;
use App\Cash\Application\SaveCashTransactionSplitsAction;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Form\Transaction\CashTransactionSplitsType;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class CashTransactionSplitsController extends AbstractController
{
    #[Route('/finance/cash-transactions/{id}/splits', name: 'cash_transaction_splits', methods: ['GET', 'POST'])]
    public function __invoke(
        string $id,
        Request $request,
        ActiveCompanyService $companyService,
        CashTransactionRepository $transactionRepository,
        CashflowCategoryRepository $categoryRepository,
        SaveCashTransactionSplitsAction $saveSplits,
    ): Response {
        $company = $companyService->getActiveCompany();

        $transaction = $transactionRepository->findOneByIdAndCompanyId($id, (string) $company->getId());
        if (!$transaction instanceof CashTransaction) {
            throw $this->createNotFoundException();
        }

        $categories = $categoryRepository->findTreeByCompany($company);

        $form = $this->createForm(CashTransactionSplitsType::class, $this->buildInput($transaction), [
            'categories' => $categories,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $saveSplits($transaction, $form->getData());

                $this->addFlash('success', 'Разбивка сохранена.');

                return $this->redirectToRoute('cash_transaction_show', ['id' => $id]);
            } catch (\DomainException $e) {
                // Инварианты набора живут в агрегате, поэтому его отказ показываем как
                // ошибку формы, а не как 500: пользователю нужно поправить строки.
                $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));
            }
        }

        return $this->render('transaction/splits.html.twig', [
            'transaction' => $transaction,
            'form' => $form->createView(),
        ]);
    }

    private function buildInput(CashTransaction $transaction): CashTransactionSplitsInput
    {
        $input = new CashTransactionSplitsInput();

        foreach ($transaction->getSplits() as $split) {
            /* @var CashTransactionSplit $split */
            $row = new CashTransactionSplitInput();
            $row->cashflowCategoryId = (string) $split->getCashflowCategory()->getId();
            $row->amount = $split->getAmount();
            $input->rows[] = $row;
        }

        if ([] === $input->rows) {
            // У транзакции без категории строк нет: показываем одну пустую,
            // иначе форма открывается без единого поля.
            $input->rows[] = new CashTransactionSplitInput();
        }

        return $input;
    }
}
