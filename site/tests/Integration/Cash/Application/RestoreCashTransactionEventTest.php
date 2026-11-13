<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\Transaction\RestoreCashTransactionAction;
use App\Cash\Domain\Event\CashTransactionsChanged;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;

final class RestoreCashTransactionEventTest extends IntegrationTestCase
{
    #[DataProvider('outcomes')]
    public function testChangeEventIsPublishedOnlyAfterSuccessfulTransaction(bool $fail): void
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7().'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId(Uuid::uuid7()->toString())->forCompany($company)->build()->setOpeningBalanceDate(new \DateTimeImmutable('today'));
        $tx = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid7()->toString())->forCompany($company)->withMoneyAccount($account)->build()->setOccurredAt(new \DateTimeImmutable('today'));
        $tx->markDeleted(null);
        foreach ([$owner, $company, $account, $tx] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
        if ($fail) {
            $this->connection->executeStatement("CREATE FUNCTION pg_temp.reject_restored_balance() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'test restore failure'; END; $$ LANGUAGE plpgsql");
            $this->connection->executeStatement('CREATE TRIGGER reject_restored_balance BEFORE INSERT ON money_account_daily_balance FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_restored_balance()');
        }
        $observed = [];
        $level = $this->connection->getTransactionNestingLevel();
        self::getContainer()->get('event_dispatcher')->addListener(CashTransactionsChanged::class, function (CashTransactionsChanged $event) use (&$observed, $tx): void {
            $observed[] = [$event->companyId, $this->connection->getTransactionNestingLevel(), $this->connection->fetchOne('SELECT deleted_at FROM cash_transaction WHERE id = :id', ['id' => $tx->getId()])];
        });
        try {
            self::getContainer()->get(RestoreCashTransactionAction::class)($tx);
            self::assertFalse($fail);
        } catch (DriverException $exception) {
            self::assertTrue($fail);
            self::assertStringContainsString('test restore failure', $exception->getMessage());
        }
        self::assertSame($fail ? [] : [[$company->getId(), $level, null]], $observed);
    }

    /** @return iterable<array{bool}> */
    public static function outcomes(): iterable
    {
        yield 'commit' => [false];
        yield 'rollback' => [true];
    }
}
