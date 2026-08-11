<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Service\Import;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use Ramsey\Uuid\Uuid;

final class ClientBank1CImportCurrencySafetyTest extends ClientBank1CImportServiceTestCase
{
    public function testRejectsAccountFromAnotherCompanyBeforeImport(): void
    {
        $foreignUser = new User(Uuid::uuid4()->toString());
        $foreignUser->setEmail('foreign-import@example.test');
        $foreignUser->setPassword('password');
        $foreignCompany = new Company(Uuid::uuid4()->toString(), $foreignUser);
        $foreignCompany->setName('Foreign company');
        $foreignAccount = new MoneyAccount(
            Uuid::uuid4()->toString(),
            $foreignCompany,
            MoneyAccountType::BANK,
            'Foreign account',
            'RUB',
        );

        $this->em->persist($foreignUser);
        $this->em->persist($foreignCompany);
        $this->em->persist($foreignAccount);
        $this->em->flush();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Счёт импорта принадлежит другой компании.');

        $this->service->import([], $foreignAccount, false);
    }
}
