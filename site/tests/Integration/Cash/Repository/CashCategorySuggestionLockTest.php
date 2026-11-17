<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Repository;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use App\Tests\Builders\Cash\CashCategorySuggestionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;

/**
 * Замена дерева ДДС переводит открытые подсказки в stale: строки берутся FOR UPDATE, иначе
 * отклонение пользователем между чтением и записью перезаписалось бы статусом stale.
 */
final class CashCategorySuggestionLockTest extends PostgresResetTestCase
{
    public function testSuggestedForCategoriesAreLockedUntilCommit(): void
    {
        $companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('lock-'.$companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid4()->toString())->forCompany($company)->build();
        $transaction = new CashTransaction(Uuid::uuid4()->toString(), $company, $account, CashDirection::OUTFLOW, '10.00', 'RUB', new \DateTimeImmutable('2026-01-15'));
        $categoryId = Uuid::uuid4()->toString();
        $suggestion = CashCategorySuggestionBuilder::aSuggestion()
            ->withId(Uuid::uuid4()->toString())
            ->withCompanyId($companyId)
            ->withTransactionId((string) $transaction->getId())
            ->withTaskId(Uuid::uuid4()->toString())
            ->suggesting($categoryId)
            ->build();
        foreach ([$owner, $company, $account, $transaction, $suggestion] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $worker = DriverManager::getConnection($this->em->getConnection()->getParams());
        $this->em->beginTransaction();
        try {
            $found = self::getContainer()->get(CashCategorySuggestionRepository::class)
                ->findSuggestedForCategoriesForUpdate($companyId, [$categoryId]);
            self::assertCount(1, $found);

            $worker->executeStatement("SET lock_timeout = '200ms'");
            $this->expectException(DriverException::class);
            $this->expectExceptionMessage('lock timeout');
            $worker->executeStatement("UPDATE cash_category_suggestion SET status = 'dismissed' WHERE id = ?", [$suggestion->getId()]);
        } finally {
            $this->em->rollback();
            $worker->close();
        }
    }
}
