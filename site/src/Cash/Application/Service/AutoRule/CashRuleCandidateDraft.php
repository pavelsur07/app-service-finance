<?php

declare(strict_types=1);

namespace App\Cash\Application\Service\AutoRule;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField as Field;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator as Operator;
use App\Cash\Infrastructure\Query\CashRulePhraseQuery;
use App\Cash\Infrastructure\Query\CashTransactionAutoRuleCandidateQuery;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Company\Facade\CompanyFacade;
use Ramsey\Uuid\Uuid;

final readonly class CashRuleCandidateDraft
{
    public function __construct(
        private CashTransactionAutoRuleCandidateQuery $history,
        private CashRulePhraseQuery $phrases,
        private MoneyAccountRepository $accounts,
        private CompanyFacade $companies,
    ) {
    }

    /** @param list<CashflowCategory> $categories */
    public function fill(CashTransactionAutoRule $rule, string $key, array $categories): bool
    {
        $companyId = (string) $rule->getCompany()->getId();
        $candidate = null;
        if (Uuid::isValid($key)) {
            $candidate = $this->phrases->review($companyId, $key)?->candidate;
        } else {
            $to = new \DateTimeImmutable('today');
            $from = $to->modify('-'.CashTransactionAutoRuleCandidateQuery::PERIOD_DAYS.' days');
            foreach ($this->history->findForCompany($companyId, $from, $to) as $item) {
                if ($item->key() === $key) {
                    $candidate = $item;
                    break;
                }
            }
        }
        if (null === $candidate) {
            return false;
        }
        foreach ($categories as $category) {
            if ($category->getId() === $candidate->categoryId) {
                $rule->setCashflowCategory($category);
                $rule->setName(mb_substr($candidate->valueLabel.' → '.$category->getName(), 0, 255));
                $rule->setOperationType($candidate->operationType);
                $counterparty = Field::COUNTERPARTY === $candidate->field ? $this->companies->findCounterpartyByIdAndCompany($candidate->value, $companyId) : null;
                $account = Field::MONEY_ACCOUNT === $candidate->field ? $this->accounts->findOneByIdAndCompanyId($candidate->value, $companyId) : null;
                if ((Field::COUNTERPARTY === $candidate->field && null === $counterparty) || (Field::MONEY_ACCOUNT === $candidate->field && null === $account)) {
                    return false;
                }
                $rule->addCondition(new CashTransactionAutoRuleCondition(
                    field: $candidate->field, operator: Operator::EQUAL,
                    value: null === $counterparty && null === $account ? $candidate->value : null,
                    counterparty: $counterparty, moneyAccount: $account,
                ));
                if (null !== $candidate->phrase) {
                    $rule->addCondition(new CashTransactionAutoRuleCondition(field: Field::DESCRIPTION, operator: Operator::CONTAINS, value: $candidate->phrase));
                }

                return true;
            }
        }

        return false;
    }
}
