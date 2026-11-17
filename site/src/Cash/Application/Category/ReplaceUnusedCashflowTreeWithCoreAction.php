<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Application\DTO\CashflowCoreReplacePlan;
use App\Cash\Application\DTO\Template\CashflowCoreTemplateNode;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Exception\CashflowCoreTemplateException;
use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Базовое дерево ДДС (`core.yaml`) для существующей компании: несистемные статьи
 * удаляются и заменяются ядром, но только когда ни на одну из них ничего не ссылается.
 * Открытые ИИ-подсказки на удаляемые статьи (поле без FK) становятся `stale`.
 * Повтор на дереве, уже совпадающем с ядром, ничего не меняет.
 *
 * Транзакцией владеет вызывающий (команда `app:cash:cashflow-core:apply`, `wrapInTransaction`).
 * Промежуточные `flush()` внутри неё не коммитят: удаление должно дойти до БД, чтобы
 * `ApplyCashflowCoreTemplateAction` увидел компанию без своих статей. Ссылку, добавленную
 * между проверкой и удалением, отклоняет FK, и транзакция откатывается целиком.
 */
final class ReplaceUnusedCashflowTreeWithCoreAction
{
    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly CashflowCategoryRepository $categories,
        private readonly CashCategorySuggestionRepository $suggestions,
        private readonly CashflowCategoryUsageQuery $usageQuery,
        private readonly CashflowCoreTemplateReader $reader,
        private readonly EnsureCashflowSystemCategoriesAction $ensureSystem,
        private readonly ApplyCashflowCoreTemplateAction $applyCore,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function plan(string $companyId): CashflowCoreReplacePlan
    {
        $company = $this->company($companyId);
        $regular = $this->regular($company);

        $paths = [];
        foreach ($regular as $category) {
            $paths[(string) $category->getId()] = $this->path($category);
        }

        $template = $this->templatePaths();
        $current = array_values($paths);
        sort($current);
        if ([] !== $current && $current === $template) {
            return new CashflowCoreReplacePlan(true, [], [], 0, \count($template));
        }

        $usage = ($this->usageQuery)($companyId);
        $removable = [];
        $blocked = [];
        foreach ($paths as $id => $path) {
            if (isset($usage[$id]) && $usage[$id]->isUsed()) {
                $blocked[] = $path.' — '.$usage[$id]->summary();
            } else {
                $removable[] = $path;
            }
        }

        return new CashflowCoreReplacePlan(
            false,
            $removable,
            $blocked,
            \count($this->suggestions->findSuggestedForCategories($companyId, array_keys($paths))),
            \count($template),
        );
    }

    /**
     * @return int сколько узлов ядра создано (0 — ядро уже применено)
     *
     * @throws CashflowCoreTemplateException хоть одна статья компании используется
     */
    public function __invoke(string $companyId): int
    {
        $connection = $this->entityManager->getConnection();
        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Замена дерева ДДС выполняется только внутри транзакции вызывающего.');
        }
        // Тот же ключ, что у CashflowCategoryStructureMigrator: обе меняют дерево компании.
        $connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:companyId))', ['companyId' => $companyId]);

        ($this->ensureSystem)($companyId);

        $plan = $this->plan($companyId);
        if ($plan->alreadyApplied) {
            return 0;
        }
        if ([] !== $plan->blocked) {
            throw new CashflowCoreTemplateException('Статьи ДДС компании используются, замена невозможна: '.implode('; ', $plan->blocked));
        }

        $regular = $this->regular($this->company($companyId));
        $ids = array_map(static fn (CashflowCategory $c): string => (string) $c->getId(), $regular);
        $now = $this->clock->now();
        foreach ($this->suggestions->findSuggestedForCategories($companyId, $ids) as $suggestion) {
            $suggestion->markStale('category_removed', $now);
        }

        // Сначала листья: строка родителя держит FK parent_id детей.
        $byDepth = [];
        foreach ($regular as $category) {
            $byDepth[substr_count($this->path($category), '/')][] = $category;
        }
        krsort($byDepth);
        foreach ($byDepth as $level) {
            foreach ($level as $category) {
                $this->entityManager->remove($category);
            }
            $this->entityManager->flush();
        }

        $created = ($this->applyCore)($companyId);
        $this->entityManager->flush();

        $this->logger->info('Cashflow tree replaced with core template', [
            'companyId' => $companyId,
            'removed' => \count($regular),
            'created' => $created,
            'staleSuggestions' => $plan->staleSuggestions,
        ]);

        return $created;
    }

    private function company(string $companyId): Company
    {
        return $this->companyFacade->findById($companyId)
            ?? throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));
    }

    /** @return list<CashflowCategory> */
    private function regular(Company $company): array
    {
        return $this->categories->findBy(['company' => $company, 'isSystem' => false]);
    }

    /** «CF_OP/Выплаты/Аренда»: путь от системной группы. */
    private function path(CashflowCategory $category): string
    {
        $names = [];
        for ($node = $category; null !== $node && !$node->isSystem(); $node = $node->getParent()) {
            $names[] = $node->getName();
        }

        return implode('/', [$node?->getSystemCode() ?? '-', ...array_reverse($names)]);
    }

    /** @return list<string> */
    private function templatePaths(): array
    {
        $paths = [];
        $walk = static function (string $prefix, array $nodes) use (&$walk, &$paths): void {
            /** @var list<CashflowCoreTemplateNode> $nodes */
            foreach ($nodes as $node) {
                $paths[] = $prefix.'/'.$node->name;
                $walk($prefix.'/'.$node->name, $node->children);
            }
        };
        foreach ($this->reader->read() as $section => $nodes) {
            $walk($section, $nodes);
        }
        sort($paths);

        return $paths;
    }
}
