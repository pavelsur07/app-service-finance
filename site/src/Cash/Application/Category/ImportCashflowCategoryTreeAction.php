<?php

declare(strict_types=1);

namespace App\Cash\Application\Category;

use App\Cash\Application\DTO\CashflowCategoryImportPlan;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\PaymentPlan\PaymentPlanType;
use App\Cash\Enum\Transaction\CashflowCategoryStatus;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashCategorySuggestionRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Finance\Entity\PLCategory;
use App\Finance\Facade\PLCategoryFacade;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Загрузка изменённого дерева статей ДДС из файла выгрузки: предпросмотр и применение.
 *
 * Применение — одна транзакция под блокировкой дерева компании: план пересчитывается по
 * перечитанным строкам и применяется, только если совпал с показанным в предпросмотре.
 * Повтор той же формы находит созданные статьи по ID, выданным предпросмотром, и ничего не пишет.
 */
final class ImportCashflowCategoryTreeAction
{
    public function __construct(
        private readonly CashflowCategoryRepository $categories,
        private readonly PLCategoryFacade $plCategoryFacade,
        private readonly EntityManagerInterface $entityManager,
        private readonly CashflowCategoryImportPlanner $planner,
        private readonly CashCategorySuggestionRepository $suggestions,
        private readonly ClockInterface $clock,
    ) {
    }

    /** Предпросмотр: ничего не пишет. */
    public function preview(Company $company, mixed $payload): CashflowCategoryImportPlan
    {
        return $this->planner->plan($this->categories->findTreeByCompany($company), $payload, $this->plCategoryNames($this->plCategories($company)), [], $this->takenIds($company, $payload));
    }

    /**
     * @param list<string> $newIds ID новых статей из предпросмотра
     *
     * @throws \DomainException файл не прошёл проверку или дерево изменилось после предпросмотра
     */
    public function __invoke(Company $company, mixed $payload, array $newIds, string $expectedFingerprint): CashflowCategoryImportPlan
    {
        // Отказ возвращается из транзакции строкой: до него ничего не записано, а исключение
        // внутри wrapInTransaction() закрыло бы EntityManager.
        try {
            $result = $this->entityManager->wrapInTransaction(function () use ($company, $payload, $newIds, $expectedFingerprint): CashflowCategoryImportPlan|string {
                // Тот же ключ, что у синхронизации эталона группы: обе меняют дерево компании.
                $this->entityManager->getConnection()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:companyId))', ['companyId' => (string) $company->getId()]);
                $existing = $this->categories->lockTreeByCompany($company);
                $plCategories = $this->plCategories($company);

                $plan = $this->planner->plan($existing, $payload, $this->plCategoryNames($plCategories), $newIds, $this->takenIds($company, $payload));
                if ([] !== $plan->errors) {
                    return 'Файл не прошёл проверку: '.implode(' ', $plan->errors);
                }
                if ([] === $plan->items) {
                    return $plan;
                }
                // ID новых статей приходят из формы: занятый чужой статьёй дал бы конфликт первичного ключа.
                $newItemIds = array_column($plan->created(), 'id');
                if ([] !== $newItemIds && false !== $this->entityManager->getConnection()->fetchOne('SELECT 1 FROM cashflow_categories WHERE id IN (?) LIMIT 1', [$newItemIds], [ArrayParameterType::STRING])) {
                    return 'Форма устарела. Загрузите файл заново.';
                }
                if (!hash_equals($plan->fingerprint, $expectedFingerprint)) {
                    return 'Дерево статей изменилось после предпросмотра. Загрузите файл заново и проверьте изменения.';
                }

                $this->apply($company, $plan, $existing, $plCategories);
                // Подсказки ИИ без решения выбраны из старого дерева — часовой запуск спросит заново.
                $this->suggestions->reopenAfterTreeChange((string) $company->getId(), 'category_tree_imported', $this->clock->now());

                return $plan;
            });
        } catch (UniqueConstraintViolationException) {
            // Тот же новый id одновременно создала другая компания (у неё своя блокировка).
            throw new \DomainException('Форма устарела. Загрузите файл заново.');
        }

        if (\is_string($result)) {
            throw new \DomainException($result);
        }

        return $result;
    }

    /**
     * @param list<CashflowCategory> $existing
     * @param array<string, PLCategory> $plCategories
     */
    private function apply(Company $company, CashflowCategoryImportPlan $plan, array $existing, array $plCategories): void
    {
        $byId = [];
        foreach ($existing as $category) {
            $byId[(string) $category->getId()] = $category;
        }

        // Код уникален в компании: меняющиеся коды сначала освобождаются, иначе обмен кодами
        // между двумя статьями упирается в unique посреди flush.
        $released = false;
        foreach ($plan->items as $item) {
            $category = $byId[$item['id']] ?? null;
            if (null !== $category && null !== $category->getCode() && $category->getCode() !== $item['values']['code']) {
                $category->setCode(null);
                $released = true;
            }
        }
        if ($released) {
            $this->entityManager->flush();
        }

        foreach ($plan->items as $item) {
            $values = $item['values'];
            $category = $byId[$item['id']] ??= new CashflowCategory($item['id'], $company);
            $category
                ->setName($values['name'])
                ->setDescription($values['description'])
                ->setStatus(CashflowCategoryStatus::from($values['status']))
                ->setSort($values['sort'])
                ->setParent(null === $values['parentId'] ? null : $byId[$values['parentId']])
                ->setOperationType(null === $values['operationType'] ? null : PaymentPlanType::from($values['operationType']))
                ->setAllowPlDocument($values['allowPlDocument'])
                ->setCode($values['code'])
                ->setFlowKind(CashflowFlowKind::from($values['flowKind']));
            // Только при изменении: сохранённую привязку вне справочника компании загрузка не снимает.
            if ($category->getPlCategory()?->getId() !== $values['plCategoryId']) {
                $category->setPlCategory(null === $values['plCategoryId'] ? null : $plCategories[$values['plCategoryId']]);
            }
            $this->entityManager->persist($category);
        }

        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function takenIds(Company $company, mixed $payload): array
    {
        return $this->categories->findIdsOutsideCompany((string) $company->getId(), CashflowCategoryImportPlanner::fileIds($payload));
    }

    /** @return array<string, PLCategory> */
    private function plCategories(Company $company): array
    {
        $byId = [];
        foreach ($this->plCategoryFacade->findTreeEntitiesByCompanyId((string) $company->getId()) as $plCategory) {
            $byId[(string) $plCategory->getId()] = $plCategory;
        }

        return $byId;
    }

    /**
     * @param array<string, PLCategory> $plCategories
     *
     * @return array<string, string>
     */
    private function plCategoryNames(array $plCategories): array
    {
        return array_map(static fn (PLCategory $plCategory): string => $plCategory->getName(), $plCategories);
    }
}
