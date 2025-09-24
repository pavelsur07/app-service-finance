<?php

namespace App\Tests\Service\Import;

class SignAndDateTest extends ClientBank1CImportServiceTestCase
{
    public function testDirectionDeterminesAmountSignAndDates(): void
    {
        $rows = [
            [
                'docType' => 'Платежное поручение',
                'docNumber' => 'OUT-1',
                'docDate' => '2024-03-05',
                'amount' => 150,
                'payerAccount' => '40702810900000000001',
                'receiverAccount' => '40702810900000000010',
                'dateDebit' => '2024-03-10',
                'dateCredit' => null,
                'purpose' => 'Оплата поставщику',
                'direction' => 'outflow',
            ],
            [
                'docType' => 'Платёжное поручение',
                'docNumber' => 'IN-1',
                'docDate' => '2024-03-07',
                'amount' => 200,
                'payerAccount' => '40702810900000000020',
                'receiverAccount' => '40702810900000000001',
                'dateDebit' => null,
                'dateCredit' => '2024-03-11',
                'purpose' => 'Оплата покупателя',
                'direction' => 'inflow',
            ],
            [
                'docType' => 'Платёжное поручение',
                'docNumber' => 'OUT-2',
                'docDate' => '2024-03-15',
                'amount' => 300,
                'payerAccount' => '40702810900000000001',
                'receiverAccount' => '40702810900000000030',
                'dateDebit' => null,
                'dateCredit' => null,
                'purpose' => 'Оплата услуг',
                'direction' => 'outflow',
            ],
        ];

        $summary = $this->service->import($rows, $this->account, false);
        self::assertSame(3, $summary['created']);
        self::assertSame(0, $summary['errors']);

        $transactions = $this->transactionRepository->findAll();
        self::assertCount(3, $transactions);

        $byNumber = [];
        foreach ($transactions as $transaction) {
            $byNumber[$transaction->getDocNumber()] = $transaction;
        }

        self::assertSame('-150.00', $byNumber['OUT-1']->getAmount());
        self::assertSame('2024-03-10', $byNumber['OUT-1']->getOccurredAt()->format('Y-m-d'));

        self::assertSame('200.00', $byNumber['IN-1']->getAmount());
        self::assertSame('2024-03-11', $byNumber['IN-1']->getOccurredAt()->format('Y-m-d'));

        self::assertSame('-300.00', $byNumber['OUT-2']->getAmount());
        self::assertSame('2024-03-15', $byNumber['OUT-2']->getOccurredAt()->format('Y-m-d'));
    }
}
