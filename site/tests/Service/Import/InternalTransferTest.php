<?php

namespace App\Tests\Integration\Import;

class InternalTransferTest extends ClientBank1CImportServiceTestCase
{
    public function testKeywordBasedTransferMarksTransactionAndSkipsCounterparty(): void
    {
        $row = [
            'docType' => 'Платежное поручение',
            'docNumber' => 'TR-1',
            'docDate' => '2024-02-01',
            'amount' => 2500,
            'payerName' => 'ООО Источник',
            'payerInn' => '7701999999',
            'payerAccount' => '40702810900000000001',
            'receiverName' => 'ООО Получатель',
            'receiverInn' => '7712888888',
            'receiverAccount' => '40702810900000000002',
            'dateDebit' => '2024-02-02',
            'dateCredit' => null,
            'purpose' => 'Перевод средств между счетами компании',
            'direction' => 'self-transfer',
        ];

        $summary = $this->service->import([$row], $this->account, false);
        self::assertSame(1, $summary['created']);
        self::assertSame(0, $summary['duplicates']);

        $transactions = $this->transactionRepository->findAll();
        self::assertCount(1, $transactions);

        $transaction = $transactions[0];
        self::assertTrue($transaction->isTransfer());
        self::assertNull($transaction->getCounterparty());
    }
}
