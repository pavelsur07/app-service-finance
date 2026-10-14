<?php

declare(strict_types=1);

namespace App\Company\Domain\Service;

use App\Company\Entity\Counterparty;
use App\Company\Enum\CounterpartyDisplayNameSource;

/**
 * Предложение привести название к единому виду — единственная точка условий показа подсказки
 * в списке и проверки при «Применить» (docs/plan/ds-counterparties.md, «Подсказка нормализации»).
 *
 * Предложение есть, только если правила уверены (не null), результат отличается от того, что
 * сейчас показывается (displayName ?? name), название не задано вручную и контрагент не в архиве.
 * Правил здесь нет — только CounterpartyDisplayNameFormatter.
 */
final readonly class CounterpartyDisplayNameSuggestion
{
    private const MAX_LENGTH = 255;

    public function __construct(private CounterpartyDisplayNameFormatter $formatter)
    {
    }

    public function for(Counterparty $counterparty): ?string
    {
        if ($counterparty->isArchived() || CounterpartyDisplayNameSource::MANUAL === $counterparty->getDisplayNameSource()) {
            return null;
        }

        $suggested = $this->formatter->format($counterparty->getName());
        if (null === $suggested || mb_strlen($suggested) > self::MAX_LENGTH) {
            return null;
        }

        return $suggested !== ($counterparty->getDisplayName() ?? $counterparty->getName()) ? $suggested : null;
    }
}
