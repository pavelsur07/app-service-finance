<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transfer\CashTransfer;
use App\Finance\Entity\Document;

final readonly class CashTransactionPage
{
    public function canCreatePnlDocument(CashTransaction $transaction): bool
    {
        $category = $transaction->getCashflowCategory();

        return $transaction->getSplits()->count() <= 1
            && $transaction->getRemainingMoney()->isPositive()
            && $category instanceof CashflowCategory
            && $category->isAllowPlDocument();
    }

    /**
     * @return Document[]
     */
    public function getDocumentsForTransaction(CashTransaction $transaction): array
    {
        $documents = array_values(array_filter(
            $transaction->getDocuments()->toArray(),
            static fn (Document $document): bool => !$document->isDeleted(),
        ));

        usort(
            $documents,
            static fn (Document $a, Document $b): int => $b->getDate() <=> $a->getDate(),
        );

        return $documents;
    }

    /**
     * @param list<CashTransfer> $transfers
     *
     * @return array<string, CashTransfer>
     */
    public function mapTransfersToLegIds(array $transfers): array
    {
        $map = [];
        foreach ($transfers as $transfer) {
            $map[(string) $transfer->getSourceTransaction()->getId()] = $transfer;
            $map[(string) $transfer->getTargetTransaction()->getId()] = $transfer;
        }

        return $map;
    }
}
