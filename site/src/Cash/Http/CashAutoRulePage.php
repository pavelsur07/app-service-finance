<?php

declare(strict_types=1);

namespace App\Cash\Http;

use App\Cash\Application\AutoRule\SaveCashTransactionAutoRuleAction;
use App\Cash\Application\DTO\CashTransactionAutoRulePreviewResult;
use App\Cash\Application\Service\AutoRule\CashAutoRuleConfirmation;
use App\Cash\Application\Service\AutoRule\CashAutoRulePreviewBuilder;
use App\Cash\Application\Service\AutoRule\CashTransactionAutoRuleTargetValidator;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Company\Application\DTO\FinancialResponsibilityCenterDTO;
use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class CashAutoRulePage
{
    public function __construct(
        private CashAutoRulePreviewBuilder $previewBuilder,
        private CashTransactionRepository $transactions,
        private CashAutoRuleConfirmation $confirmation,
        private SaveCashTransactionAutoRuleAction $save,
    ) {
    }

    /**
     * @param FormInterface<CashTransactionAutoRule> $form
     *
     * @return array{saved: bool, preview: ?CashTransactionAutoRulePreviewResult, confirmation: ?string}
     */
    public function processForm(
        FormInterface $form,
        Request $request,
        CashTransactionAutoRule $draft,
        ?CashTransactionAutoRule $original,
        ?string $actorId,
    ): array {
        $result = ['saved' => false, 'preview' => null, 'confirmation' => null];
        if (!$form->isSubmitted() || !$form->isValid()) {
            return $result;
        }
        if (null === $actorId) {
            throw new AccessDeniedException();
        }
        if ('confirm' === $request->request->get('intent')) {
            try {
                ($this->save)($draft, $original, $actorId, $request->request->getString('confirmation'));
                $result['saved'] = true;
            } catch (\DomainException $exception) {
                $form->addError(new FormError($exception->getMessage()));
            }

            return $result;
        }

        $today = new \DateTimeImmutable('today');
        $transactions = $this->transactions->iterateForAutoRulePreview($draft->getCompany(), $today->modify('-6 months'), $today);
        $preview = $this->previewBuilder->forRule($draft, $transactions, 200);

        return [
            'saved' => false,
            'preview' => $preview,
            'confirmation' => $this->confirmation->issue($draft, $actorId, $original?->getRevision() ?? 0, $preview->activeVersions),
        ];
    }

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
