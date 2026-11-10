<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Application\Service\CashTransactionAutoRuleTargetValidator;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;

final readonly class CashAutoRulePage
{
    /** @return array<string, string> */
    public function getResponsibilityCenterChoices(
        FinancialResponsibilityCenterFacade $facade,
        string $companyId,
        ?string $currentId = null,
    ): array {
        $centers = $facade->getActiveChoices($companyId);
        $activeIds = array_map(
            static fn (FinancialResponsibilityCenterDTO $center): string => $center->id,
            $centers,
        );
        if (null !== $currentId && !in_array($currentId, $activeIds, true)) {
            $current = $facade->findByIdAndCompany($currentId, $companyId);
            if (null !== $current) {
                $centers[] = $current;
            }
        }

        $choices = [];
        foreach ($centers as $center) {
            $choices[sprintf(
                '%s [%s]%s',
                $center->name,
                $center->code,
                $center->isActive() ? '' : ' — архив, только сохранение',
            )] = $center->id;
        }

        return $choices;
    }

    /** @param FormInterface<CashTransactionAutoRule> $form */
    public function validatePairTarget(
        FormInterface $form,
        CashTransactionAutoRuleTargetValidator $validator,
        string $companyId,
        ?string $currentProjectDirectionId,
        ?string $currentResponsibilityCenterId,
        CashTransactionAutoRule $rule,
    ): void {
        if (!$form->isSubmitted() || !$form->isSynchronized()) {
            return;
        }

        try {
            $validator->assertValidChange(
                $companyId,
                $currentProjectDirectionId,
                $currentResponsibilityCenterId,
                $rule->getProjectDirection()?->getId(),
                $rule->getResponsibilityCenterId(),
            );
        } catch (\DomainException $exception) {
            $form->get('responsibilityCenterId')->addError(new FormError($exception->getMessage()));
        }
    }
}
