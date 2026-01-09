<?php

namespace App\Cash\Service\PaymentPlan;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Entity\Company;
use App\Entity\PaymentPlan;
use App\Enum\PaymentPlanStatus as PaymentPlanStatusEnum;
use App\Enum\PaymentPlanType as PaymentPlanTypeEnum;
use App\Util\StringNormalizer;

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
                return PaymentPlanTypeEnum::TRANSFER->value;
            }
        }

        foreach ($names as $name) {
            if ($this->containsAny($name, self::INFLOW_KEYWORDS)) {
                return PaymentPlanTypeEnum::INFLOW->value;
            }
        }

        return PaymentPlanTypeEnum::OUTFLOW->value;
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
                    PaymentPlanTypeEnum::INFLOW => PaymentPlanTypeEnum::INFLOW->value,
                    PaymentPlanTypeEnum::OUTFLOW => PaymentPlanTypeEnum::OUTFLOW->value,
                    PaymentPlanTypeEnum::TRANSFER => PaymentPlanTypeEnum::TRANSFER->value,
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
        $knownStatuses = array_map(
            static fn (PaymentPlanStatusEnum $status): string => $status->value,
            PaymentPlanStatusEnum::cases(),
        );

        if (!\in_array($to, $knownStatuses, true)) {
            throw new \DomainException(sprintf('Unknown payment plan status "%s".', $to));
        }

        $currentEnum = $plan->getStatus();
        $current = $currentEnum->value;

        if ($current === $to) {
            return;
        }

        if (PaymentPlanStatusEnum::PAID === $currentEnum) {
            throw new \DomainException(sprintf('Cannot transition payment plan from terminal status "%s".', $current));
        }

        $allowed = [
            PaymentPlanStatusEnum::DRAFT->value => [PaymentPlanStatusEnum::PLANNED->value],
            PaymentPlanStatusEnum::PLANNED->value => [
                PaymentPlanStatusEnum::APPROVED->value,
                PaymentPlanStatusEnum::PAID->value,
                PaymentPlanStatusEnum::CANCELED->value,
            ],
            PaymentPlanStatusEnum::APPROVED->value => [
                PaymentPlanStatusEnum::PAID->value,
                PaymentPlanStatusEnum::CANCELED->value,
            ],
        ];

        if (!\in_array($to, $allowed[$current] ?? [], true)) {
            throw new \DomainException(sprintf('Cannot transition payment plan status from "%s" to "%s".', $current, $to));
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
            throw new \DomainException('Cannot operate on a payment plan that belongs to a different company.');
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
