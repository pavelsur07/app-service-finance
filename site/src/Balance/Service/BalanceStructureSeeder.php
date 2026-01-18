<?php

namespace App\Balance\Service;

use App\Balance\Entity\BalanceCategory;
use App\Balance\Entity\BalanceCategoryLink;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Balance\Repository\BalanceCategoryRepository;
use App\Entity\Company;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

class BalanceStructureSeeder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BalanceCategoryRepository $balanceCategories,
        private readonly BalanceCategoryLinkRepository $balanceCategoryLinks,
    ) {
    }

    public function seedDefaultIfEmpty(Company $company): bool
    {
        if ($this->balanceCategories->count(['company' => $company]) > 0) {
            return false;
        }

        $assets = $this->ensureCategory(
            company: $company,
            name: 'Активы',
            type: BalanceCategoryType::ASSET,
            parent: null,
            sortOrder: 10,
            code: 'ASSETS',
        );

        $cash = $this->ensureCategory(
            company: $company,
            name: 'Деньги',
            type: BalanceCategoryType::ASSET,
            parent: $assets,
            sortOrder: 10,
            code: 'CASH',
        );
        $this->ensureLink(
            company: $company,
            category: $cash,
            sourceType: BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL,
            sourceId: null,
            sign: 1,
            position: 0,
        );

        $funds = $this->ensureCategory(
            company: $company,
            name: 'Фонды и резервы',
            type: BalanceCategoryType::ASSET,
            parent: $assets,
            sortOrder: 20,
            code: 'FUNDS',
        );
        $this->ensureLink(
            company: $company,
            category: $funds,
            sourceType: BalanceLinkSourceType::MONEY_FUNDS_TOTAL,
            sourceId: null,
            sign: 1,
            position: 0,
        );

        $this->ensureCategory(
            company: $company,
            name: 'Обязательства',
            type: BalanceCategoryType::LIABILITY,
            parent: null,
            sortOrder: 20,
            code: 'LIABILITIES',
        );

        $this->ensureCategory(
            company: $company,
            name: 'Капитал',
            type: BalanceCategoryType::EQUITY,
            parent: null,
            sortOrder: 30,
            code: 'EQUITY',
        );

        return true;
    }

    private function ensureCategory(
        Company $company,
        string $name,
        BalanceCategoryType $type,
        ?BalanceCategory $parent,
        int $sortOrder,
        ?string $code,
    ): BalanceCategory {
        $existing = $this->balanceCategories->findOneBy([
            'company' => $company,
            'name' => $name,
            'parent' => $parent,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $category = new BalanceCategory(id: Uuid::uuid4()->toString(), company: $company);
        $category->setName($name);
        $category->setType($type);
        $category->setParent($parent);
        $category->setSortOrder($sortOrder);
        $category->setCode($code);

        $this->em->persist($category);

        return $category;
    }

    public function ensureLink(
        Company $company,
        BalanceCategory $category,
        BalanceLinkSourceType $sourceType,
        ?string $sourceId,
        int $sign = 1,
        int $position = 0,
    ): BalanceCategoryLink {
        $existing = $this->balanceCategoryLinks->findOneBy([
            'company' => $company,
            'category' => $category,
            'sourceType' => $sourceType,
            'sourceId' => $sourceId,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $link = new BalanceCategoryLink(
            id: Uuid::uuid4()->toString(),
            company: $company,
            category: $category,
        );
        $link->setSourceType($sourceType);
        $link->setSourceId($sourceId);
        $link->setSign($sign);
        $link->setPosition($position);

        $this->em->persist($link);

        return $link;
    }
}
