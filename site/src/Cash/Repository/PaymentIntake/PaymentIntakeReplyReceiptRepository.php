<?php

declare(strict_types=1);

namespace App\Cash\Repository\PaymentIntake;

use App\Cash\Entity\PaymentIntake\PaymentIntakeReplyReceipt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PaymentIntakeReplyReceipt> */
final class PaymentIntakeReplyReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentIntakeReplyReceipt::class);
    }

    /** @companyScopeExempt Global reply replay metadata only; caller authorizes the current company/actor before this check. */
    public function hasReceipt(string $receiptKey): bool
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne('SELECT count(*) FROM cash_payment_intake_reply_receipt WHERE reply_key = :key', ['key' => $receiptKey]) > 0;
    }
}
