<?php

namespace App\Tests\Integration\Cash\Service\Import;

class IdempotencyTest extends ClientBank1CImportServiceTestCase
{
    public function testDistinctDocumentsWithSamePaymentDetailsAreBothImported(): void
    {
        $firstRow = [
            'docType' => 'Банковский ордер',
            'docNumber' => '861307',
            'docDate' => '2026-01-29',
            'amount' => 1099,
            'payerAccount' => '40702810900000000001',
            'receiverAccount' => '40702810900000000002',
            'dateDebit' => '2026-01-29',
            'dateCredit' => null,
            'purpose' => 'Покупка товара с одинаковыми реквизитами',
            'direction' => 'outflow',
        ];
        $secondRow = $firstRow;
        $secondRow['docNumber'] = '924374';
        $rows = [$firstRow, $secondRow];

        $firstSummary = $this->service->import($rows, $this->account, false);

        self::assertSame(2, $firstSummary['created']);
        self::assertSame(0, $firstSummary['duplicates']);
        self::assertSame(0, $firstSummary['errors']);
        self::assertCount(2, $this->transactionRepository->findAll());

        $secondSummary = $this->service->import($rows, $this->account, false);

        self::assertSame(0, $secondSummary['created']);
        self::assertSame(2, $secondSummary['duplicates']);
        self::assertSame(0, $secondSummary['errors']);
        self::assertCount(2, $this->transactionRepository->findAll());
    }

    public function testRepeatedImportCountsDuplicatesAndSupportsOverwrite(): void
    {
        $row = [
            'docType' => 'Платежное поручение',
            'docNumber' => 'INV-1',
            'docDate' => '2024-01-05',
            'amount' => 1500.25,
            'payerName' => 'ООО Плательщик',
            'payerInn' => '7701000000',
            'payerAccount' => '40702810900000000003',
            'receiverName' => 'ООО Получатель',
            'receiverInn' => '7712000000',
            'receiverAccount' => '40702810900000000004',
            'dateDebit' => '2024-01-05',
            'dateCredit' => null,
            'purpose' => 'Оплата по договору',
            'direction' => 'outflow',
        ];

        $summaryFirst = $this->service->import([$row], $this->account, false);
        self::assertSame(1, $summaryFirst['created']);
        self::assertSame(0, $summaryFirst['duplicates']);
        self::assertSame(0, $summaryFirst['errors']);

        $summarySecond = $this->service->import([$row], $this->account, false);
        self::assertSame(0, $summarySecond['created']);
        self::assertSame(1, $summarySecond['duplicates']);
        self::assertSame(0, $summarySecond['errors']);

        $transaction = $this->transactionRepository->findAll()[0];
        $transaction->setDescription('Устаревшее назначение');
        $this->em->flush();

        $summaryOverwrite = $this->service->import([$row], $this->account, true);
        self::assertSame(0, $summaryOverwrite['created']);
        self::assertSame(1, $summaryOverwrite['duplicates']);
        self::assertSame(0, $summaryOverwrite['errors']);

        $transactions = $this->transactionRepository->findAll();
        self::assertCount(1, $transactions);
        self::assertSame('Оплата по договору', $transactions[0]->getDescription());
    }
}
