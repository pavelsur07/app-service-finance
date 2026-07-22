<?php

declare(strict_types=1);

namespace App\Cash\Facade;

use App\Cash\Application\DTO\AutoRuleConditionInput;
use App\Cash\Application\DTO\AutoRuleInput;
use App\Cash\Application\DTO\CashflowCategoryInput;
use App\Cash\Application\DTO\CreateCashTransactionCommand;
use App\Cash\Application\DTO\CreateCashTransactionResult;
use App\Cash\Application\SaveCashflowCategoryAction;
use App\Cash\Application\SaveCashTransactionAutoRuleAction;
use App\Cash\DTO\CashTransactionDTO;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Cash\Repository\Transaction\CashTransactionAutoRuleRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Cash\Service\Transaction\CashTransactionService;
use App\Company\Entity\Company;
use App\Company\Entity\Counterparty;
use App\Company\Facade\CompanyFacade;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;

final readonly class CashFacade
{
    public const MAX_PER_PAGE = 200;

    public function __construct(
        private CashTransactionService $cashTransactionService,
        private CashTransactionRepository $cashTransactionRepository,
        private CashflowCategoryRepository $cashflowCategoryRepository,
        private CashTransactionAutoRuleRepository $autoRuleRepository,
        private MoneyAccountRepository $moneyAccountRepository,
        private CompanyFacade $companyFacade,
        private SaveCashflowCategoryAction $saveCategory,
        private SaveCashTransactionAutoRuleAction $saveAutoRule,
    ) {
    }

    /**
     * @param array{
     *     dateFrom?: ?string, dateTo?: ?string, accountId?: ?string, categoryId?: ?string,
     *     counterpartyId?: ?string, direction?: ?string, amountMin?: ?string, amountMax?: ?string, q?: ?string
     * } $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pages: int, per_page: int}
     */
    public function listTransactions(string $companyId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $company = $this->requireCompany($companyId);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $pager = $this->cashTransactionRepository->paginateByCompanyWithFilters(
            $company,
            $filters + [
                'dateFrom' => null, 'dateTo' => null, 'accountId' => null, 'categoryId' => null,
                'counterpartyId' => null, 'direction' => null, 'amountMin' => null, 'amountMax' => null, 'q' => null,
            ],
            max(1, $page),
            $perPage,
        );

        $items = [];
        foreach ($pager->getCurrentPageResults() as $transaction) {
            $items[] = $this->serializeTransaction($transaction);
        }

        return [
            'items' => $items,
            'total' => $pager->getNbResults(),
            'page' => $pager->getCurrentPage(),
            'pages' => $pager->getNbPages(),
            'per_page' => $pager->getMaxPerPage(),
        ];
    }

    /**
     * Плоское дерево статей ДДС компании, отсортированное как в интерфейсе.
     *
     * @return list<array<string, mixed>>
     */
    public function listCashflowCategories(string $companyId): array
    {
        $company = $this->requireCompany($companyId);

        return array_map(
            static fn (CashflowCategory $category): array => [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'level' => $category->getLevel(),
                'parentId' => $category->getParent()?->getId(),
                'status' => $category->getStatus()->value,
                'flowKind' => $category->getFlowKind()->value,
                'sort' => $category->getSort(),
                'isSystem' => $category->isSystem(),
            ],
            $this->cashflowCategoryRepository->findTreeByCompany($company),
        );
    }

    /**
     * @return string id созданной или изменённой статьи
     */
    public function upsertCashflowCategory(string $companyId, CashflowCategoryInput $input): string
    {
        $company = $this->requireCompany($companyId);

        if (null === $input->id) {
            if (null === $input->name || '' === trim($input->name)) {
                throw new \DomainException('Для новой статьи укажите name.');
            }

            $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        } else {
            $category = $this->requireCategory($input->id, $company);
        }

        if (null !== $input->name) {
            $category->setName($input->name);
        }
        if (null !== $input->parentId) {
            $category->setParent($this->requireCategory($input->parentId, $company));
        }
        if (null !== $input->description) {
            $category->setDescription($input->description);
        }
        if (null !== $input->status) {
            $category->setStatus($input->status);
        }
        if (null !== $input->sort) {
            $category->setSort($input->sort);
        }
        if (null !== $input->flowKind) {
            $category->setFlowKind($input->flowKind);
        }

        ($this->saveCategory)($category);

        return (string) $category->getId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAutoRules(string $companyId): array
    {
        $company = $this->requireCompany($companyId);

        return array_map(
            static fn (CashTransactionAutoRule $rule): array => [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
                'action' => $rule->getAction()->value,
                'operationType' => $rule->getOperationType()->value,
                'priority' => $rule->getPriority(),
                'isActive' => $rule->isActive(),
                'category' => null === $rule->getCashflowCategory() ? null : [
                    'id' => $rule->getCashflowCategory()->getId(),
                    'name' => $rule->getCashflowCategory()->getName(),
                ],
                'counterpartyId' => $rule->getCounterparty()?->getId(),
                'conditions' => array_map(
                    static fn (CashTransactionAutoRuleCondition $condition): array => [
                        'field' => $condition->getField()->value,
                        'operator' => $condition->getOperator()->value,
                        'value' => $condition->getValue(),
                        'valueTo' => $condition->getValueTo(),
                        'counterpartyId' => $condition->getCounterparty()?->getId(),
                        'moneyAccountId' => $condition->getMoneyAccount()?->getId(),
                    ],
                    $rule->getConditions()->toArray(),
                ),
            ],
            $this->autoRuleRepository->findByCompany($company, null, null, null),
        );
    }

    /**
     * @return string id созданного или изменённого автоправила
     */
    public function upsertAutoRule(string $companyId, AutoRuleInput $input, ?string $actorUserId = null): string
    {
        $company = $this->requireCompany($companyId);

        if (null === $input->id) {
            if (null === $input->name || '' === trim($input->name)) {
                throw new \DomainException('Для нового автоправила укажите name.');
            }
            if (null === $input->cashflowCategoryId) {
                throw new \DomainException('Для нового автоправила укажите cashflowCategoryId.');
            }
            if (null === $input->conditions || [] === $input->conditions) {
                throw new \DomainException('Для нового автоправила укажите хотя бы одно условие.');
            }

            $rule = new CashTransactionAutoRule(
                Uuid::uuid4()->toString(),
                $company,
                $input->name,
                $input->action ?? CashTransactionAutoRuleAction::FILL,
                $input->operationType ?? CashTransactionAutoRuleOperationType::ANY,
                createdByUserId: $actorUserId,
            );
        } else {
            $rule = $this->autoRuleRepository->findOneByIdAndCompanyId($input->id, (string) $company->getId());
            if (null === $rule) {
                throw new \DomainException(sprintf('Автоправило %s не найдено.', $input->id));
            }

            if (null !== $input->name) {
                $rule->setName($input->name);
            }
            if (null !== $input->action) {
                $rule->setAction($input->action);
            }
            if (null !== $input->operationType) {
                $rule->setOperationType($input->operationType);
            }
        }

        if (null !== $input->cashflowCategoryId) {
            $rule->setCashflowCategory($this->requireCategory($input->cashflowCategoryId, $company));
        }
        if (null !== $input->counterpartyId) {
            $rule->setCounterparty($this->requireCounterparty($input->counterpartyId, $company));
        }
        if (null !== $input->priority) {
            $rule->setPriority($input->priority);
        }
        if (null !== $input->isActive) {
            $rule->setIsActive($input->isActive);
        }
        if (null !== $input->conditions) {
            $this->replaceConditions($rule, $input->conditions, $company);
        }

        ($this->saveAutoRule)(
            $rule,
            $actorUserId,
            $rule->getProjectDirection()?->getId(),
            $rule->getResponsibilityCenterId(),
        );

        return (string) $rule->getId();
    }

    /**
     * @param list<AutoRuleConditionInput> $conditions
     */
    private function replaceConditions(CashTransactionAutoRule $rule, array $conditions, Company $company): void
    {
        foreach ($rule->getConditions()->toArray() as $existing) {
            $rule->removeCondition($existing);
        }

        foreach ($conditions as $input) {
            $counterparty = null;
            if (CashTransactionAutoRuleConditionField::COUNTERPARTY === $input->field) {
                if (null === $input->counterpartyId) {
                    throw new \DomainException('Для условия по контрагенту укажите counterpartyId.');
                }
                $counterparty = $this->requireCounterparty($input->counterpartyId, $company);
            }

            $moneyAccount = null;
            if (CashTransactionAutoRuleConditionField::MONEY_ACCOUNT === $input->field) {
                if (null === $input->moneyAccountId) {
                    throw new \DomainException('Для условия по счёту укажите moneyAccountId.');
                }
                $moneyAccount = $this->moneyAccountRepository->findOneBy([
                    'id' => $input->moneyAccountId,
                    'company' => $company,
                ]);
                if (null === $moneyAccount) {
                    throw new \DomainException(sprintf('Денежный счёт %s не найден.', $input->moneyAccountId));
                }
            }

            $rule->addCondition(new CashTransactionAutoRuleCondition(
                Uuid::uuid4()->toString(),
                $rule,
                $input->field,
                $input->operator,
                $input->value,
                $input->valueTo,
                $counterparty,
                $moneyAccount,
            ));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTransaction(CashTransaction $transaction): array
    {
        return [
            'id' => $transaction->getId(),
            'occurredAt' => $transaction->getOccurredAt()->format('Y-m-d'),
            'direction' => $transaction->getDirection()->value,
            'amount' => $transaction->getAmount(),
            'currency' => $transaction->getCurrency(),
            'description' => $transaction->getDescription(),
            'isTransfer' => $transaction->isTransfer(),
            'account' => [
                'id' => $transaction->getMoneyAccount()->getId(),
                'name' => $transaction->getMoneyAccount()->getName(),
            ],
            'counterparty' => null === $transaction->getCounterparty() ? null : [
                'id' => $transaction->getCounterparty()->getId(),
                'name' => $transaction->getCounterparty()->getName(),
            ],
            'category' => null === $transaction->getCashflowCategory() ? null : [
                'id' => $transaction->getCashflowCategory()->getId(),
                'name' => $transaction->getCashflowCategory()->getName(),
            ],
        ];
    }

    private function requireCompany(string $companyId): Company
    {
        $company = $this->companyFacade->findById($companyId);
        if (null === $company) {
            throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));
        }

        return $company;
    }

    private function requireCategory(string $categoryId, Company $company): CashflowCategory
    {
        $category = $this->cashflowCategoryRepository->findOneBy(['id' => $categoryId, 'company' => $company]);
        if (null === $category) {
            throw new \DomainException(sprintf('Статья ДДС %s не найдена.', $categoryId));
        }

        return $category;
    }

    private function requireCounterparty(string $counterpartyId, Company $company): Counterparty
    {
        $counterparty = $this->companyFacade->findCounterpartyByIdAndCompany(
            $counterpartyId,
            (string) $company->getId(),
        );
        if (null === $counterparty) {
            throw new \DomainException(sprintf('Контрагент %s не найден.', $counterpartyId));
        }

        return $counterparty;
    }

    public function createTransaction(CreateCashTransactionCommand $command): CreateCashTransactionResult
    {
        $dto = new CashTransactionDTO();
        $dto->companyId = $command->companyId;
        $dto->moneyAccountId = $command->moneyAccountId;
        $dto->direction = $command->direction;
        $dto->amount = $command->amount;
        $dto->currency = $command->currency;
        $dto->occurredAt = $command->occurredAt;
        $dto->description = $command->description;
        $dto->counterpartyId = $command->counterpartyId;
        $dto->cashflowCategoryId = $command->cashflowCategoryId;
        $dto->projectDirectionId = $command->projectDirectionId;
        $dto->importSource = $command->importSource;
        $dto->externalId = $command->externalId;
        $dto->dedupeHash = $command->dedupeHash;
        $dto->rawData = $command->rawData;
        $dto->responsibilityCenterId = $command->responsibilityCenterId;

        $existing = $this->findExistingByImport($command);
        if (null !== $existing) {
            return new CreateCashTransactionResult((string) $existing->getId(), false, true);
        }

        try {
            $transaction = $this->cashTransactionService->add($dto);

            return new CreateCashTransactionResult((string) $transaction->getId(), true, false);
        } catch (UniqueConstraintViolationException $e) {
            if (null === $command->importSource || null === $command->externalId) {
                throw $e;
            }

            $existingId = $this->cashTransactionRepository->findAnyIdByCompanyImportSourceExternalIdDbal(
                $command->companyId,
                $command->importSource,
                $command->externalId,
            );

            if (null !== $existingId) {
                return new CreateCashTransactionResult($existingId, false, true);
            }

            throw $e;
        }
    }

    private function findExistingByImport(CreateCashTransactionCommand $command): ?CashTransaction
    {
        if (null === $command->importSource || null === $command->externalId) {
            return null;
        }

        return $this->cashTransactionRepository->findAnyByCompanyImportSourceExternalId(
            $command->companyId,
            $command->importSource,
            $command->externalId,
        );
    }
}
