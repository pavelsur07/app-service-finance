<?php

namespace App\Service\AutoCategory;

use App\Entity\CashflowCategory;
use App\Entity\Company;
use App\Enum\AutoTemplateDirection;
use App\Enum\MatchLogic;
use App\Repository\AutoCategoryTemplateRepository;
use Psr\Log\LoggerInterface;

class AutoCategorizer implements AutoCategorizerInterface
{
    public function __construct(
        private AutoCategoryTemplateRepository $templateRepository,
        private ConditionEvaluatorInterface $evaluator,
        private LoggerInterface $logger
    ) {
    }

    public function resolveCashflowCategory(Company $company, array $operation, AutoTemplateDirection $direction): ?CashflowCategory
    {
        $templates = $this->templateRepository->findActiveForCashflowByDirection($company, $direction);
        $finalCategory = null;
        $matchedTemplateId = null;
        $matchedConditions = [];

        foreach ($templates as $template) {
            $conditionResults = [];
            foreach ($template->getConditions() as $condition) {
                $res = $this->evaluator->isConditionMatched($operation, $condition);
                $conditionResults[] = [
                    'field' => $condition->getField()->value,
                    'operator' => $condition->getOperator()->value,
                    'result' => $res,
                ];
            }
            $matches = false;
            if ($template->getMatchLogic() === MatchLogic::ALL) {
                $matches = !in_array(false, array_column($conditionResults, 'result'), true);
            } else {
                $matches = in_array(true, array_column($conditionResults, 'result'), true);
            }
            if ($matches) {
                $finalCategory = $template->getTargetCategory();
                $matchedTemplateId = $template->getId();
                $matchedConditions = $conditionResults;
                if ($template->getStopOnMatch()) {
                    break;
                }
            }
        }

        $this->logger->info('auto_category', [
            'operationRef' => $operation['doc_number'] ?? null,
            'matched_template_id' => $matchedTemplateId,
            'conditions' => $matchedConditions,
            'final_category_id' => $finalCategory?->getId(),
        ]);

        return $finalCategory;
    }
}
