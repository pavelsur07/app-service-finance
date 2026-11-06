<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import;

use App\Cash\Entity\Transaction\CashTransaction;

final class ClientBank1CDescriptionOverrideTest extends ClientBank1CImportServiceTestCase
{
    public function testOverwritePreservesExplicitDescriptionAndSourceIdentity(): void
    {
        $row = [
            'docType' => 'Платежное поручение',
            'docNumber' => '101',
            'docDate' => '2026-10-01',
            'amount' => '100.00',
            'payerAccount' => $this->account->getAccountNumber(),
            'receiverAccount' => '40702810900000000002',
            'dateDebit' => '2026-10-01',
            'purpose' => 'Исходное описание',
            'direction' => 'outflow',
            'raw' => ['НазначениеПлатежа' => 'Исходное описание'],
        ];
        $this->service->import([$row], $this->account, false, fn () => $this->em->flush());
        $transaction = $this->transactionRepository->findOneBy(['company' => $this->company]);
        self::assertInstanceOf(CashTransaction::class, $transaction);
        $id = $transaction->getId();
        $externalId = $transaction->getExternalId();
        $rawData = $transaction->getRawData();
        $transaction->overrideImportedDescription('Уточнённое описание');
        $this->em->flush();
        $this->em->refresh($transaction);
        self::assertTrue($transaction->isImportedDescriptionOverridden());

        $this->service->import([$row], $this->account, true, fn () => $this->em->flush());
        $this->em->refresh($transaction);

        self::assertSame('Уточнённое описание', $transaction->getDescription());
        self::assertSame($id, $transaction->getId());
        self::assertSame($externalId, $transaction->getExternalId());
        self::assertSame($rawData, $transaction->getRawData());
        self::assertSame('100.00', $transaction->getAmount());
        self::assertSame(1, $this->transactionRepository->count(['company' => $this->company]));
    }
}
