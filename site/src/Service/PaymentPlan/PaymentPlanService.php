<?php

namespace App\Service\PaymentPlan;

use App\Domain\PaymentPlan\PaymentPlanStatus;
use App\Domain\PaymentPlan\PaymentPlanType;
use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Entity\PaymentPlan;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Enum\PaymentPlanType as PaymentPlanTypeEnum;
use App\Util\StringNormalizer;
use DomainException;

final class PaymentPlanService
{
    /** @var list<string> */
    private const TRANSFER_KEYWORDS = [
        'техническ',
        'перемещ',
        'перевод',
        'самоинкас',
        'валют',
        'курсов',
    ];

    /** @var list<string> */
    private const INFLOW_KEYWORDS = [
        'доход',
        'поступл',
        'выруч',
        'продаж',
        'инвест',
        'взнос',
        'привлечен',
        'возврат инвест',
    ];

    /**
     * Определяет тип операции по явному признаку CashflowCategory.
     * Поднимаемся по иерархии категорий, пока не встретим заданный тип.
     * Если признак не найден ни у одного предка, откатываемся к эвристике по ключевым словам.
     */
    public function resolveTypeByCategory(CashflowCategory $category): string
    {
        if (null !== ($resolved = $this->resolveTypeFromHierarchy($category))) {
            return $resolved;
        }

        $names = $this->collectCategoryNames($category);

        foreach ($names as $name) {
            if ($this->containsAny($name, self::TRANSFER_KEYWORDS)) {
                return PaymentPlanType::TRANSFER;
            }
        }

        foreach ($names as $name) {
            if ($this->containsAny($name, self::INFLOW_KEYWORDS)) {
                return PaymentPlanType::INFLOW;
            }
        }

        return PaymentPlanType::OUTFLOW;
    }

    /*
     * CashflowCategory::getOperationType() хранит enum App\Enum\PaymentPlanType (INFLOW/OUTFLOW/TRANSFER).
     * Значение может быть null, поэтому поднимаемся к родителю до первого установленного признака.
     */
    private function resolveTypeFromHierarchy(CashflowCategory $category): ?string
    {
        $node = $category;

        while (null !== $node) {
            // читаем признак из CashflowCategory::getOperationType()
            $operationType = $node->getOperationType();
            if (null !== $operationType) {
                return match ($operationType) {
                    PaymentPlanTypeEnum::INFLOW => PaymentPlanType::INFLOW,
                    PaymentPlanTypeEnum::OUTFLOW => PaymentPlanType::OUTFLOW,
                    PaymentPlanTypeEnum::TRANSFER => PaymentPlanType::TRANSFER,
                };
            }

            $node = $node->getParent();
        }

        return null;
    }

    /**
     * Меняет статус плана с валидацией допустимых переходов.
     */
    public function transitionStatus(PaymentPlan $plan, string $to): void
    {
        if (!\in_array($to, PaymentPlanStatus::all(), true)) {
            throw new DomainException(sprintf('Unknown payment plan status "%s".', $to));
        }

        $currentEnum = $plan->getStatus();
        $current = $currentEnum->value;

        if ($current === $to) {
            return;
        }

        if (PaymentPlanStatus::isTerminal($current)) {
            throw new DomainException(sprintf('Cannot transition payment plan from terminal status "%s".', $current));
        }

        $allowed = [
            PaymentPlanStatus::DRAFT => [PaymentPlanStatus::PLANNED],
            PaymentPlanStatus::PLANNED => [PaymentPlanStatus::APPROVED, PaymentPlanStatus::CANCELED],
            PaymentPlanStatus::APPROVED => [PaymentPlanStatus::PAID, PaymentPlanStatus::CANCELED],
        ];

        if (!\in_array($to, $allowed[$current] ?? [], true)) {
            throw new DomainException(sprintf('Cannot transition payment plan status from "%s" to "%s".', $current, $to));
        }

        $plan->setStatus(PaymentPlanStatusEnum::from($to));
    }

    /**
     * Применяет company-scope: устанавливает компанию, если пусто; иначе проверяет совпадение.
     */
    public function applyCompanyScope(PaymentPlan $plan, Company $company): void
    {
        $current = $this->extractCompany($plan);

        if (null === $current) {
            $plan->setCompany($company);

            return;
        }

        if ($current === $company) {
            return;
        }

        if ($current->getId() !== $company->getId()) {
            throw new DomainException('Cannot operate on a payment plan that belongs to a different company.');
        }
    }

    /**
     * @return list<string>
     */
    private function collectCategoryNames(CashflowCategory $category): array
    {
        $names = [];
        $node = $category;

        while (null !== $node) {
            $names[] = (string) $node->getName();
            $node = $node->getParent();
        }

        return $names;
    }

    /**
     * @param list<string> $keywords
     */
    private function containsAny(string $value, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (StringNormalizer::contains($value, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function extractCompany(PaymentPlan $plan): ?Company
    {
        /** @var Company|null $company */
        $company = \Closure::bind(static function (PaymentPlan $plan): ?Company {
            return $plan->company ?? null;
        }, null, PaymentPlan::class)($plan);

        return $company;
    }
}
