<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

use App\Balance\Service\BalanceStructureSeeder;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\PLCategory;
use App\Entity\User;
use App\Enum\MoneyAccountType;
use App\Repository\CompanyRepository;
use App\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

final class AccountBootstrapper
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompanyRepository $companies,
        private readonly CashflowCategoryRepository $cashflowCategories,
        private readonly PLCategoryRepository $plCategories,
        private readonly MoneyAccountRepository $moneyAccounts,
        private readonly BalanceStructureSeeder $balanceSeeder,
    ) {
    }

    public function bootstrapForUser(User $user): ?Company
    {
        if ($this->companies->count(['user' => $user]) > 0) {
            return null;
        }

        /** @var Company $company */
        $company = $this->em->transactional(function (EntityManagerInterface $em) use ($user): Company {
            $company = $this->createCompanyFor($user, 'Новая компания');

            $this->seedCashflow($company);
            $this->seedPL($company);
            $this->seedAccounts($company);
            $this->balanceSeeder->seedDefaultIfEmpty($company);

            $em->flush();

            return $company;
        });

        return $company;
    }

    public function ensurePlSeeded(Company $company): bool
    {
        if ($this->plCategories->count(['company' => $company]) > 0) {
            return false;
        }

        $this->seedPL($company);
        $this->em->flush();

        return true;
    }

    private function createCompanyFor(User $user, string $name): Company
    {
        $company = new Company(
            id: Uuid::uuid4()->toString(),
            user: $user,
        );
        $company->setName($name);

        $this->em->persist($company);

        return $company;
    }

    private function seedCashflow(Company $company): void
    {
        $roots = [
            'Поступления (операционные)' => [
                'Продажи',
                'Прочие поступления',
            ],
            'Списания (операционные)' => [
                'Себестоимость/Закупки',
                'Зарплата',
                'Аренда/Склад',
                'Маркетинг',
                'Налоги/Взносы',
                'Прочие расходы',
            ],
        ];

        $rootSort = 10;
        foreach ($roots as $rootName => $children) {
            $root = $this->ensureCashflow($company, $rootName, null, $rootSort);
            $rootSort += 10;

            $childSort = 10;
            foreach ($children as $childName) {
                $this->ensureCashflow($company, $childName, $root, $childSort);
                $childSort += 10;
            }
        }
    }

    private function ensureCashflow(
        Company $company,
        string $name,
        ?CashflowCategory $parent,
        int $sort,
    ): CashflowCategory {
        $existing = $this->cashflowCategories->findOneBy([
            'company' => $company,
            'name' => $name,
            'parent' => $parent,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $category = new CashflowCategory(
            id: Uuid::uuid4()->toString(),
            company: $company,
        );
        $category->setName($name);
        $category->setParent($parent);
        $category->setSort($sort);

        $this->em->persist($category);

        return $category;
    }

    private function seedPL(Company $company): void
    {
        $tree = [
            'Выручка' => ['Маркетплейсы', 'Собственные каналы'],
            'Себестоимость' => [],
            'Комиссии/Логистика' => [],
            'Маркетинг' => [],
            'Административные (G&A)' => [],
        ];

        $rootSort = 10;
        foreach ($tree as $rootName => $children) {
            $root = $this->ensurePL($company, $rootName, null, $rootSort);
            $rootSort += 10;

            $childSort = 10;
            foreach ($children as $childName) {
                $this->ensurePL($company, $childName, $root, $childSort);
                $childSort += 10;
            }
        }
    }

    private function ensurePL(
        Company $company,
        string $name,
        ?PLCategory $parent,
        int $sortOrder,
    ): PLCategory {
        $existing = $this->plCategories->findOneBy([
            'company' => $company,
            'name' => $name,
            'parent' => $parent,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $category = new PLCategory(
            id: Uuid::uuid4()->toString(),
            company: $company,
        );
        $category->setName($name);
        $category->setParent($parent);
        $category->setSortOrder($sortOrder);

        $this->em->persist($category);

        return $category;
    }

    private function seedAccounts(Company $company): void
    {
        $this->ensureAccount($company, 'Основной счет', MoneyAccountType::BANK);
        $this->ensureAccount($company, 'Основная касса', MoneyAccountType::CASH);
    }

    private function ensureAccount(Company $company, string $name, MoneyAccountType $type): MoneyAccount
    {
        $existing = $this->moneyAccounts->findOneBy([
            'company' => $company,
            'name' => $name,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $account = new MoneyAccount(
            id: Uuid::uuid4()->toString(),
            company: $company,
            type: $type,
            name: $name,
            currency: 'RUB',
        );
        $account->setIsActive(true);

        $this->em->persist($account);

        return $account;
    }
}
