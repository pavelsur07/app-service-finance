<?php

namespace App\Tests\Service\Import;

class IdempotencyTest extends ClientBank1CImportServiceTestCase
{
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

        $rowUpdated = $row;
        $rowUpdated['purpose'] = 'Обновлённое назначение';

        $summaryOverwrite = $this->service->import([$rowUpdated], $this->account, true);
        self::assertSame(0, $summaryOverwrite['created']);
        self::assertSame(1, $summaryOverwrite['duplicates']);
        self::assertSame(0, $summaryOverwrite['errors']);

        $transactions = $this->transactionRepository->findAll();
        self::assertCount(1, $transactions);
        self::assertSame('Обновлённое назначение', $transactions[0]->getDescription());
    }
}
