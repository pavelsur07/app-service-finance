<?php

declare(strict_types=1);

namespace App\Tests\Integration\BankSync;

use App\BankSync\Application\BankAccountCatalog;
use App\BankSync\Application\DTO\BankAccountDTO;
use App\BankSync\Entity\BankAccount;
use App\BankSync\Repository\BankAccountRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class BankAccountCatalogTest extends IntegrationTestCase
{
    public function testDeletedConnectionGetsNoAccounts(): void
    {
        $companyId = Uuid::uuid4()->toString();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->withEmail('bank-catalog-'.$companyId.'@example.test')->build();
        $this->em->persist($owner);
        $this->em->persist(CompanyBuilder::aCompany()->withId($companyId)->withOwner($owner)->build());
        $this->em->flush();
        /** @var BankAccountRepository $repository */
        $repository = $this->em->getRepository(BankAccount::class);
        $catalog = new BankAccountCatalog($repository, $this->em);
        // Подключение удалили, пока банк отдавал список счетов.
        $deletedConnectionId = Uuid::uuid7()->toString();

        $accounts = $this->em->wrapInTransaction(static fn (): array => $catalog->synchronize(
            $companyId,
            $deletedConnectionId,
            [new BankAccountDTO('40702810100000000001', 'RUB')],
            new \DateTimeImmutable(),
        ));

        self::assertSame([], $accounts);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM bank_sync_account WHERE company_id = :companyId',
            ['companyId' => $companyId],
        ));
    }
}
