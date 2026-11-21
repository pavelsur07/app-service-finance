<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Facade;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class ExternalCashReadTest extends IntegrationTestCase
{
    public function testReferencesAreScopedSearchableAndOrderedById(): void
    {
        $company = $this->company(1);
        $other = $this->company(2);
        $rub = $this->account($company, 'Резерв 100%', 'RUB');
        $btc = $this->account($company, 'Кошелёк', 'BTC');
        $inactive = $this->account($company, 'Закрытый', 'RUB');
        $inactive->setIsActive(false);
        $this->account($other, 'Резерв другой компании', 'RUB');
        $root = $this->category($company, 'Расходы');
        $child = $this->category($company, 'Оплата аренды', $root);
        $this->category($other, 'Оплата чужой аренды');
        $this->em->flush();

        $cash = self::getContainer()->get(CashFacade::class);
        $companyId = (string) $company->getId();
        self::assertSame([['id' => $rub->getId(), 'name' => 'Резерв 100%', 'currency' => 'RUB']], $cash->listExternalAccounts($companyId, 'РЕЗЕРВ 100%', null, 2));
        self::assertSame([], $cash->listExternalAccounts($companyId, '_', null, 2));
        self::assertSame([['id' => $btc->getId(), 'name' => 'Кошелёк', 'currency' => 'BTC']], $cash->listExternalAccounts($companyId, null, $rub->getId(), 2));
        self::assertSame([['id' => $child->getId(), 'name' => 'Оплата аренды', 'parentId' => $root->getId()]], $cash->listExternalCategories($companyId, 'АРЕНДЫ', null, 2));
        self::assertSame([['id' => $root->getId(), 'name' => 'Расходы', 'parentId' => null]], $cash->listExternalCategories($companyId, null, null, 1));
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->em->persist($owner);
        $this->em->persist($company);

        return $company;
    }

    private function account(Company $company, string $name, string $currency): MoneyAccount
    {
        $type = 'BTC' === $currency ? MoneyAccountType::CRYPTO_WALLET : MoneyAccountType::BANK;
        $account = new MoneyAccount(Uuid::uuid7()->toString(), $company, $type, $name, $currency);
        $this->em->persist($account);

        return $account;
    }

    private function category(Company $company, string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid7()->toString(), $company);
        $category->setName($name)->setParent($parent);
        $this->em->persist($category);

        return $category;
    }
}
