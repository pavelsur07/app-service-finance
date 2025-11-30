<?php

declare(strict_types=1);

namespace App\Loan\Service;

use App\Entity\Document;
use App\Entity\DocumentOperation;
use App\Entity\PLCategory;
use App\Enum\DocumentType;
use App\Loan\Entity\Loan;
use App\Loan\Entity\LoanPaymentSchedule;
use Ramsey\Uuid\Uuid;

class LoanScheduleToDocumentService
{
    /**
     * Создаёт документ ОПиУ по строке графика платежей.
     *
     * Правила:
     * - В P&L всегда идут проценты + комиссии.
     * - Если у займа includePrincipalInPnl = true, то тело тоже включаем в сумму.
     * - Используем категорию plCategoryInterest; если для комиссий задана отдельная plCategoryFee,
     *   в MVP просто используем одну категорию (проценты/комиссии) без разбиения на две операции.
     */
    public function createDocumentFromSchedule(Loan $loan, LoanPaymentSchedule $schedule): Document
    {
        if ($schedule->getLoan() !== $loan) {
            throw new \InvalidArgumentException('Платёж графика не относится к займу.');
        }

        $plCategoryInterest = $loan->getPlCategoryInterest();
        if (!$plCategoryInterest instanceof PLCategory) {
            throw new \DomainException('Для займа не настроена категория ОПиУ для процентов.');
        }

        $interest = (float) $schedule->getInterestPart();
        $fee = (float) $schedule->getFeePart();
        $principal = (float) $schedule->getPrincipalPart();

        $amount = $interest + $fee;

        if ($loan->isIncludePrincipalInPnl()) {
            $amount += $principal;
        }

        if ($amount <= 0) {
            throw new \DomainException('Сумма для документа ОПиУ должна быть больше нуля.');
        }

        $document = new Document(Uuid::uuid4()->toString(), $loan->getCompany());
        $document
            ->setDate($schedule->getDueDate())
            ->setType(DocumentType::LOANS)
            ->setDescription(sprintf(
                'Платёж по займу "%s" от %s',
                $loan->getName(),
                $schedule->getDueDate()->format('d.m.Y')
            ));

        $operation = new DocumentOperation();
        $operation
            ->setAmount(number_format($amount, 2, '.', ''))
            ->setCategory($plCategoryInterest);

        $document->addOperation($operation);

        return $document;
    }
}
