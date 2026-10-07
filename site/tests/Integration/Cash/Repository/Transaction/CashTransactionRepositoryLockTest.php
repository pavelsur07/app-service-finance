<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Repository\Transaction;

use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\Exception\ORMException;
use Ramsey\Uuid\Uuid;

/**
 * GlitchTip #414: ручное применение автоправила и воркер читали транзакцию без блокировки и
 * вставляли одну и ту же строку разбивки дважды (uniq_cts_tx_category). Блокировку в одном
 * процессе не показать, поэтому закрепляем её свойства: метод берёт PESSIMISTIC_WRITE
 * (Doctrine без транзакции отказывает) и не выходит за границы компании.
 */
final class CashTransactionRepositoryLockTest extends IntegrationTestCase
{
    public function testLockedLoadRequiresTransactionAndRespectsCompanyScope(): void
    {
        $user = UserBuilder::aUser()->withIndex(41)->build();
        $company = CompanyBuilder::aCompany()->withIndex(41)->withOwner($user)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            '100.00',
            'RUB',
            new \DateTimeImmutable('2024-01-02'),
        );
        foreach ([$user, $company, $account, $transaction] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        $this->em->clear();

        $repository = self::getContainer()->get(CashTransactionRepository::class);
        $id = (string) $transaction->getId();
        $companyId = (string) $company->getId();

        try {
            $repository->findOneByIdAndCompanyIdForUpdate($id, $companyId);
            self::fail('Блокирующая загрузка вне транзакции должна отказывать: иначе блокировка не берётся.');
        } catch (ORMException) {
            // ожидаемо: PESSIMISTIC_WRITE требует активной транзакции
        }

        $this->em->wrapInTransaction(function () use ($repository, $id, $companyId): void {
            $found = $repository->findOneByIdAndCompanyIdForUpdate($id, $companyId);
            self::assertInstanceOf(CashTransaction::class, $found);
            self::assertSame($id, $found->getId());

            $this->em->clear();
            self::assertNull(
                $repository->findOneByIdAndCompanyIdForUpdate($id, '11111111-1111-1111-1111-999999999999'),
                'Чужая компания не должна получить транзакцию, даже под блокировкой.',
            );
        });
    }
}
