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

        $templateCodes = $this->templateCodes();
        $template = array_keys($templateCodes);
        sort($template);
        $current = array_values($paths);
        sort($current);
        if ([] !== $current && $current === $template) {
            $missing = array_filter($regular, static fn (CashflowCategory $c): bool => null === $c->getGroupCode());

            return new CashflowCoreReplacePlan(true, [], [], 0, \count($template), \count($missing));
        }

        $usage = ($this->usageQuery)($companyId);
        $removable = [];
        $blocked = [];
        $coded = [];
        foreach ($regular as $category) {
            $coded[(string) $category->getId()] = $category->getGroupCode();
        }
        foreach ($paths as $id => $path) {
            if (null !== $coded[$id]) {
                $blocked[] = $path.' — статья базового дерева (код '.$coded[$id].')';
            } elseif (isset($usage[$id]) && $usage[$id]->isUsed()) {
                $blocked[] = $path.' — '.$usage[$id]->summary();
            } else {
                $removable[] = $path;
            }
        }

        // Ответ ИИ на уже отправленный список статей мог бы сослаться на удалённую статью:
        // такая подсказка не видна в UI и исключает операцию из повторной категоризации.
        $pendingAi = $this->suggestions->countRequested($companyId);
        if ($pendingAi > 0) {
            $blocked[] = sprintf('ИИ ещё не ответил по %d операциям — повторите после ответа', $pendingAi);
        }

        return new CashflowCoreReplacePlan(
            false,
            $removable,
            $blocked,
            \count($this->suggestions->findSuggestedForCategories($companyId, array_keys($paths))),
            \count($template),
            0,
        );
    }

    /**
     * @return int сколько узлов ядра создано или, если дерево уже совпадает с ядром, сколько кодов
     *             проставлено (0 — менять нечего)
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
        // Дерево, загруженное планом до транзакции, могло устареть (форма advisory lock не берёт):
        // строки под FOR UPDATE и перечитаны — пути и коды считаются по текущему состоянию.
        $this->categories->lockTreeByCompany($this->company($companyId));

        $plan = $this->plan($companyId);
        if ($plan->alreadyApplied) {
            return $this->assignMissingCodes($companyId);
        }
        if ([] !== $plan->blocked) {
            throw new CashflowCoreTemplateException('Замена дерева ДДС невозможна: '.implode('; ', $plan->blocked));
        }

        $regular = $this->regular($this->company($companyId));
        $ids = array_map(static fn (CashflowCategory $c): string => (string) $c->getId(), $regular);
        $now = $this->clock->now();
        foreach ($this->suggestions->findSuggestedForCategoriesForUpdate($companyId, $ids) as $suggestion) {
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
        if ($created !== $plan->coreNodes) {
            // Статью завели параллельно (форма advisory lock не берёт), и ядро себя пропустило:
            // откатываем удаление, иначе компания останется без дерева.
            throw new CashflowCoreTemplateException(sprintf('Ядро не применено: создано %d из %d узлов — дерево ДДС компании изменили во время замены, повторите.', $created, $plan->coreNodes));
        }
        $this->entityManager->flush();

        $this->logger->info('Cashflow tree replaced with core template', [
            'companyId' => $companyId,
            'removed' => \count($regular),
            'created' => $created,
            'staleSuggestions' => $plan->staleSuggestions,
        ]);

        return $created;
    }

    /** Дерево совпадает с ядром по путям: статьям без кода — код узла ядра того же пути. */
    private function assignMissingCodes(string $companyId): int
    {
        $templateCodes = $this->templateCodes();
        $assigned = 0;
        foreach ($this->regular($this->company($companyId)) as $category) {
            if (null === $category->getGroupCode()) {
                $category->assignGroupCode($templateCodes[$this->path($category)]);
                ++$assigned;
            }
        }
        $this->entityManager->flush();

        if ($assigned > 0) {
            $this->logger->info('Cashflow core group codes assigned', ['companyId' => $companyId, 'assigned' => $assigned]);
        }

        return $assigned;
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

    /** @return array<string, string> путь узла ядра → его код */
    private function templateCodes(): array
    {
        $codes = [];
        $walk = static function (string $prefix, array $nodes) use (&$walk, &$codes): void {
            /** @var list<CashflowCoreTemplateNode> $nodes */
            foreach ($nodes as $node) {
                $codes[$prefix.'/'.$node->name] = $node->code;
                $walk($prefix.'/'.$node->name, $node->children);
            }
        };
        foreach ($this->reader->read() as $section => $nodes) {
            $walk($section, $nodes);
        }

        return $codes;
    }
}
