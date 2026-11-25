<?php

declare(strict_types=1);

namespace App\Balance\Facade;

use App\Balance\Application\BalanceLedgerService;
use App\Balance\Application\DTO\BalanceBookStatus;
use App\Balance\Application\DTO\BalancePostingAccount;
use App\Balance\Application\SeedBalanceStructureAction;
use App\Balance\Infrastructure\Query\BalanceReportQuery;
use App\Balance\Infrastructure\Query\LedgerQuery;
use App\Balance\ReadModel\BalanceReport;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use App\Balance\Security\BalanceAccess;

final readonly class BalanceFacade
{
    public function __construct(
        private BalanceCategoryRepositoryInterface $balanceCategoryRepository,
        private BalanceReportQuery $balanceReportQuery,
        private SeedBalanceStructureAction $seedBalanceStructureAction,
        private LedgerQuery $ledgerQuery,
        private BalanceLedgerService $ledger,
        private BalanceAccess $access,
    ) {
    }

    /**
     * @return bool true, если структура была создана; false, если она уже есть
     */
    public function seedDefaultStructure(string $companyId): bool
    {
        return ($this->seedBalanceStructureAction)($companyId);
    }

    /**
     * @return list<array{id: string, name: string, level: int, type: string, children: list<mixed>}>
     */
    public function getCategoriesForCompany(string $companyId): array
    {
        /** @var array<string, array{id: string, name: string, level: int, type: string, children: list<mixed>}> $byId */
        $byId = [];
        /** @var list<string> $rootIds */
        $rootIds = [];

        foreach ($this->balanceCategoryRepository->findTreeByCompany($companyId) as $category) {
            $byId[$category->getId()] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'level' => $category->getLevel(),
                'type' => $category->getType()->value,
                'children' => [],
            ];

            $parent = $category->getParent();
            if (null === $parent) {
                $rootIds[] = $category->getId();
            } else {
                $byId[$parent->getId()]['children'][] = $category->getId();
            }
        }

        return $this->buildTree($byId, $rootIds);
    }

    /**
     * @param array<string, array{id: string, name: string, level: int, type: string, children: list<mixed>}> $byId
     * @param list<string> $ids
     *
     * @return list<array{id: string, name: string, level: int, type: string, children: list<mixed>}>
     */
    private function buildTree(array $byId, array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $node = $byId[$id];
            $node['children'] = $this->buildTree($byId, $node['children']);
            $result[] = $node;
        }

        return $result;
    }

    public function getReportForCompany(string $companyId, \DateTimeImmutable $date): BalanceReport
    {
        return $this->balanceReportQuery->buildForCompanyAndDate($companyId, $date);
    }

    /** @return list<BalancePostingAccount> неархивные счета, у которых ни одна статья цепочки не в архиве */
    public function getPostingAccounts(string $companyId): array
    {
        $articles = [];
        foreach ($this->ledgerQuery->articles($companyId) as $article) {
            $articles[(string) $article['id']] = $article;
        }
        $path = static function (string $id) use ($articles): ?string {
            $names = [];
            for ($current = $articles[$id] ?? null; null !== $current; $current = null === $current['parent_id'] ? null : ($articles[(string) $current['parent_id']] ?? null)) {
                if ($current['is_archived']) {
                    return null;
                }
                array_unshift($names, (string) $current['name']);
            }

            return [] === $names ? null : implode(' / ', $names);
        };
        $result = [];
        foreach ($this->ledgerQuery->accounts($companyId) as $account) {
            $articlePath = $account['is_archived'] ? null : $path((string) $account['article_id']);
            if (null !== $articlePath) {
                $result[] = new BalancePostingAccount((string) $account['id'], (string) $account['code'], (string) $account['name'], $articlePath, 'asset' === $account['type']);
            }
        }

        return $result;
    }

    public function getBookStatus(string $companyId): BalanceBookStatus
    {
        $book = $this->ledgerQuery->book($companyId);

        return null === $book
            ? new BalanceBookStatus(null, null, false)
            : new BalanceBookStatus(null === $book['currency'] ? null : (string) $book['currency'], null === $book['start_date'] ? null : (string) $book['start_date'], (bool) $book['initialized']);
    }

    /** Может ли пользователь быть автором фоновых проводок (права «подготовить» и «провести»). */
    public function canPostFromSource(string $companyId, string $userId): bool
    {
        return $this->access->allowsDelegatedPosting($companyId, $userId);
    }

    /**
     * Установить остаток счёта на дату от имени автора из фонового источника. Транзакцией владеет Баланс
     * (книга под блокировкой). Возвращает id документа или null, если остаток уже такой.
     *
     * @throws \App\Balance\Exception\BalanceLedgerException книга не начата, валюта книги не та, период закрыт, счёт архивный…
     * @throws \Symfony\Component\Security\Core\Exception\AccessDeniedException у автора нет права проводить
     */
    public function setAccountBalanceFromSource(string $companyId, string $actorId, string $accountId, string $counterAccountId, \DateTimeImmutable $date, string $targetMinor, string $currency, string $reason, string $requestKey): ?string
    {
        return $this->ledger->postDelegatedTargetBalance($companyId, $actorId, $accountId, $counterAccountId, $date->format('Y-m-d'), $targetMinor, $currency, $reason, $requestKey);
    }
}
