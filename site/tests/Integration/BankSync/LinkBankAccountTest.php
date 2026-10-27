<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\LinkBankAccountAction;
use App\BankSync\Application\UnlinkBankAccountAction;
use App\BankSync\Entity\BankAccount;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Exception\BankAccountNotFoundException;
use App\BankSync\Exception\MoneyAccountAlreadyLinkedException;
use App\BankSync\Exception\MoneyAccountNotLinkableException;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Tests\Builders\BankSync\BankAccountBuilder;
use App\Tests\Builders\BankSync\BankConnectionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

/**
 * Привязка счёта банка к денежному счёту: уникальность, идемпотентность, IDOR, валюта,
 * признак «выписка из банка по API» в той же транзакции.
 */
final class LinkBankAccountTest extends IntegrationTestCase
{
    private Company $company;
    private BankConnection $bankConnection;
    private BankAccount $bankAccount;
    private MoneyAccount $moneyAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany();
        $this->bankConnection = $this->createConnection($this->company);
        $this->bankAccount = $this->createBankAccount($this->bankConnection, '40702810900000000001');
        $this->moneyAccount = $this->createMoneyAccount($this->company, 'RUB');
        $this->em->flush();
    }

    public function testLinkStoresPairAndMarksSource(): void
    {
        $this->link($this->bankAccount, $this->moneyAccount);

        self::assertSame($this->id($this->moneyAccount), $this->linkedMoneyAccountId($this->bankAccount));
        self::assertSame('alfa', $this->source($this->moneyAccount));
    }

    public function testRepeatedLinkIsSameResult(): void
    {
        $this->link($this->bankAccount, $this->moneyAccount);
        $linkedAt = $this->em->getConnection()->fetchOne('SELECT linked_at FROM bank_sync_account WHERE id = :id', ['id' => $this->bankAccount->getId()]);

        $this->link($this->bankAccount, $this->moneyAccount);

        self::assertSame($linkedAt, $this->em->getConnection()->fetchOne('SELECT linked_at FROM bank_sync_account WHERE id = :id', ['id' => $this->bankAccount->getId()]));
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT count(*) FROM bank_sync_account WHERE money_account_id = :id', ['id' => $this->id($this->moneyAccount)]));
    }

    public function testMoneyAccountLinkedToAnotherBankAccountIsRejected(): void
    {
        $other = $this->createBankAccount($this->bankConnection, '40702810900000000002');
        $this->em->flush();
        $this->link($other, $this->moneyAccount);

        try {
            $this->link($this->bankAccount, $this->moneyAccount);
            self::fail('один денежный счёт — один счёт банка');
        } catch (MoneyAccountAlreadyLinkedException) {
        }

        self::assertNull($this->linkedMoneyAccountId($this->bankAccount));
        self::assertSame($this->id($this->moneyAccount), $this->linkedMoneyAccountId($other));
    }

    public function testUniqueIndexHoldsWhenPreCheckIsBypassed(): void
    {
        $other = $this->createBankAccount($this->bankConnection, '40702810900000000002');
        $this->em->flush();
        // Гонка: чужая привязка закоммичена мимо проверки действия — unique в БД, а не 500.
        $this->em->getConnection()->executeStatement(
            'UPDATE bank_sync_account SET money_account_id = :moneyAccountId WHERE id = :id',
            ['moneyAccountId' => $this->id($this->moneyAccount), 'id' => $other->getId()],
        );

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement(
            'UPDATE bank_sync_account SET money_account_id = :moneyAccountId WHERE id = :id',
            ['moneyAccountId' => $this->id($this->moneyAccount), 'id' => $this->bankAccount->getId()],
        );
    }

    public function testRelinkMovesSourceToNewMoneyAccount(): void
    {
        $second = $this->createMoneyAccount($this->company, 'RUB');
        $this->em->flush();
        $this->link($this->bankAccount, $this->moneyAccount);

        $this->link($this->bankAccount, $second);

        self::assertSame($this->id($second), $this->linkedMoneyAccountId($this->bankAccount));
        self::assertNull($this->source($this->moneyAccount));
        self::assertSame('alfa', $this->source($second));
    }

    public function testUnlinkClearsPairAndSourceAndRepeatIsNoop(): void
    {
        $this->link($this->bankAccount, $this->moneyAccount);

        $this->unlink($this->bankAccount);
        $this->unlink($this->bankAccount);

        self::assertNull($this->linkedMoneyAccountId($this->bankAccount));
        self::assertNull($this->source($this->moneyAccount));
    }

    public function testOtherCurrencyOrInactiveMoneyAccountIsRejected(): void
    {
        $usd = $this->createMoneyAccount($this->company, 'USD');
        $inactive = $this->createMoneyAccount($this->company, 'RUB');
        $inactive->setIsActive(false);
        $this->em->flush();

        foreach ([[$usd, MoneyAccountNotLinkableException::CURRENCY], [$inactive, MoneyAccountNotLinkableException::NOT_FOUND]] as [$moneyAccount, $reason]) {
            try {
                $this->link($this->bankAccount, $moneyAccount);
                self::fail($reason);
            } catch (MoneyAccountNotLinkableException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
        self::assertNull($this->linkedMoneyAccountId($this->bankAccount));
    }

    public function testForeignMoneyAccountOrBankAccountIsRejected(): void
    {
        $otherCompany = $this->createCompany();
        $foreignMoneyAccount = $this->createMoneyAccount($otherCompany, 'RUB');
        $foreignBankAccount = $this->createBankAccount($this->createConnection($otherCompany), '40702810900000000009');
        $this->em->flush();

        try {
            $this->link($this->bankAccount, $foreignMoneyAccount);
            self::fail('чужой денежный счёт');
        } catch (MoneyAccountNotLinkableException $exception) {
            self::assertSame(MoneyAccountNotLinkableException::NOT_FOUND, $exception->reason);
        }

        $this->expectException(BankAccountNotFoundException::class);
        $this->action()($this->id($this->company), $foreignBankAccount->getId(), $this->id($this->moneyAccount));
    }

    public function testLinkAfterConcurrentDeleteDoesNothing(): void
    {
        $this->deleteBankAccountRowAfterFirstLoad();

        try {
            $this->link($this->bankAccount, $this->moneyAccount);
            self::fail('удалённый счёт банка не привязывается');
        } catch (BankAccountNotFoundException) {
        }

        self::assertNull($this->source($this->moneyAccount), 'признак не поставлен без привязки');
    }

    public function testUnlinkAfterConcurrentDeleteDoesNothing(): void
    {
        $this->link($this->bankAccount, $this->moneyAccount);
        $this->deleteBankAccountRowAfterFirstLoad();

        $this->expectException(BankAccountNotFoundException::class);
        $this->unlink($this->bankAccount);
    }

    /**
     * Удаление подключения коммитится между проверкой счёта банка и блокировкой его строки:
     * строка исчезает сразу после первой загрузки сущности.
     */
    private function deleteBankAccountRowAfterFirstLoad(): void
    {
        $this->em->clear();
        $connection = $this->em->getConnection();
        $bankAccountId = $this->bankAccount->getId();
        $this->em->getEventManager()->addEventListener([Events::postLoad], new class($connection, $bankAccountId) {
            private bool $done = false;

            public function __construct(private readonly Connection $connection, private readonly string $bankAccountId)
            {
            }

            public function postLoad(PostLoadEventArgs $args): void
            {
                if ($this->done || !$args->getObject() instanceof BankAccount) {
                    return;
                }
                $this->done = true;
                $this->connection->executeStatement('DELETE FROM bank_sync_account WHERE id = :id', ['id' => $this->bankAccountId]);
            }
        });
    }

    private function link(BankAccount $bankAccount, MoneyAccount $moneyAccount): void
    {
        $this->action()($this->id($this->company), $bankAccount->getId(), $this->id($moneyAccount));
    }

    private function unlink(BankAccount $bankAccount): void
    {
        (new UnlinkBankAccountAction(
            $this->em->getRepository(BankAccount::class),
            self::getContainer()->get(CashFacade::class),
            $this->em,
            new NullLogger(),
        ))($this->id($this->company), $bankAccount->getId());
    }

    private function action(): LinkBankAccountAction
    {
        // Сервисы действий появятся в контейнере вместе с контроллерами — собираем вручную.
        return new LinkBankAccountAction(
            $this->em->getRepository(BankAccount::class),
            $this->em->getRepository(BankConnection::class),
            self::getContainer()->get(CashFacade::class),
            $this->em,
            new NullLogger(),
        );
    }

    private function linkedMoneyAccountId(BankAccount $bankAccount): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT money_account_id FROM bank_sync_account WHERE id = :id', ['id' => $bankAccount->getId()]);

        return false === $value ? null : $value;
    }

    private function source(MoneyAccount $moneyAccount): ?string
    {
        $value = $this->em->getConnection()->fetchOne('SELECT bank_api_source FROM money_account WHERE id = :id', ['id' => $this->id($moneyAccount)]);

        return false === $value ? null : $value;
    }

    private function id(Company|MoneyAccount $entity): string
    {
        return (string) $entity->getId();
    }

    private function createCompany(): Company
    {
        $companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-link-'.$companyId.'@example.test')->build();
        $company = CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function createConnection(Company $company): BankConnection
    {
        $connection = BankConnectionBuilder::aConnection()->withId(Uuid::uuid7()->toString())->withCompanyId($this->id($company))->build();
        $this->em->persist($connection);

        return $connection;
    }

    private function createBankAccount(BankConnection $connection, string $number): BankAccount
    {
        $account = BankAccountBuilder::anAccount()
            ->withId(Uuid::uuid7()->toString())
            ->forConnection($connection->getCompanyId(), $connection->getId())
            ->withAccountNumber($number)
            ->build();
        $this->em->persist($account);

        return $account;
    }

    private function createMoneyAccount(Company $company, string $currency): MoneyAccount
    {
        $id = Uuid::uuid4()->toString();
        $account = MoneyAccountBuilder::aMoneyAccount()->withId($id)->forCompany($company)->withName('Счёт '.$id)->withCurrency($currency)->build();
        $this->em->persist($account);

        return $account;
    }
}
