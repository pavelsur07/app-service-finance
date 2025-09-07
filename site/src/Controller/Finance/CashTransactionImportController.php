<?php

namespace App\Controller\Finance;

use App\DTO\CashTransactionDTO;
use App\Entity\MoneyAccount;
use App\Enum\CashDirection;
use App\Repository\MoneyAccountRepository;
use App\Service\ActiveCompanyService;
use App\Service\CashTransactionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/finance/cash-transactions/import')]
class CashTransactionImportController extends AbstractController
{
    public function __construct(private ActiveCompanyService $companyService)
    {
    }

    #[Route('/', name: 'cash_transaction_import_upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $form = $this->createFormBuilder()
            ->add('file', FileType::class, ['label' => 'CSV файл'])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var \Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $form->get('file')->getData();
            $handle = fopen($file->getPathname(), 'r');
            $header = fgetcsv($handle, 0, ';');
            $rows = [];
            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }
                $rows[] = $row;
            }
            fclose($handle);

            $session = $request->getSession();
            $session->set('tx_import_header', $header);
            $session->set('tx_import_rows', $rows);

            return $this->redirectToRoute('cash_transaction_import_map');
        }

        return $this->render('transaction/import.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/map', name: 'cash_transaction_import_map', methods: ['GET', 'POST'])]
    public function map(
        Request $request,
        MoneyAccountRepository $accountRepo,
        CashTransactionService $txService
    ): Response {
        $session = $request->getSession();
        $header = $session->get('tx_import_header');
        $rows = $session->get('tx_import_rows');
        if (!$header || !$rows) {
            return $this->redirectToRoute('cash_transaction_import_upload');
        }

        $choices = [];
        foreach ($header as $idx => $name) {
            $choices[$name ?: 'Column '.$idx] = $idx;
        }

        $company = $this->companyService->getActiveCompany();

        $form = $this->createFormBuilder()
            ->add('account', ChoiceType::class, [
                'choices' => $accountRepo->findBy(['company' => $company]),
                'choice_label' => fn (MoneyAccount $a) => $a->getName(),
                'choice_value' => 'id',
            ])
            ->add('date', ChoiceType::class, ['choices' => $choices, 'label' => 'Колонка даты'])
            ->add('description', ChoiceType::class, ['choices' => $choices, 'label' => 'Колонка описания'])
            ->add('amount', ChoiceType::class, ['choices' => $choices, 'label' => 'Колонка суммы'])
            ->getForm();

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            /** @var MoneyAccount $account */
            $account = $data['account'];
            $dateIdx = $data['date'];
            $descIdx = $data['description'];
            $amountIdx = $data['amount'];
            $count = 0;
            foreach ($rows as $row) {
                if (!isset($row[$amountIdx]) || !isset($row[$dateIdx])) {
                    continue;
                }
                $raw = str_replace([' ', ','], ['', '.'], $row[$amountIdx]);
                if ($raw === '') {
                    continue;
                }
                $amount = (float) $raw;
                $dto = new CashTransactionDTO();
                $dto->companyId = $company->getId();
                $dto->moneyAccountId = $account->getId();
                $dto->currency = $account->getCurrency();
                $dto->direction = $amount < 0 ? CashDirection::OUTFLOW : CashDirection::INFLOW;
                $dto->amount = abs($amount);
                $dto->occurredAt = new \DateTimeImmutable($row[$dateIdx]);
                $dto->description = $row[$descIdx] ?? null;
                $txService->add($dto);
                $count++;
            }
            $session->remove('tx_import_header');
            $session->remove('tx_import_rows');
            $this->addFlash('success', 'Импортировано ' . $count . ' транзакций');
            return $this->redirectToRoute('cash_transaction_index');
        }

        return $this->render('transaction/import_map.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}

