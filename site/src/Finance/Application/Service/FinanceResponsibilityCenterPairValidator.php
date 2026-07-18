<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Company\Facade\FinancialResponsibilityCenterFacade;
use Ramsey\Uuid\Uuid;

final readonly class FinanceResponsibilityCenterPairValidator
{
    private const INCOMPLETE_PAIR_MESSAGE = 'Укажите проект и ЦФО.';
    private const UNAVAILABLE_PAIR_MESSAGE = 'Выбранная пара проекта и ЦФО недоступна.';

    public function __construct(
        private FinancialResponsibilityCenterFacade $responsibilityCenterFacade,
    ) {
    }

    public function assertValidNullablePair(
        string $companyId,
        ?string $projectDirectionId,
        ?string $responsibilityCenterId,
    ): void {
        if (!Uuid::isValid($companyId)) {
            throw new \DomainException('Компания не найдена.');
        }

        if (null === $responsibilityCenterId) {
            return;
        }

        if (null === $projectDirectionId) {
            throw new \DomainException(self::INCOMPLETE_PAIR_MESSAGE);
        }

        if (!Uuid::isValid($projectDirectionId) || !Uuid::isValid($responsibilityCenterId)) {
            throw new \DomainException(self::UNAVAILABLE_PAIR_MESSAGE);
        }

        $center = $this->responsibilityCenterFacade->findByIdAndCompany($responsibilityCenterId, $companyId);
        if (null === $center || !$center->isActive()
            || !$this->responsibilityCenterFacade->isProjectAllowed($companyId, $projectDirectionId, $responsibilityCenterId)) {
            throw new \DomainException(self::UNAVAILABLE_PAIR_MESSAGE);
        }
    }
}
