<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Application\DTO\LinkBalanceCategoryCommand;
use App\Balance\Entity\BalanceCategory;
use App\Balance\Enum\BalanceCategoryType;
use App\Balance\Enum\BalanceLinkSourceType;
use App\Balance\Repository\BalanceCategoryRepository;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class SeedBalanceStructureAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BalanceCategoryRepository $balanceCategoryRepository,
        private BalanceCategoryLinkRepository $balanceCategoryLinkRepository,
        private LinkBalanceCategoryAction $linkBalanceCategoryAction,
    ) {
    }

    public function __invoke(string $companyId): bool
    {
        Assert::uuid($companyId);

        // Дерево строится серией flush (см. ensureCategory), поэтому сбой на середине
        // без транзакции оставил бы половину структуры и заблокировал повторный запуск.
        return (bool) $this->entityManager->wrapInTransaction(function () use ($companyId): bool {
            if ($this->balanceCategoryRepository->count(['companyId' => $companyId]) > 0) {
                return false;
            }

            $assets = $this->ensureCategory($companyId, 'Активы', BalanceCategoryType::ASSET, null, 10, 'ASSETS');

            $cash = $this->ensureCategory($companyId, 'Деньги', BalanceCategoryType::ASSET, $assets, 10, 'CASH');
            ($this->linkBalanceCategoryAction)($companyId, new LinkBalanceCategoryCommand(
                categoryId: $cash->getId(),
                sourceType: BalanceLinkSourceType::MONEY_ACCOUNTS_TOTAL,
                sourceId: null,
            ));

            $funds = $this->ensureCategory($companyId, 'Фонды и резервы', BalanceCategoryType::ASSET, $assets, 20, 'FUNDS');
            ($this->linkBalanceCategoryAction)($companyId, new LinkBalanceCategoryCommand(
                categoryId: $funds->getId(),
                sourceType: BalanceLinkSourceType::MONEY_FUNDS_TOTAL,
                sourceId: null,
            ));

            $this->ensureCategory($companyId, 'Обязательства', BalanceCategoryType::LIABILITY, null, 20, 'LIABILITIES');
            $this->ensureCategory($companyId, 'Капитал', BalanceCategoryType::EQUITY, null, 30, 'EQUITY');

            return true;
        });
    }

    private function ensureCategory(
        string $companyId,
        string $name,
        BalanceCategoryType $type,
        ?BalanceCategory $parent,
        int $sortOrder,
        ?string $code,
    ): BalanceCategory {
        $existing = $this->balanceCategoryRepository->findOneBy([
            'companyId' => $companyId,
            'name' => $name,
            'parent' => $parent,
        ]);

        if (null !== $existing) {
            return $existing;
        }

        $category = new BalanceCategory(Uuid::uuid7()->toString(), $companyId);
        $category->setName($name);
        $category->setType($type);
        $category->setParent($parent);
        $category->setSortOrder($sortOrder);
        $category->setCode($code);

        $this->entityManager->persist($category);
        // Категорию ищут через findOneBy (SQL) в LinkBalanceCategoryAction — без flush
        // она не видна и линк падает BalanceCategoryNotFoundException.
        $this->entityManager->flush();

        return $category;
    }
}
